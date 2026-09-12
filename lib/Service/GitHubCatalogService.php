<?php

/**
 * Buildiq GitHubCatalogService
 *
 * Server-side GitHub source for the shop (github-shop-catalogue). Searches GitHub
 * for Buildiq apps by the `topic:buildiq-app` discovery topic, fetches each
 * hit's root `openbuild-app.json` descriptor for a card, and — on install —
 * fetches the whole repo file map for github-app-repo-format's AppRepoParser.
 *
 * SSRF-safe by construction (design.md Decision 2/9): every outbound host is the
 * compile-time constant `api.github.com` — there is NO admin-configurable URL —
 * and `owner`/`repo`/`ref` are pattern-validated before path interpolation.
 * Browsing is anonymous-first (usable with no credential); when the acting user
 * supplies an allowed broker `github` credential the call is transparently
 * upgraded through OpenRegister's CredentialBrokerService so the token stays
 * broker-side and NEVER enters Buildiq. The broker is resolved lazily
 * (`class_exists` + the injected container, mirroring RemoteTemplateStoreService)
 * so a missing/older OpenRegister falls back to anonymous cleanly. Results + descriptors
 * are cached short-TTL against the tight anonymous rate limit; the raw GitHub body
 * and any token are never returned or logged.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fixed-host, SSRF-safe GitHub catalogue source with optional broker upgrade.
 *
 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
 */
class GitHubCatalogService {
	/**
	 * The fixed GitHub API host — compile-time constant, no SSRF surface.
	 */
	private const API_BASE = 'https://api.github.com';

	/**
	 * The discovery topics a conforming app repo may carry, canonical first.
	 *
	 * A GitHub topic lives on repositories we do not own, so it only moves when
	 * THOSE repos re-tag. The app-id rename (openbuild -> buildiq, #334) moved
	 * this constant to `buildiq-app` while every published app repo still
	 * carried `openbuild-app`, so the store searched for a topic nothing
	 * answered to, got a legitimately empty result set, and rendered "no apps
	 * match your search". Measured 2026-08-24: `topic:buildiq-app` matched 0
	 * repositories, `topic:openbuild-app` matched 5.
	 *
	 * Both are accepted while the rename is in flight. Drop the legacy entry
	 * once every published app repo carries the canonical topic; until then,
	 * removing it empties the store.
	 */
	private const DISCOVERY_TOPICS = [
		'topic:buildiq-app',
		'topic:openbuild-app',
	];

	/**
	 * The credential-broker service FQCN (resolved lazily; may be absent).
	 */
	private const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * The broker `appId` Buildiq identifies itself with.
	 */
	private const APP_ID = 'buildiq';

	/**
	 * Cache namespace for search results + descriptors.
	 */
	private const CACHE_NS = 'buildiq_github_catalog';

	/**
	 * Search-result cache TTL (seconds).
	 */
	private const SEARCH_TTL = 60;

	/**
	 * Descriptor cache TTL (seconds).
	 */
	private const DESCRIPTOR_TTL = 300;

	/**
	 * Connect + request timeout (seconds) for every anonymous call.
	 */
	private const TIMEOUT = 10;

	/**
	 * Maximum number of search hits turned into cards (per-hit descriptor cost).
	 */
	private const MAX_HITS = 30;

	/**
	 * Maximum app-repo-format-v2 channel blobs fetched for one install.
	 *
	 * Sized above a real v2 artefact — buildiq-hydra carries 94 skills across
	 * ~750 blobs — while still bounding a hostile repository's ability to turn
	 * one install into an unbounded fan-out of contents-API calls. Truncation is
	 * logged, never silent.
	 *
	 * @var int
	 */
	private const MAX_CHANNEL_FILES = 2048;

	/**
	 * Safe owner/repo pattern (GitHub allows alnum, `-`, `_`, `.`).
	 */
	private const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

	/**
	 * Safe ref pattern (branch/tag/sha; no path traversal / spaces).
	 */
	private const REF_PATTERN = '/^[A-Za-z0-9._\/-]{1,255}$/';

	/**
	 * Outcome: the request succeeded.
	 */
	public const OUTCOME_OK = 'ok';

	/**
	 * Outcome: GitHub rate-limited and no cached result was available.
	 */
	public const OUTCOME_RATE_LIMITED = 'github_rate_limited';

	/**
	 * Outcome: transport failure / non-2xx that is not a rate limit.
	 */
	public const OUTCOME_UNREACHABLE = 'github_unreachable';

	/**
	 * The distributed cache, or null when no cache backend is available.
	 *
	 * @var ICache|null
	 */
	private readonly ?ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService NC HTTP client factory (anonymous calls).
	 * @param ICacheFactory $cacheFactory NC cache factory (short-TTL server cache).
	 * @param LoggerInterface $logger PSR logger (secret-free diagnostics only).
	 * @param ContainerInterface $container DI container, resolves the OpenRegister
	 *                                      broker at call time (never the global server).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clientService,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
		$cache = null;
		if ($cacheFactory->isAvailable() === true) {
			$cache = $cacheFactory->createDistributed(self::CACHE_NS);
		}

		$this->cache = $cache;
	}//end __construct()

	/**
	 * Whether the OpenRegister credential broker is present on this instance.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function isBrokerAvailable(): bool {
		return class_exists(self::BROKER_CLASS) === true;
	}//end isBrokerAvailable()

	/**
	 * Search GitHub for `topic:buildiq-app` repos and build installable cards.
	 *
	 * @param string|null $query Optional free-text term appended to the topic query.
	 * @param string|null $actingUserId The session UID (broker owner-guard identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential to upgrade the call.
	 *
	 * @return array{outcome:string,cards:array<int,array<string,mixed>>,brokerUsed:bool,rateLimited:bool}
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function search(?string $query, ?string $actingUserId, ?string $credentialId = null): array {
		$term = trim((string)$query);
		$normalise = strtolower($term);
		$cacheKey = 'search:' . md5($normalise . '|' . ((string)$credentialId));

		$cached = $this->cacheGet(key: $cacheKey);
		if (is_array($cached) === true) {
			return $cached;
		}

		$hits = $this->collectTopicHits(
			term: $term,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);

		if ($hits['anyOk'] === false) {
			// Every accepted topic failed to answer. Nothing was measured, so
			// this is not an empty catalogue. Returning here also skips the
			// cache write below, so one bad round is not served to every later
			// caller for the whole TTL.
			$failure = self::OUTCOME_UNREACHABLE;
			if ($hits['rateLimited'] === true) {
				$failure = self::OUTCOME_RATE_LIMITED;
			}

			return [
				'outcome' => $failure,
				'cards' => [],
				'brokerUsed' => $hits['brokerUsed'],
				'rateLimited' => $hits['rateLimited'],
			];
		}

		$payload = [
			'outcome' => self::OUTCOME_OK,
			'cards' => $this->buildCards(
				items: $hits['items'],
				actingUserId: $actingUserId,
				credentialId: $credentialId
			),
			'brokerUsed' => $hits['brokerUsed'],
			// At least one topic answered, so the catalogue is real. If another
			// topic was rate-limited the set may be incomplete, and saying so is
			// more honest than reporting a clean result.
			'rateLimited' => $hits['rateLimited'],
		];
		$this->cacheSet(key: $cacheKey, value: $payload, ttl: self::SEARCH_TTL);

		return $payload;
	}//end search()


	/**
	 * Query every accepted discovery topic and merge the hits.
	 *
	 * GitHub's repository search does NOT support OR on qualifiers ("logical
	 * operators only apply to text, not to qualifiers"), so each accepted topic
	 * needs its own request. Results are merged and de-duplicated by full_name
	 * before any card is built.
	 *
	 * @param string      $term         The user's search text, already trimmed.
	 * @param string|null $actingUserId Who is asking, for credential resolution.
	 * @param string|null $credentialId The advisory github credential, if any.
	 *
	 * @return array{items: array<int, array>, anyOk: bool, brokerUsed: bool, rateLimited: bool}
	 */
	private function collectTopicHits(
		string $term,
		?string $actingUserId,
		?string $credentialId
	): array {
		$items = [];
		$seen = [];
		$brokerUsed = false;
		$anyOk = false;
		$rateLimited = false;

		foreach (self::DISCOVERY_TOPICS as $topic) {
			$page = $this->fetchTopic(
				topic: $topic,
				term: $term,
				actingUserId: $actingUserId,
				credentialId: $credentialId
			);

			$brokerUsed = ($brokerUsed || $page['brokerUsed']);
			if ($page['rateLimited'] === true) {
				$rateLimited = true;
			}

			if ($page['ok'] === false) {
				continue;
			}

			$anyOk = true;
			foreach ($page['items'] as $item) {
				$key = strtolower((string)($item['full_name'] ?? ''));
				if ($key === '' || isset($seen[$key]) === true) {
					continue;
				}

				$seen[$key] = true;
				$items[] = $item;
			}
		}

		return [
			'items' => $items,
			'anyOk' => $anyOk,
			'brokerUsed' => $brokerUsed,
			'rateLimited' => $rateLimited,
		];
	}//end collectTopicHits()


	/**
	 * Run one topic's repository search and return its usable items.
	 *
	 * @param string      $topic        The discovery topic to query.
	 * @param string      $term         The user's search text, already trimmed.
	 * @param string|null $actingUserId Who is asking.
	 * @param string|null $credentialId The advisory github credential, if any.
	 *
	 * @return array{ok: bool, items: array<int, array>, brokerUsed: bool, rateLimited: bool}
	 */
	private function fetchTopic(
		string $topic,
		string $term,
		?string $actingUserId,
		?string $credentialId
	): array {
		$queryString = $topic;
		if ($term !== '') {
			$queryString .= ' ' . $term;
		}

		$path = '/search/repositories?q=' . rawurlencode($queryString) . '&per_page=' . self::MAX_HITS;

		$result = $this->get(path: $path, actingUserId: $actingUserId, credentialId: $credentialId);
		$failed = [
			'ok' => false,
			'items' => [],
			'brokerUsed' => $result['brokerUsed'],
			'rateLimited' => $result['rateLimited'],
		];

		if ($result['ok'] === false) {
			return $failed;
		}

		$decoded = json_decode($result['body'], true);
		if (is_array($decoded) === false || is_array($decoded['items'] ?? null) === false) {
			// A 200 whose body is not the documented search shape means GitHub
			// never actually answered this query: a proxy error page, a
			// truncated response, an HTML interstitial. Counting it as an empty
			// success would make the UI say "no apps match your search", telling
			// the user their query found nothing when in fact the lookup failed.
			// A lookup failure must not wear the words of a judgement, so this
			// query counts as failed.
			return $failed;
		}

		// Shape-check here so the caller's merge loop can trust every entry.
		$items = array_values(
			array_filter(
				$decoded['items'],
				static fn ($item): bool => is_array($item)
			)
		);

		return [
			'ok' => true,
			'items' => $items,
			'brokerUsed' => $result['brokerUsed'],
			'rateLimited' => $result['rateLimited'],
		];
	}//end fetchTopic()


	/**
	 * Build the installable cards for the merged hits.
	 *
	 * Every entry was already shape-checked while merging the per-topic
	 * responses, so no is_array() guard is needed here.
	 *
	 * @param array<int, array> $items        The merged, de-duplicated hits.
	 * @param string|null       $actingUserId Who is asking.
	 * @param string|null       $credentialId The advisory github credential, if any.
	 *
	 * @return array<int, array> The cards, skipping any that would not build.
	 */
	private function buildCards(
		array $items,
		?string $actingUserId,
		?string $credentialId
	): array {
		$cards = [];
		foreach (array_slice($items, 0, self::MAX_HITS) as $item) {
			$card = $this->buildCard(item: $item, actingUserId: $actingUserId, credentialId: $credentialId);
			if ($card !== null) {
				$cards[] = $card;
			}
		}

		return $cards;
	}//end buildCards()

	/**
	 * Fetch + decode a repo's root `openbuild-app.json` descriptor.
	 *
	 * @param string $owner Repo owner (pattern-validated).
	 * @param string $repo Repo name (pattern-validated).
	 * @param string|null $ref Optional git ref (pattern-validated).
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array<string,mixed>|null The parsed descriptor, or null when missing/unparseable.
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function fetchDescriptor(
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId = null,
	): ?array {
		if ($this->validRepo(owner: $owner, repo: $repo, ref: $ref) === false) {
			return null;
		}

		$cacheKey = 'descriptor:' . $owner . '/' . $repo . '@' . ((string)$ref) . '|' . ((string)$credentialId);
		$cached = $this->cacheGet(key: $cacheKey);
		if (is_array($cached) === true) {
			return $cached;
		}

		$contents = $this->fetchFileContents(
			owner: $owner,
			repo: $repo,
			path: AppRepoParser::DESCRIPTOR_FILE,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);
		if ($contents === null) {
			return null;
		}

		$decoded = json_decode($contents, true);
		if (is_array($decoded) === false || array_is_list($decoded) === true) {
			return null;
		}

		$this->cacheSet(key: $cacheKey, value: $decoded, ttl: self::DESCRIPTOR_TTL);

		return $decoded;
	}//end fetchDescriptor()

	/**
	 * Resolve the exact commit SHA a ref points at (for pull provenance).
	 *
	 * @param string $owner Repo owner (pattern-validated).
	 * @param string $repo Repo name (pattern-validated).
	 * @param string $ref The git ref (branch/tag/sha, pattern-validated).
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return string|null The resolved commit SHA, or null when unresolvable.
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function resolveCommitSha(
		string $owner,
		string $repo,
		string $ref,
		?string $actingUserId,
		?string $credentialId = null,
	): ?string {
		if ($this->validRepo(owner: $owner, repo: $repo, ref: $ref) === false || $ref === '') {
			return null;
		}

		$path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/commits/' . rawurlencode($ref);
		$result = $this->get(path: $path, actingUserId: $actingUserId, credentialId: $credentialId);
		if ($result['ok'] === false) {
			return null;
		}

		$decoded = json_decode($result['body'], true);
		if (is_array($decoded) === false) {
			return null;
		}

		$sha = (string)($decoded['sha'] ?? '');
		if ($sha === '') {
			return null;
		}

		return $sha;
	}//end resolveCommitSha()

	/**
	 * Fetch the repo file map (`path => contents`) AppRepoParser::parse expects:
	 * `openbuild-app.json`, `manifest.json`, and every `schemas/*.json`.
	 *
	 * @param string $owner Repo owner (pattern-validated).
	 * @param string $repo Repo name (pattern-validated).
	 * @param string|null $ref Optional git ref (pattern-validated).
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array<string,string> The file map (may be empty when the repo is unreachable).
	 *
	 * @spec openspec/changes/github-shop-catalogue/specs/github-shop-catalogue/spec.md
	 */
	public function fetchRepoFiles(
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId = null,
	): array {
		if ($this->validRepo(owner: $owner, repo: $repo, ref: $ref) === false) {
			return [];
		}

		$files = [];
		foreach ([AppRepoParser::DESCRIPTOR_FILE, AppRepoParser::MANIFEST_FILE] as $rootFile) {
			$contents = $this->fetchFileContents(
				owner: $owner,
				repo: $repo,
				path: $rootFile,
				ref: $ref,
				actingUserId: $actingUserId,
				credentialId: $credentialId
			);
			if ($contents !== null) {
				$files[$rootFile] = $contents;
			}
		}

		$schemaPaths = $this->listSchemaFiles(
			owner: $owner,
			repo: $repo,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);
		foreach ($schemaPaths as $schemaPath) {
			$contents = $this->fetchFileContents(
				owner: $owner,
				repo: $repo,
				path: $schemaPath,
				ref: $ref,
				actingUserId: $actingUserId,
				credentialId: $credentialId
			);
			if ($contents !== null) {
				$files[$schemaPath] = $contents;
			}
		}

		// The app-repo-format-v2 channels. Without this the parser — which DOES know
		// how to read them — is handed a v1 file set, so every channel comes back
		// empty and a v2 repo installs as if it carried nothing but a manifest.
		// Verified against the real published artefacts: buildiq-spectr fetched
		// 2 files and parsed with 0 data-registers and 0 connectors, despite the
		// repository holding 46 blobs.
		return array_merge(
			$files,
			$this->fetchChannelFiles(
				owner: $owner,
				repo: $repo,
				ref: $ref,
				actingUserId: $actingUserId,
				credentialId: $credentialId
			)
		);
	}//end fetchRepoFiles()

	/**
	 * Fetch every blob under the app-repo-format-v2 channel prefixes.
	 *
	 * Uses ONE recursive tree call rather than a contents-API walk per directory:
	 * a v2 repo can carry hundreds of entries (94 skills is ~750 blobs), and a
	 * per-directory listing would multiply the round trips before a single file
	 * is read.
	 *
	 * Bounded, and truncation is logged rather than silent — an install that
	 * quietly drops half an app is the failure this format exists to prevent.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID, or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array<string,string> The `path => contents` map for the v2 channels.
	 *
	 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-app-s-whole-configuration
	 */
	private function fetchChannelFiles(
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId,
	): array {
		$treeRef = ($ref ?? '');
		if ($treeRef === '') {
			$treeRef = 'HEAD';
		}

		$result = $this->get(
			path: '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
				. '/git/trees/' . rawurlencode($treeRef) . '?recursive=1',
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);
		if ($result['ok'] === false) {
			return [];
		}

		$decoded = json_decode($result['body'], true);
		if (is_array($decoded) === false || is_array($decoded['tree'] ?? null) === false) {
			return [];
		}

		$prefixes = [
			AppRepoParser::DATA_REGISTERS_PREFIX,
			AppRepoParser::CONNECTORS_PREFIX,
			AppRepoParser::AUTOMATIONS_PREFIX,
			AppRepoParser::SKILLS_PREFIX,
			// App-repo-format-flow-agent-export: AppRepoParser::parseChannels()
			// has read these two since that change landed, but this fetch-side
			// allowlist was never extended to match — the exact "parser can read
			// it, fetch never downloaded it" defect this method's own docblock
			// warns about (verified live: buildiq-spectr fetched 2 files and
			// parsed 0 data-registers before that fix). Confirmed live again
			// here: a repo publishing flows/<uuid>.json and agents/<uuid>.json
			// pulled back with both channels declared 0 until these two lines
			// were added.
			AppRepoParser::FLOWS_PREFIX,
			AppRepoParser::AGENTS_PREFIX,
		];

		$files = [];
		$truncated = false;

		foreach ($decoded['tree'] as $entry) {
			if (is_array($entry) === false || (string)($entry['type'] ?? '') !== 'blob') {
				continue;
			}

			$path = (string)($entry['path'] ?? '');
			if ($path === '' || str_contains($path, '..') === true) {
				continue;
			}

			$wanted = false;
			foreach ($prefixes as $prefix) {
				if (str_starts_with($path, $prefix) === true) {
					$wanted = true;
					break;
				}
			}

			if ($wanted === false) {
				continue;
			}

			if (count($files) >= self::MAX_CHANNEL_FILES) {
				$truncated = true;
				continue;
			}

			$contents = $this->fetchFileContents(
				owner: $owner,
				repo: $repo,
				path: $path,
				ref: $ref,
				actingUserId: $actingUserId,
				credentialId: $credentialId
			);
			if ($contents !== null) {
				$files[$path] = $contents;
			}
		}//end foreach

		if ($truncated === true) {
			$this->logger->warning(
				'Buildiq: v2 channel fetch truncated — the installed app will be INCOMPLETE.',
				['owner' => $owner, 'repo' => $repo, 'limit' => self::MAX_CHANNEL_FILES]
			);
		}

		return $files;
	}//end fetchChannelFiles()

	/**
	 * Build a card from a search hit's descriptor (non-installable when the
	 * descriptor is missing/unparseable — the hit is surfaced, never dropped).
	 *
	 * @param array<string,mixed> $item A GitHub search-result item.
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array<string,mixed>|null The card, or null when the item lacks an owner/name.
	 */
	private function buildCard(array $item, ?string $actingUserId, ?string $credentialId): ?array {
		$fullName = (string)($item['full_name'] ?? '');
		$nameParts = explode('/', $fullName);
		$owner = (string)($item['owner']['login'] ?? '');
		if ($owner === '') {
			$owner = (string)($nameParts[0] ?? '');
		}

		$repo = (string)($item['name'] ?? '');
		if ($repo === '') {
			$repo = (string)($nameParts[1] ?? '');
		}

		if ($owner === '' || $repo === '') {
			return null;
		}

		$stars = (int)($item['stargazers_count'] ?? 0);
		$descriptor = $this->fetchDescriptor(
			owner: $owner,
			repo: $repo,
			ref: null,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);

		if ($descriptor === null) {
			return [
				'owner' => $owner,
				'repo' => $repo,
				'stars' => $stars,
				'installable' => false,
				'unparseable' => true,
			];
		}

		$credentials = [];
		if (is_array($descriptor['credentials'] ?? null) === true) {
			$credentials = $descriptor['credentials'];
		}

		return [
			'owner' => $owner,
			'repo' => $repo,
			'stars' => $stars,
			'installable' => true,
			'unparseable' => false,
			'slug' => (string)($descriptor['slug'] ?? ''),
			'name' => (string)($descriptor['name'] ?? $repo),
			'description' => (string)($descriptor['description'] ?? ''),
			'category' => (string)($descriptor['category'] ?? ''),
			'appType' => (string)($descriptor['appType'] ?? ''),
			'version' => (string)($descriptor['version'] ?? ''),
			'credentials' => $credentials,
		];
	}//end buildCard()

	/**
	 * List `schemas/*.json` paths via the contents API directory listing.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array<int,string> Repo-relative `schemas/<slug>.json` paths.
	 */
	private function listSchemaFiles(
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId,
	): array {
		$path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/schemas';
		if ($ref !== null && $ref !== '') {
			$path .= '?ref=' . rawurlencode($ref);
		}

		$result = $this->get(path: $path, actingUserId: $actingUserId, credentialId: $credentialId);
		if ($result['ok'] === false) {
			return [];
		}

		$decoded = json_decode($result['body'], true);
		if (is_array($decoded) === false) {
			return [];
		}

		$paths = [];
		foreach ($decoded as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$name = (string)($entry['name'] ?? '');
			if ((string)($entry['type'] ?? '') === 'file' && str_ends_with($name, '.json') === true) {
				$paths[] = 'schemas/' . $name;
			}
		}

		return $paths;
	}//end listSchemaFiles()

	/**
	 * Fetch a single file's decoded contents via the GitHub contents API.
	 *
	 * @param string $owner Repo owner.
	 * @param string $repo Repo name.
	 * @param string $path Repo-relative file path.
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID (broker identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return string|null The decoded file contents, or null when absent/unreadable.
	 */
	private function fetchFileContents(
		string $owner,
		string $repo,
		string $path,
		?string $ref,
		?string $actingUserId,
		?string $credentialId,
	): ?string {
		$apiPath = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/contents/'
			. implode('/', array_map('rawurlencode', explode('/', $path)));
		if ($ref !== null && $ref !== '') {
			$apiPath .= '?ref=' . rawurlencode($ref);
		}

		$result = $this->get(path: $apiPath, actingUserId: $actingUserId, credentialId: $credentialId);
		if ($result['ok'] === false) {
			return null;
		}

		$decoded = json_decode($result['body'], true);
		if (is_array($decoded) === false) {
			return null;
		}

		$content = (string)($decoded['content'] ?? '');
		$encoding = (string)($decoded['encoding'] ?? '');
		if ($encoding === 'base64') {
			$raw = base64_decode(str_replace("\n", '', $content), true);
			if ($raw === false) {
				return null;
			}

			return $raw;
		}

		if ($content === '') {
			return null;
		}

		return $content;
	}//end fetchFileContents()

	/**
	 * Perform a GET — via the broker when a credential is supplied and the broker
	 * admits the call, else anonymously (feature-detect + fall back).
	 *
	 * @param string $path The GitHub-relative path (starts with `/`).
	 * @param string|null $actingUserId The session UID (broker owner-guard identity), or null.
	 * @param string|null $credentialId Optional allowed `github` credential.
	 *
	 * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}
	 */
	private function get(string $path, ?string $actingUserId, ?string $credentialId): array {
		if ($credentialId !== null && $credentialId !== '' && $this->isBrokerAvailable() === true) {
			$brokered = $this->brokerGet(path: $path, credentialId: $credentialId, actingUserId: $actingUserId);
			if ($brokered !== null) {
				return $brokered;
			}

			// Broker denied / rules missing — fall through to anonymous.
		}

		return $this->anonymousGet(path: $path);
	}//end get()

	/**
	 * Route a GET through the OpenRegister credential broker.
	 *
	 * @param string $path The GitHub-relative path.
	 * @param string $credentialId The `github` credential UUID.
	 * @param string|null $actingUserId The session UID for the broker owner guard.
	 *
	 * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}|null Null when the broker
	 *                                                                                     denies the call (caller falls back to anonymous).
	 */
	private function brokerGet(string $path, string $credentialId, ?string $actingUserId): ?array {
		try {
			$broker = $this->container->get(self::BROKER_CLASS);
			$response = $broker->request(
				$credentialId,
				self::APP_ID,
				'GET',
				$path,
				['Accept' => 'application/vnd.github+json'],
				null,
				$actingUserId
			);
		} catch (Throwable $e) {
			$this->logger->debug('Buildiq GitHub catalog: broker call not admitted, falling back to anonymous.');
			return null;
		}

		$status = (int)($response['status'] ?? 0);
		$body = (string)($response['body'] ?? '');

		return [
			'ok' => ($status >= 200 && $status < 300),
			'status' => $status,
			'body' => $body,
			'rateLimited' => ($status === 403 || $status === 429),
			'brokerUsed' => true,
		];
	}//end brokerGet()

	/**
	 * Perform an anonymous GET against the fixed GitHub host.
	 *
	 * @param string $path The GitHub-relative path.
	 *
	 * @return array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool}
	 */
	private function anonymousGet(string $path): array {
		try {
			$response = $this->clientService->newClient()->get(
				self::API_BASE . $path,
				[
					'timeout' => self::TIMEOUT,
					'connect_timeout' => self::TIMEOUT,
					'headers' => [
						'Accept' => 'application/vnd.github+json',
						'User-Agent' => 'Buildiq-Shop',
					],
				]
			);
		} catch (Throwable $e) {
			$rateLimited = (str_contains($e->getMessage(), '403') === true || str_contains($e->getMessage(), '429') === true);
			return ['ok' => false, 'status' => 0, 'body' => '', 'rateLimited' => $rateLimited, 'brokerUsed' => false];
		}

		$status = $response->getStatusCode();

		return [
			'ok' => ($status >= 200 && $status < 300),
			'status' => $status,
			'body' => (string)$response->getBody(),
			'rateLimited' => ($status === 403 || $status === 429),
			'brokerUsed' => false,
		];
	}//end anonymousGet()

	/**
	 * Validate owner/repo/ref against safe patterns before path interpolation.
	 *
	 * @param string $owner The repo owner.
	 * @param string $repo The repo name.
	 * @param string|null $ref Optional git ref.
	 *
	 * @return bool
	 */
	private function validRepo(string $owner, string $repo, ?string $ref): bool {
		if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
			return false;
		}

		if ($ref !== null && $ref !== '' && preg_match(self::REF_PATTERN, $ref) !== 1) {
			return false;
		}

		return true;
	}//end validRepo()

	/**
	 * Read a value from the short-TTL cache (no-op when no cache backend).
	 *
	 * @param string $key The cache key.
	 *
	 * @return mixed The cached value, or null on a miss / no cache.
	 */
	private function cacheGet(string $key): mixed {
		if ($this->cache === null) {
			return null;
		}

		return $this->cache->get($key);
	}//end cacheGet()

	/**
	 * Write a value to the short-TTL cache (no-op when no cache backend).
	 *
	 * @param string $key The cache key.
	 * @param mixed $value The value to cache.
	 * @param int $ttl The TTL in seconds.
	 *
	 * @return void
	 */
	private function cacheSet(string $key, mixed $value, int $ttl): void {
		if ($this->cache === null) {
			return;
		}

		$this->cache->set($key, $value, $ttl);
	}//end cacheSet()
}//end class
