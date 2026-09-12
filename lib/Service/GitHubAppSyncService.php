<?php

/**
 * Buildiq GitHubAppSyncService
 *
 * The owner round-trip between a local Buildiq app and its GitHub home
 * (github-app-sync). `push` serialises the chosen ApplicationVersion via
 * github-app-repo-format's AppRepoSerializer and commits it to GitHub by porting
 * the Git Data API tree-push mechanics (blob → tree → commit → ref) from the
 * exporter's GitHubPushService — but routing EVERY outbound HTTP call through
 * OpenRegister's CredentialBrokerService so the credential's token is used by the
 * broker and NEVER reaches Buildiq (REQ-GHAS-005). `pull` fetches the linked
 * repo (github-shop-catalogue's GitHubCatalogService), strictly parses it
 * (AppRepoParser), and creates a NEW DRAFT ApplicationVersion — it never modifies
 * `productionVersion` or any published version.
 *
 * Non-destructive by construction: push parents the commit on the current head so
 * it ADDS a commit (never a force overwrite; a moved head surfaces `push_conflict`),
 * and pull always yields a draft the owner reviews and promotes via the existing
 * release flow. The broker is resolved lazily (`class_exists` + the injected
 * container, mirroring RemoteTemplateStoreService); when it is absent, publish fails closed
 * (reported unavailable) rather than falling back to any token-bearing path.
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
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Exception\AppRepoParseException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Owner round-trip (link/push/pull) routing every GitHub write through the broker.
 *
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */
class GitHubAppSyncService {
	/**
	 * The credential-broker service FQCN (resolved lazily; may be absent).
	 */
	private const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * The broker `appId` Buildiq identifies itself with.
	 */
	private const APP_ID = 'buildiq';

	/**
	 * The shared Buildiq register slug.
	 */
	private const REGISTER_SLUG = 'buildiq';

	/**
	 * The Application schema slug.
	 */
	private const APPLICATION_SCHEMA = 'built-app';

	/**
	 * The ApplicationVersion schema slug.
	 */
	private const VERSION_SCHEMA = 'applicationVersion';

	/**
	 * The discovery topic set on a freshly created repo.
	 */
	private const DISCOVERY_TOPIC = 'openbuild-app';

	/**
	 * Outcome: the operation succeeded.
	 */
	public const OUTCOME_OK = 'ok';

	/**
	 * Outcome: the credential broker is absent — publish is unavailable.
	 */
	public const OUTCOME_BROKER_UNAVAILABLE = 'broker_unavailable';

	/**
	 * Outcome: the broker denied the call (rules missing / credential not allowed).
	 */
	public const OUTCOME_BROKER_DENIED = 'broker_denied';

	/**
	 * Outcome: the app is not linked to a GitHub repository.
	 */
	public const OUTCOME_NOT_LINKED = 'not_linked';

	/**
	 * Outcome: the remote head moved — the owner must pull first (never force).
	 */
	public const OUTCOME_PUSH_CONFLICT = 'push_conflict';

	/**
	 * Outcome: GitHub / transport failure.
	 */
	public const OUTCOME_UNREACHABLE = 'github_unreachable';

	/**
	 * Outcome: GitHub refused the call on permissions grounds (HTTP 403).
	 *
	 * Distinct from a transport failure. Publishing a generated app needs a
	 * credential carrying the `workflow` permission, because the scaffold always
	 * emits `.github/workflows/*` and GitHub rejects a tree containing those
	 * without it.
	 */
	public const OUTCOME_FORBIDDEN = 'github_forbidden';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OR object CRUD (load/save Application + versions).
	 * @param RegisterMapper $registerMapper Provisions the draft register on pull.
	 * @param SchemaMapper $schemaMapper Clones pulled companion schemas into the draft register.
	 * @param AppRepoSerializer $serializer Local → repo file map (change 1).
	 * @param AppRepoParser $parser Repo file map → clone-seam array (change 1).
	 * @param GitHubCatalogService $catalogService Repo fetch + commit-sha resolution (change 2).
	 * @param AppChannelApplier $channelApplier Applies the v2 repo channels (apply-v2-channels).
	 * @param LoggerInterface $logger PSR logger (secret-free diagnostics only).
	 * @param ContainerInterface $container DI container, resolves the OpenRegister
	 *                                      broker at call time (never the global server).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly AppRepoSerializer $serializer,
		private readonly AppRepoParser $parser,
		private readonly GitHubCatalogService $catalogService,
		private readonly AppChannelApplier $channelApplier,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether the OpenRegister credential broker is present on this instance.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function isBrokerAvailable(): bool {
		return class_exists(self::BROKER_CLASS) === true;
	}//end isBrokerAvailable()

	/**
	 * Load an Application object by slug (org-wide; RBAC gating is the caller's job).
	 *
	 * @param string $slug The Application slug.
	 *
	 * @return array<string,mixed>|null The Application object, or null when absent.
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function loadApplicationBySlug(string $slug): ?array {
		try {
			$registerId = $this->registerMapper->find(self::REGISTER_SLUG, _multitenancy: false)->getId();
			$schemaId = $this->schemaMapper->find(self::APPLICATION_SCHEMA, _multitenancy: false)->getId();

			$results = $this->objectService->searchObjects(
				query: [
					'@self' => [
						'register' => $registerId,
						'schema' => $schemaId,
					],
					'slug' => $slug,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq GitHub sync: application lookup failed: ' . $e->getMessage());
			return null;
		}

		foreach ((array)$results as $result) {
			$data = $this->normalise(object: $result);
			if ((string)($data['slug'] ?? '') === $slug) {
				return $data;
			}
		}

		return null;
	}//end loadApplicationBySlug()

	/**
	 * Serialize the chosen version and push it through the broker (non-destructive).
	 *
	 * @param array<string,mixed> $application The owner-gated Application object.
	 * @param string|null $versionSlug Optional version slug to push (else the production/first).
	 * @param string $credentialId The allowed `github` credential UUID.
	 * @param array{owner:string,name:string,org?:string}|null $repoOverride Optional repo to create/link on this push.
	 * @param string|null $actingUserId The session UID (broker owner-guard identity).
	 * @param string $visibility Fresh-repo visibility ('public'|'private').
	 *
	 * @return array<string,mixed> `{outcome, ...}` — outcome `ok` carries `commitSha`/`branch`/`repoUrl`.
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function push(
		array $application,
		?string $versionSlug,
		string $credentialId,
		?array $repoOverride,
		?string $actingUserId,
		string $visibility = 'public',
	): array {
		if ($this->isBrokerAvailable() === false) {
			return ['outcome' => self::OUTCOME_BROKER_UNAVAILABLE];
		}

		$version = $this->resolveVersion(application: $application, versionSlug: $versionSlug);
		if ($version === null) {
			return ['outcome' => 'version_not_found'];
		}

		$files = $this->serializer->serialize(application: $application, version: $version);

		$repo = $this->ensureRepo(
			application: $application,
			repoOverride: $repoOverride,
			credentialId: $credentialId,
			actingUserId: $actingUserId,
			visibility: $visibility
		);
		if (isset($repo['outcome']) === true) {
			return $repo;
		}

		$result = $this->commitFileMap(
			owner: $repo['owner'],
			name: $repo['name'],
			branch: $repo['branch'],
			files: $files,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		if ($result['outcome'] !== self::OUTCOME_OK) {
			return $result;
		}

		$this->stampVersionProvenance(version: $version, commitSha: (string)$result['commitSha'], sourceRef: null);

		return $result;
	}//end push()

	/**
	 * Serialize a seeded template and publish it to GitHub as an `buildiq-app`
	 * repo (never-throw). The repo is created (or reused) via the broker, tagged
	 * with the discovery topic, then the template file map is tree-pushed — the
	 * SAME create-repo + topic + Git-Data mechanics `push()` uses, so the result
	 * is installable by AppRepoParser (round-trip).
	 *
	 * @param array<string,mixed> $template The seeded application-template object.
	 * @param string $credentialId The allowed `github` credential UUID.
	 * @param string|null $org Optional org to create the repo under (null = the credential user's account).
	 * @param string|null $repoName Optional repo name (defaults to `buildiq-{template-slug}`).
	 * @param string|null $actingUserId The session UID (broker owner-guard identity).
	 * @param string $visibility Repo visibility for a freshly created repo ('public'|'private'); 'public' by default.
	 *
	 * @return array<string,mixed> `{outcome, ...}` — outcome `ok` carries `repoUrl`/`commitSha`/`branch`.
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function publishTemplate(
		array $template,
		string $credentialId,
		?string $org,
		?string $repoName,
		?string $actingUserId,
		string $visibility = 'public',
	): array {
		if ($this->isBrokerAvailable() === false) {
			return ['outcome' => self::OUTCOME_BROKER_UNAVAILABLE];
		}

		$slug = trim((string)($template['slug'] ?? ''));
		if ($slug === '') {
			return ['outcome' => 'template_invalid'];
		}

		$name = trim((string)($repoName ?? ''));
		if ($name === '') {
			$name = 'openbuild-' . $slug;
		}

		$files = $this->serializer->serializeTemplate(template: $template);

		$repo = $this->createRepoViaBroker(
			name: $name,
			org: $org,
			credentialId: $credentialId,
			actingUserId: $actingUserId,
			visibility: $visibility,
			reuseExisting: true
		);
		if (isset($repo['outcome']) === true) {
			return $repo;
		}

		return $this->commitFileMap(
			owner: $repo['owner'],
			name: $repo['name'],
			branch: $repo['branch'],
			files: $files,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
	}//end publishTemplate()

	/**
	 * Resolve the branch head, blob→tree→commit→ref push a file map, and build
	 * the `{outcome, repoUrl, commitSha, branch}` result — the shared publish
	 * mechanics reused by `push()` and `publishTemplate()`.
	 *
	 * @param string $owner Repo owner.
	 * @param string $name Repo name.
	 * @param string $branch Target branch.
	 * @param array<string,string> $files The serialized `path => contents` map.
	 * @param string $credentialId The `github` credential UUID.
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return array<string,mixed> `{outcome}` on failure; `{outcome:ok, repoUrl, commitSha, branch}` on success.
	 */
	private function commitFileMap(
		string $owner,
		string $name,
		string $branch,
		array $files,
		string $credentialId,
		?string $actingUserId,
	): array {
		$head = $this->resolveHead(
			owner: $owner,
			name: $name,
			branch: $branch,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		$commit = $this->commitTree(
			owner: $owner,
			name: $name,
			branch: $branch,
			files: $files,
			head: $head,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		if ($commit['outcome'] !== self::OUTCOME_OK) {
			return $commit;
		}

		return [
			'outcome' => self::OUTCOME_OK,
			'repoUrl' => 'https://github.com/' . $owner . '/' . $name,
			'commitSha' => (string)$commit['commitSha'],
			'branch' => $branch,
		];
	}//end commitFileMap()

	/**
	 * Store the app's GitHub linkage (owner/name + resolved default branch).
	 *
	 * @param array<string,mixed> $application The owner-gated Application object.
	 * @param string $owner The GitHub owner (pattern-validated by the controller).
	 * @param string $name The GitHub repo name.
	 * @param string|null $credentialId Optional credential to resolve the default branch.
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return array{githubRepo:array{owner:string,name:string},githubDefaultBranch:string}
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function link(
		array $application,
		string $owner,
		string $name,
		?string $credentialId,
		?string $actingUserId,
	): array {
		$branch = $this->resolveDefaultBranch(
			owner: $owner,
			name: $name,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		$application['githubRepo'] = ['owner' => $owner, 'name' => $name];
		$application['githubDefaultBranch'] = $branch;
		$this->saveApplication(application: $application);

		return [
			'githubRepo' => ['owner' => $owner, 'name' => $name],
			'githubDefaultBranch' => $branch,
		];
	}//end link()

	/**
	 * Fetch + strictly parse the linked repo and create a NEW draft version.
	 *
	 * @param array<string,mixed> $application The owner-gated Application object.
	 * @param string $ref The git ref to pull.
	 * @param string|null $credentialId Optional credential (broker path for private repos).
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return array<string,mixed> `{outcome, ...}` — outcome `ok` carries the new draft version.
	 *
	 * @throws AppRepoParseException On a strict-parse failure (caller surfaces the code + file).
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function pull(
		array $application,
		string $ref,
		?string $credentialId,
		?string $actingUserId,
	): array {
		$repo = ($application['githubRepo'] ?? null);
		if (is_array($repo) === false) {
			return ['outcome' => self::OUTCOME_NOT_LINKED];
		}

		$owner = (string)($repo['owner'] ?? '');
		$name = (string)($repo['name'] ?? '');
		if ($owner === '' || $name === '') {
			return ['outcome' => self::OUTCOME_NOT_LINKED];
		}

		$files = $this->catalogService->fetchRepoFiles(
			owner: $owner,
			repo: $name,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);
		if ($files === []) {
			return ['outcome' => self::OUTCOME_UNREACHABLE];
		}

		// Strict, all-or-nothing (throws AppRepoParseException, surfaced by the controller).
		$template = $this->parser->parse(files: $files, repo: ['owner' => $owner, 'name' => $name]);

		$appUuid = $this->uuidOf(object: $application);
		$appSlug = (string)($application['slug'] ?? '');
		$versionSlug = $this->draftVersionSlug();
		// Per-version register — NOT this app's register slug. See
		// ApplicationVersionService::VERSION_REGISTER_PREFIX.
		$registerSlug = ApplicationVersionService::VERSION_REGISTER_PREFIX . $appSlug . '-' . $versionSlug;

		$rewriteMap = $this->reconcileCompanionSchemas(
			template: $template,
			appSlug: $appSlug,
			versionSlug: $versionSlug,
			registerSlug: $registerSlug
		);

		$manifest = $this->rewriteSchemaRefs(node: $template['manifest'], map: $rewriteMap);
		if (is_array($manifest) === false) {
			$manifest = [];
		}

		$commitSha = $this->catalogService->resolveCommitSha(
			owner: $owner,
			repo: $name,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId
		);

		$semver = (string)($template['version'] ?? '');
		if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+/', $semver) !== 1) {
			$semver = '0.1.0';
		}

		$payload = [
			'name' => 'Pulled ' . $versionSlug,
			'slug' => $versionSlug,
			'manifest' => $manifest,
			'register' => $registerSlug,
			'semver' => $semver,
			'status' => 'draft',
			'application' => $appUuid,
			'sourceRef' => $ref,
		];
		if ($commitSha !== null) {
			$payload['commitSha'] = $commitSha;
		}

		$created = $this->objectService->saveObject(
			object: $payload,
			register: self::REGISTER_SLUG,
			schema: self::VERSION_SCHEMA
		);
		$versionData = $this->normalise(object: $created);
		$versionUuid = $this->uuidOf(object: $versionData);

		// Apply the app-repo-format-v2 channels through the SAME applier the shop
		// install path uses. Before this call, pull() persisted the manifest and
		// discarded the registers, connectors, automations and skills the repo
		// carried — a complete, silent failure of what the format is for.
		$channels = $this->channelApplier->apply(
			template: $template,
			owner: $owner,
			repo: $name,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId,
			applicationUuid: $appUuid,
			applicationSlug: $appSlug
		);

		return [
			'outcome' => self::OUTCOME_OK,
			'versionUuid' => $versionUuid,
			'versionSlug' => $versionSlug,
			'commitSha' => $commitSha,
			'sourceRef' => $ref,
			'status' => 'draft',
			'register' => $registerSlug,
			'channels' => $channels,
			// Copied onto the top level — same reasoning as
			// ApplicationsController::installFromTemplateArray(): a caller
			// should not have to know to look inside `channels.<name>.reason`
			// to find an actionable degradation.
			'warnings' => ($channels['warnings'] ?? []),
		];
	}//end pull()

	/**
	 * Ensure a linked repo exists — create it via the broker when unlinked.
	 *
	 * @param array<string,mixed> $application The Application object.
	 * @param array{owner:string,name:string,org?:string}|null $repoOverride Optional repo to create/link.
	 * @param string $credentialId The `github` credential UUID.
	 * @param string|null $actingUserId The session UID (broker identity).
	 * @param string $visibility Fresh-repo visibility ('public'|'private').
	 *
	 * @return array{owner:string,name:string,branch:string}|array{outcome:string}
	 */
	private function ensureRepo(
		array $application,
		?array $repoOverride,
		string $credentialId,
		?string $actingUserId,
		string $visibility = 'public',
	): array {
		$linked = ($application['githubRepo'] ?? null);
		if ($repoOverride === null && is_array($linked) === true
			&& (string)($linked['owner'] ?? '') !== '' && (string)($linked['name'] ?? '') !== ''
		) {
			return [
				'owner' => (string)$linked['owner'],
				'name' => (string)$linked['name'],
				'branch' => $this->branchOf(application: $application),
			];
		}

		if ($repoOverride === null) {
			return ['outcome' => self::OUTCOME_NOT_LINKED];
		}

		$name = (string)$repoOverride['name'];
		$org = null;
		$orgRaw = trim((string)($repoOverride['org'] ?? ''));
		if ($orgRaw !== '') {
			$org = $orgRaw;
		}

		$repo = $this->createRepoViaBroker(
			name: $name,
			org: $org,
			credentialId: $credentialId,
			actingUserId: $actingUserId,
			visibility: $visibility,
			reuseExisting: false
		);
		if (isset($repo['outcome']) === true) {
			return $repo;
		}

		$application['githubRepo'] = ['owner' => $repo['owner'], 'name' => $repo['name']];
		$application['githubDefaultBranch'] = $repo['branch'];
		$this->saveApplication(application: $application);

		return $repo;
	}//end ensureRepo()

	/**
	 * Create (or reuse) a GitHub repo via the broker and tag it with the
	 * discovery topic — the shared create-repo mechanics used by both the app
	 * push (`ensureRepo`) and the template publish (`publishTemplate`).
	 *
	 * @param string $name The repo name.
	 * @param string|null $org Optional org to create under (null = the credential user's account).
	 * @param string $credentialId The `github` credential UUID.
	 * @param string|null $actingUserId The session UID (broker identity).
	 * @param string $visibility Repo visibility for a fresh repo ('public'|'private').
	 * @param bool $reuseExisting True reuses a 422 name-taken repo (idempotent); false surfaces unreachable (preserves push()).
	 *
	 * @return array{owner:string,name:string,branch:string}|array{outcome:string}
	 */
	private function createRepoViaBroker(
		string $name,
		?string $org,
		string $credentialId,
		?string $actingUserId,
		string $visibility,
		bool $reuseExisting,
	): array {
		$orgTrim = trim((string)($org ?? ''));
		$createPath = '/user/repos';
		if ($orgTrim !== '') {
			$createPath = '/orgs/' . rawurlencode($orgTrim) . '/repos';
		}

		// Default to a PUBLIC repo so the shop's anonymous catalogue search can
		// discover it; the caller may override to 'private' via the param.
		$isPrivate = ($visibility === 'private');

		$created = $this->brokerJson(
			method: 'POST',
			path: $createPath,
			body: [
				'name' => $name,
				'private' => $isPrivate,
				'visibility' => $visibility,
				'auto_init' => true,
				'description' => 'Published from Buildiq',
			],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		if ($created['denied'] === true) {
			return ['outcome' => self::OUTCOME_BROKER_DENIED];
		}

		if ($created['ok'] === false) {
			// A 422 means the repo name is already taken. On an idempotent
			// re-publish, reuse it: resolve the owner + default branch and push
			// an additive commit onto the existing repo.
			if ($reuseExisting === true && $created['status'] === 422) {
				return $this->reuseExistingRepo(
					name: $name,
					org: $orgTrim,
					credentialId: $credentialId,
					actingUserId: $actingUserId
				);
			}

			return ['outcome' => self::OUTCOME_UNREACHABLE];
		}//end if

		$resolvedOwner = (string)($created['data']['owner']['login'] ?? '');
		if ($resolvedOwner === '' && $orgTrim !== '') {
			$resolvedOwner = $orgTrim;
		}

		if ($resolvedOwner === '') {
			return ['outcome' => self::OUTCOME_UNREACHABLE];
		}

		$branch = (string)($created['data']['default_branch'] ?? 'main');

		$this->setDiscoveryTopic(owner: $resolvedOwner, name: $name, credentialId: $credentialId, actingUserId: $actingUserId);

		return ['owner' => $resolvedOwner, 'name' => $name, 'branch' => $branch];
	}//end createRepoViaBroker()

	/**
	 * Resolve an existing repo's owner + default branch for an idempotent
	 * re-publish, and re-assert the discovery topic.
	 *
	 * @param string $name The repo name.
	 * @param string $org The org (empty = the credential user's account).
	 * @param string $credentialId The `github` credential UUID.
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return array{owner:string,name:string,branch:string}|array{outcome:string}
	 */
	private function reuseExistingRepo(string $name, string $org, string $credentialId, ?string $actingUserId): array {
		$owner = $org;
		if ($owner === '') {
			$user = $this->brokerJson(
				method: 'GET',
				path: '/user',
				body: null,
				credentialId: $credentialId,
				actingUserId: $actingUserId
			);
			$owner = (string)($user['data']['login'] ?? '');
		}

		if ($owner === '') {
			return ['outcome' => self::OUTCOME_UNREACHABLE];
		}

		$branch = $this->resolveDefaultBranch(
			owner: $owner,
			name: $name,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		$this->setDiscoveryTopic(owner: $owner, name: $name, credentialId: $credentialId, actingUserId: $actingUserId);

		return ['owner' => $owner, 'name' => $name, 'branch' => $branch];
	}//end reuseExistingRepo()

	/**
	 * Set the shop discovery topic on a repo (best-effort; failure is non-fatal).
	 *
	 * @param string $owner Repo owner.
	 * @param string $name Repo name.
	 * @param string $credentialId The `github` credential UUID.
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return void
	 */
	private function setDiscoveryTopic(string $owner, string $name, string $credentialId, ?string $actingUserId): void {
		$this->brokerJson(
			method: 'PUT',
			path: '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name) . '/topics',
			body: ['names' => [self::DISCOVERY_TOPIC]],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
	}//end setDiscoveryTopic()

	/**
	 * Resolve the current branch head commit + base tree (null when the branch is empty).
	 *
	 * @param string $owner Repo owner.
	 * @param string $name Repo name.
	 * @param string $branch Target branch.
	 * @param string $credentialId The `github` credential UUID.
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return array{commitSha:string,treeSha:string}|null
	 */
	private function resolveHead(string $owner, string $name, string $branch, string $credentialId, ?string $actingUserId): ?array {
		$base = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name);
		$ref = $this->brokerJson(
			method: 'GET',
			path: $base . '/git/ref/heads/' . rawurlencode($branch),
			body: null,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		if ($ref['ok'] === false) {
			return null;
		}

		$commitSha = (string)($ref['data']['object']['sha'] ?? '');
		if ($commitSha === '') {
			return null;
		}

		$commit = $this->brokerJson(
			method: 'GET',
			path: $base . '/git/commits/' . rawurlencode($commitSha),
			body: null,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		$treeSha = (string)($commit['data']['tree']['sha'] ?? '');

		return ['commitSha' => $commitSha, 'treeSha' => $treeSha];
	}//end resolveHead()

	/**
	 * Blob → tree → commit → ref, all broker-routed; parents on the head.
	 *
	 * @param string $owner Repo owner.
	 * @param string $name Repo name.
	 * @param string $branch Target branch.
	 * @param array<string,string> $files The serialized `path => contents` map.
	 * @param array{commitSha:string,treeSha:string}|null $head Current head, or null for an empty branch.
	 * @param string $credentialId The `github` credential UUID.
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return array{outcome:string,commitSha?:string}
	 */
	private function commitTree(
		string $owner,
		string $name,
		string $branch,
		array $files,
		?array $head,
		string $credentialId,
		?string $actingUserId,
	): array {
		$base = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name);
		$entries = [];
		foreach ($files as $path => $contents) {
			$blob = $this->brokerJson(
				method: 'POST',
				path: $base . '/git/blobs',
				body: ['content' => base64_encode($contents), 'encoding' => 'base64'],
				credentialId: $credentialId,
				actingUserId: $actingUserId
			);
			if ($blob['denied'] === true) {
				return ['outcome' => self::OUTCOME_BROKER_DENIED];
			}

			if ($blob['ok'] === false) {
				return ['outcome' => self::OUTCOME_UNREACHABLE];
			}

			$entries[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => (string)($blob['data']['sha'] ?? '')];
		}//end foreach

		$treeBody = ['tree' => $entries];
		if ($head !== null && $head['treeSha'] !== '') {
			$treeBody['base_tree'] = $head['treeSha'];
		}

		$tree = $this->brokerJson(
			method: 'POST',
			path: $base . '/git/trees',
			body: $treeBody,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		if ($tree['ok'] === false) {
			return ['outcome' => $this->brokerFailureOutcome(result: $tree)];
		}

		$commitBody = ['message' => 'chore: publish from Buildiq', 'tree' => (string)($tree['data']['sha'] ?? '')];
		if ($head !== null && $head['commitSha'] !== '') {
			$commitBody['parents'] = [$head['commitSha']];
		}

		$commit = $this->brokerJson(
			method: 'POST',
			path: $base . '/git/commits',
			body: $commitBody,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		if ($commit['ok'] === false) {
			return ['outcome' => $this->brokerFailureOutcome(result: $commit)];
		}

		$commitSha = (string)($commit['data']['sha'] ?? '');
		$refResult = $this->advanceRef(
			base: $base,
			branch: $branch,
			commitSha: $commitSha,
			create: ($head === null),
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		if ($refResult !== self::OUTCOME_OK) {
			return ['outcome' => $refResult];
		}

		return ['outcome' => self::OUTCOME_OK, 'commitSha' => $commitSha];
	}//end commitTree()

	/**
	 * Advance (or create) the branch ref — a non-fast-forward yields push_conflict.
	 *
	 * @param string $base The `/repos/{o}/{r}` path base.
	 * @param string $branch Target branch.
	 * @param string $commitSha The new commit SHA.
	 * @param bool $create Whether to create the ref (empty branch) vs advance it.
	 * @param string $credentialId The `github` credential UUID.
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return string An outcome constant (`ok` / `push_conflict` / broker/transport error).
	 */
	private function advanceRef(string $base, string $branch, string $commitSha, bool $create, string $credentialId, ?string $actingUserId): string {
		$method = 'PATCH';
		$path = $base . '/git/refs/heads/' . rawurlencode($branch);
		$body = ['sha' => $commitSha, 'force' => false];
		if ($create === true) {
			$method = 'POST';
			$path = $base . '/git/refs';
			$body = ['ref' => 'refs/heads/' . $branch, 'sha' => $commitSha];
		}

		$result = $this->brokerJson(
			method: $method,
			path: $path,
			body: $body,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		if ($result['ok'] === true) {
			return self::OUTCOME_OK;
		}

		if ($result['denied'] === true) {
			return self::OUTCOME_BROKER_DENIED;
		}

		// A 422 on a non-force ref advance is a non-fast-forward (moved head).
		if ($result['status'] === 422) {
			return self::OUTCOME_PUSH_CONFLICT;
		}

		return self::OUTCOME_UNREACHABLE;
	}//end advanceRef()

	/**
	 * Provision a fresh draft register and clone the pulled companion schemas into
	 * it, namespaced by the draft register scope (isolated from production).
	 *
	 * @param array<string,mixed> $template The parsed clone-seam array.
	 * @param string $appSlug The Application slug.
	 * @param string $versionSlug The new draft version slug.
	 * @param string $registerSlug The draft register slug to provision.
	 *
	 * @return array<string,string> Source-slug → namespaced-slug rewrite map.
	 */
	private function reconcileCompanionSchemas(array $template, string $appSlug, string $versionSlug, string $registerSlug): array {
		$companions = [];
		if (is_array($template['companionSchemas'] ?? null) === true) {
			$companions = $template['companionSchemas'];
		}

		$prefix = $appSlug . '-' . $versionSlug . '-';
		$map = [];
		foreach ($companions as $companion) {
			if (is_array($companion) === false || isset($companion['slug']) === false) {
				continue;
			}

			$source = (string)$companion['slug'];
			$map[$source] = $prefix . $source;
		}

		try {
			$register = $this->findOrCreateRegister(slug: $registerSlug, appSlug: $appSlug);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq GitHub pull: could not provision draft register: ' . $e->getMessage());
			return $map;
		}

		$createdIds = [];
		foreach ($companions as $companion) {
			if (is_array($companion) === false || isset($companion['slug']) === false) {
				continue;
			}

			$source = (string)$companion['slug'];
			$blob = $this->rewriteSchemaRefs(node: $companion, map: $map);
			if (is_array($blob) === false) {
				continue;
			}

			$blob['slug'] = $map[$source];
			if (isset($blob['version']) === false) {
				$blob['version'] = '0.1.0';
			}

			try {
				$createdIds[] = $this->schemaMapper->createFromArray(object: $blob)->getId();
			} catch (Throwable $e) {
				$this->logger->warning('Buildiq GitHub pull: could not clone schema "' . $source . '": ' . $e->getMessage());
			}
		}//end foreach

		if ($createdIds !== []) {
			try {
				$existing = (array)$register->getSchemas();
				$register->setSchemas(array_values(array_unique(array_merge($existing, $createdIds))));
				$this->registerMapper->update($register);
			} catch (Throwable $e) {
				$this->logger->warning('Buildiq GitHub pull: could not attach schemas to draft register: ' . $e->getMessage());
			}
		}

		return $map;
	}//end reconcileCompanionSchemas()

	/**
	 * Find or create a draft register at an exact slug.
	 *
	 * @param string $slug The register slug.
	 * @param string $appSlug The Application slug (for the title).
	 *
	 * @return \OCA\OpenRegister\Db\Register
	 */
	private function findOrCreateRegister(string $slug, string $appSlug): \OCA\OpenRegister\Db\Register {
		try {
			return $this->registerMapper->find($slug, _multitenancy: false);
		} catch (Throwable) {
			// Not found — create it below.
		}

		return $this->registerMapper->createFromArray(
			[
				'slug' => $slug,
				'title' => 'Buildiq — ' . $appSlug . ' (pulled draft)',
				'description' => 'Draft register for a GitHub-pulled version of Buildiq app `' . $appSlug . '`.',
				'version' => '0.1.0',
				'schemas' => [],
			]
		);
	}//end findOrCreateRegister()

	/**
	 * Recursively rewrite manifest/schema `schema`/`relatedSchema` refs.
	 *
	 * @param mixed $node The node to rewrite.
	 * @param array<string,string> $map Source-slug → namespaced-slug map.
	 *
	 * @return mixed The rewritten node.
	 */
	private function rewriteSchemaRefs(mixed $node, array $map): mixed {
		if (is_array($node) === false) {
			return $node;
		}

		foreach ($node as $key => $value) {
			if (($key === 'schema' || $key === 'relatedSchema') && is_string($value) === true && isset($map[$value]) === true) {
				$node[$key] = $map[$value];
				continue;
			}

			$node[$key] = $this->rewriteSchemaRefs(node: $value, map: $map);
		}

		return $node;
	}//end rewriteSchemaRefs()

	/**
	 * Resolve the version to push (by slug, else the production version, else first).
	 *
	 * @param array<string,mixed> $application The Application object.
	 * @param string|null $versionSlug Optional version slug.
	 *
	 * @return array<string,mixed>|null The version object, or null when none resolvable.
	 */
	private function resolveVersion(array $application, ?string $versionSlug): ?array {
		$appUuid = $this->uuidOf(object: $application);
		try {
			$registerId = $this->registerMapper->find(self::REGISTER_SLUG, _multitenancy: false)->getId();
			$schemaId = $this->schemaMapper->find(self::VERSION_SCHEMA, _multitenancy: false)->getId();

			// OR's searchObjects does not reliably filter by relation-string
			// equality on `application` (stored inline AND in @self.relations),
			// so fetch every version row for the register+schema and filter
			// client-side by the parent Application UUID — the proven pattern
			// in ApplicationVersionsController::index. Cheap (~few rows/app).
			$results = $this->objectService->searchObjects(
				query: [
					'@self' => [
						'register' => $registerId,
						'schema' => $schemaId,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq GitHub sync: version lookup failed: ' . $e->getMessage());
			return null;
		}//end try

		$versions = [];
		foreach ((array)$results as $result) {
			$normalisedRow = $this->normalise(object: $result);
			if ((string)($normalisedRow['application'] ?? '') !== $appUuid) {
				continue;
			}

			$versions[] = $normalisedRow;
		}

		if ($versions === []) {
			return null;
		}

		if ($versionSlug !== null && $versionSlug !== '') {
			foreach ($versions as $version) {
				if ((string)($version['slug'] ?? '') === $versionSlug) {
					return $version;
				}
			}

			return null;
		}

		$production = (string)($application['productionVersion'] ?? '');
		if ($production !== '') {
			foreach ($versions as $version) {
				if ($this->uuidOf(object: $version) === $production) {
					return $version;
				}
			}
		}

		return $versions[0];
	}//end resolveVersion()

	/**
	 * Resolve a repo's default branch (via the broker, else the stored/default).
	 *
	 * @param string $owner Repo owner.
	 * @param string $name Repo name.
	 * @param string|null $credentialId Optional credential.
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return string The default branch (falls back to `main`).
	 */
	private function resolveDefaultBranch(string $owner, string $name, ?string $credentialId, ?string $actingUserId): string {
		if ($credentialId !== null && $credentialId !== '' && $this->isBrokerAvailable() === true) {
			$repo = $this->brokerJson(
				method: 'GET',
				path: '/repos/' . rawurlencode($owner) . '/' . rawurlencode($name),
				body: null,
				credentialId: $credentialId,
				actingUserId: $actingUserId
			);
			if ($repo['ok'] === true) {
				$branch = (string)($repo['data']['default_branch'] ?? '');
				if ($branch !== '') {
					return $branch;
				}
			}
		}

		return 'main';
	}//end resolveDefaultBranch()

	/**
	 * Persist a `commitSha` (+ optional `sourceRef`) onto a version without
	 * touching its manifest (so the content auto-bump does not fire).
	 *
	 * @param array<string,mixed> $version The version object.
	 * @param string $commitSha The commit SHA to stamp.
	 * @param string|null $sourceRef Optional source ref to stamp.
	 *
	 * @return void
	 */
	private function stampVersionProvenance(array $version, string $commitSha, ?string $sourceRef): void {
		$version['commitSha'] = $commitSha;
		if ($sourceRef !== null) {
			$version['sourceRef'] = $sourceRef;
		}

		try {
			$this->objectService->saveObject(
				object: $version,
				register: self::REGISTER_SLUG,
				schema: self::VERSION_SCHEMA
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq GitHub sync: could not stamp commitSha: ' . $e->getMessage());
		}
	}//end stampVersionProvenance()

	/**
	 * Persist the Application object (linkage write).
	 *
	 * @param array<string,mixed> $application The Application object.
	 *
	 * @return void
	 */
	private function saveApplication(array $application): void {
		try {
			$this->objectService->saveObject(
				object: $application,
				register: self::REGISTER_SLUG,
				schema: self::APPLICATION_SCHEMA
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq GitHub sync: could not persist linkage: ' . $e->getMessage());
		}
	}//end saveApplication()

	/**
	 * Route a single JSON call through the credential broker.
	 *
	 * @param string $method The HTTP method.
	 * @param string $path The GitHub-relative path.
	 * @param array<string,mixed>|null $body Optional JSON body.
	 * @param string $credentialId The `github` credential UUID.
	 * @param string|null $actingUserId The session UID (broker identity).
	 *
	 * @return array{ok:bool,denied:bool,status:int,data:array<string,mixed>}
	 */
	private function brokerJson(string $method, string $path, ?array $body, string $credentialId, ?string $actingUserId): array {
		$encoded = null;
		if ($body !== null) {
			$encoded = (string)json_encode($body);
		}

		try {
			$broker = $this->container->get(self::BROKER_CLASS);
			$response = $broker->request(
				$credentialId,
				self::APP_ID,
				$method,
				$path,
				['Accept' => 'application/vnd.github+json', 'Content-Type' => 'application/json'],
				$encoded,
				$actingUserId
			);
		} catch (Throwable $e) {
			// At `debug` this is invisible on any instance running a normal
			// loglevel, which leaves an operator with a bare 502 and nothing to
			// go on. The exception message is the whole diagnosis; log it.
			$this->logger->warning(
				'Buildiq GitHub sync: broker call failed for ' . $method . ' ' . $path
				. ' — ' . get_class($e) . ': ' . $e->getMessage()
			);
			return ['ok' => false, 'denied' => true, 'status' => 0, 'data' => []];
		}//end try

		$status = (int)($response['status'] ?? 0);
		$decoded = json_decode((string)($response['body'] ?? ''), true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		if ($status < 200 || $status >= 300) {
			// GitHub's own message is precise and actionable ("Resource not
			// accessible by personal access token", "Not Found", a rate-limit
			// notice); discarding it and reporting only `github_unreachable`
			// sends the reader at the network instead of the real cause.
			$this->logger->warning(
				'Buildiq GitHub sync: ' . $method . ' ' . $path . ' returned ' . $status
				. ' — ' . substr((string)($response['body'] ?? ''), 0, 300)
			);
		}

		return [
			'ok' => ($status >= 200 && $status < 300),
			'denied' => false,
			'status' => $status,
			'data' => $decoded,
		];
	}//end brokerJson()

	/**
	 * Map a failed broker result to the right generic outcome (denied vs transport).
	 *
	 * @param array{ok:bool,denied:bool,status:int,data:array<string,mixed>} $result The broker result.
	 *
	 * @return string
	 */
	private function brokerFailureOutcome(array $result): string {
		if ($result['denied'] === true) {
			return self::OUTCOME_BROKER_DENIED;
		}

		// A 403 from GitHub is a permissions answer, not a transport failure.
		// The common case is a token without the `workflow` permission: the
		// generated scaffold ships `.github/workflows/*`, and GitHub refuses the
		// whole tree with "Resource not accessible by personal access token".
		// Reporting that as "unreachable" is what makes it hard to diagnose.
		if ($result['status'] === 403) {
			return self::OUTCOME_FORBIDDEN;
		}

		return self::OUTCOME_UNREACHABLE;
	}//end brokerFailureOutcome()

	/**
	 * The app's stored default branch, defaulting to `main`.
	 *
	 * @param array<string,mixed> $application The Application object.
	 *
	 * @return string
	 */
	private function branchOf(array $application): string {
		$branch = trim((string)($application['githubDefaultBranch'] ?? ''));
		if ($branch === '') {
			return 'main';
		}

		return $branch;
	}//end branchOf()

	/**
	 * Generate a unique kebab-case draft version slug for a pull.
	 *
	 * @return string
	 */
	private function draftVersionSlug(): string {
		return 'pull-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
	}//end draftVersionSlug()

	/**
	 * Extract the UUID/id from an object array.
	 *
	 * @param array<string,mixed> $object The object array.
	 *
	 * @return string
	 */
	private function uuidOf(array $object): string {
		$self = ($object['@self'] ?? null);
		if (is_array($self) === true && (string)($self['id'] ?? '') !== '') {
			return (string)$self['id'];
		}

		return (string)($object['id'] ?? ($object['uuid'] ?? ''));
	}//end uuidOf()

	/**
	 * Coerce an OR result entry to a plain associative array.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end normalise()
}//end class
