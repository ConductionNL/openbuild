<?php

/**
 * Buildiq GitHub Push Service
 *
 * Pushes the generated app tree to a new GitHub repository and opens a
 * placeholder pull request, over the GitHub REST + Git Data API (create-repo,
 * create-blob, create-tree, create-commit, create-ref, create-pull).
 *
 * Buildiq holds NO GitHub token. Decision 3 originally said the PAT was
 * "method-scoped — never persisted on the service instance", but the PAT was
 * still typed into an Buildiq dialog, POSTed to an Buildiq controller,
 * persisted by Buildiq for the duration of the job, and replayed by Buildiq
 * against api.github.com. Method scope is not custody: the app could read the
 * secret, so the app was the trust boundary.
 *
 * Every call now goes through OpenRegister's credential broker. This service
 * sends {method, path, body} plus a credential UUID; the broker checks the
 * owner, the allowed-app grant and the allow-rules, then injects the token
 * server-side. The token never enters this process. When the broker is absent
 * we fail closed — there is no token-bearing fallback left to fall back to.
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
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use FilesystemIterator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * GitHub delivery target. PAT-handling contract documented in Decision 3.
 *
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
 */
class GitHubPushService {
	/**
	 * OpenRegister's credential broker.
	 *
	 * This service used to take the user's GitHub Personal Access Token: the PAT was
	 * typed into an Buildiq dialog, POSTed to an Buildiq controller, persisted by
	 * Buildiq, and replayed by Buildiq against api.github.com. Encryption at rest
	 * did not change that — custody was the app's, which is exactly what the broker
	 * exists to prevent.
	 *
	 * Every GitHub call now goes through the broker: Buildiq sends {method, path,
	 * body} plus a credential UUID, and the broker injects the token. The token never
	 * reaches this process. Resolved lazily (class_exists + the injected container),
	 * mirroring GitHubAppSyncService; when the broker is absent we fail closed rather than fall
	 * back to any token-bearing path.
	 *
	 * @var string
	 */
	private const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	/**
	 * The broker `appId` Buildiq identifies itself with.
	 *
	 * @var string
	 */
	private const APP_ID = 'buildiq';

	/**
	 * The branch the generated tree is pushed to, before the PR is opened against the
	 * repo's default branch.
	 *
	 * There is deliberately no API_BASE const: https://api.github.com is the broker's
	 * host-lock, and the broker is the one place it may be asserted. A service that can
	 * name the host can name a different one.
	 *
	 * @var string
	 */
	private const BOOTSTRAP_BRANCH = 'bootstrap';

	/**
	 * Cap on the failure detail text folded into a thrown exception / log line,
	 * so an oversized GitHub error body cannot bloat `errorMessage`.
	 *
	 * @var int
	 */
	private const MAX_FAILURE_DETAIL_LENGTH = 300;

	/**
	 * The real reason the most recent {@see brokerCall()} returned `null` —
	 * `HTTP {status}: {body excerpt}` for a completed non-2xx response, or
	 * `transport error: {message}` for a caught broker exception. Reset to
	 * `null` at the top of every `brokerCall()`, so a caller reading it
	 * immediately after its own call never sees a stale value from an
	 * earlier, unrelated failure (e.g. `assertRepoAbsent()`'s quiet 404).
	 * Always scrubbed of GitHub PAT-shaped tokens before being set.
	 *
	 * @var string|null
	 */
	private ?string $lastFailureDetail = null;

	/**
	 * Constructor.
	 *
	 * No HTTP client: this service no longer makes outbound calls of its own.
	 *
	 * @param LoggerInterface $logger Logger.
	 * @param ContainerInterface $container DI container, resolves the OpenRegister
	 *                                      broker at call time (never the global server).
	 */
	public function __construct(
		private LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Push the generated tree to a new GitHub repo + open a placeholder PR.
	 *
	 * @param string $jobUuid Export job UUID — correlation key in audit logs.
	 * @param string $treeDir Absolute path to the generated tree on disk.
	 * @param string $credentialId Broker credential UUID for a `github` provider
	 *                             credential. Not a token: this service cannot
	 *                             read the secret behind it.
	 * @param string $org Target GitHub organisation/owner.
	 * @param string $repo Target repository name.
	 * @param string $visibility Repository visibility (`public`|`private`).
	 * @param string|null $actingUserId Credential owner. Required on the background-job
	 *                                  path, where there is no user session for the
	 *                                  broker's ownership guard to read.
	 *
	 * @return array{repoUrl:string,pullRequestUrl:string,commitSha:string} URLs of repo + PR.
	 *
	 * @throws RuntimeException On any GitHub API failure (auth, repo-exists, …).
	 *
	 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
	 */
	public function push(
		string $jobUuid,
		string $treeDir,
		string $credentialId,
		string $org = '',
		string $repo = '',
		string $visibility = 'private',
		?string $actingUserId = null,
	): array {
		// Audit log names only the job + repo — never the credential, never tree contents.
		$this->logger->info(
			'Buildiq GitHub push: creating repository',
			['jobUuid' => $jobUuid, 'org' => $org, 'repo' => $repo]
		);

		if ($this->isBrokerAvailable() === false) {
			// Fail closed. There is deliberately no token-bearing fallback: the whole
			// point of this change is that Buildiq can no longer hold a PAT.
			throw new RuntimeException(
				'GitHub push requires the OpenRegister credential broker, which is not available.'
			);
		}

		if ($credentialId === '') {
			throw new RuntimeException('GitHub push requires a broker credential.');
		}

		if ($org === '' || $repo === '') {
			throw new RuntimeException('GitHub push requires both an organisation and a repository name.');
		}

		if (is_dir($treeDir) === false) {
			throw new RuntimeException('GitHub push: generated tree directory is missing: ' . $treeDir);
		}

		$this->assertRepoAbsent(org: $org, repo: $repo, credentialId: $credentialId, actingUserId: $actingUserId);
		$repoData = $this->createRepo(
			org: $org,
			repo: $repo,
			visibility: $visibility,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		// The created repo reports its own default branch; `auto_init` guarantees one,
		// so there is no separate lookup (and GET /user is not needed at all).
		$defaultBranch = (string)($repoData['default_branch'] ?? 'main');
		$commitSha = $this->pushTree(
			org: $org,
			repo: $repo,
			branch: self::BOOTSTRAP_BRANCH,
			treeDir: $treeDir,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		$prUrl = $this->openPullRequest(
			org: $org,
			repo: $repo,
			fromBranch: self::BOOTSTRAP_BRANCH,
			toBranch: $defaultBranch,
			title: 'chore: bootstrap from Buildiq',
			body: 'This repository was bootstrapped from an Buildiq application export. '
				. 'Review the tree, then run `composer install` and `npm install` locally before merging.',
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		return [
			'repoUrl' => (string)($repoData['html_url'] ?? ('https://github.com/' . $org . '/' . $repo)),
			'pullRequestUrl' => $prUrl,
			'commitSha' => $commitSha,
		];
	}//end push()

	/**
	 * Fail fast when the target repository already exists (REQ-OBEX-007).
	 *
	 * @param string $org Organisation/owner.
	 * @param string $repo Repository name.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential (background context).
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the repo already exists.
	 */
	private function assertRepoAbsent(
		string $org,
		string $repo,
		string $credentialId,
		?string $actingUserId,
	): void {
		// Absent is the desired outcome, so a broker denial/404 here is NOT an error —
		// only a successful 200 means the repo already exists.
		$existing = $this->brokerCall(
			method: 'GET',
			path: '/repos/' . rawurlencode($org) . '/' . rawurlencode($repo),
			body: null,
			credentialId: $credentialId,
			actingUserId: $actingUserId,
			failQuietly: true
		);

		if ($existing !== null) {
			throw new RuntimeException(
				sprintf('Repository %s/%s already exists', $org, $repo)
			);
		}
	}//end assertRepoAbsent()

	/**
	 * Create a new GitHub repository under the given org.
	 *
	 * @param string $org Organisation/owner.
	 * @param string $repo Repository name.
	 * @param string $visibility `public`|`private`.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential (background context).
	 *
	 * @return array<string,mixed> Decoded repo payload.
	 *
	 * @throws RuntimeException On API failure.
	 *
	 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
	 */
	public function createRepo(
		string $org,
		string $repo,
		string $visibility,
		string $credentialId,
		?string $actingUserId = null,
	): array {
		$created = $this->brokerCall(
			method: 'POST',
			path: '/orgs/' . rawurlencode($org) . '/repos',
			body: [
				'name' => $repo,
				'private' => ($visibility === 'private'),
				'auto_init' => true,
				'description' => 'Bootstrapped from Buildiq',
			],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		if ($created === null) {
			throw new RuntimeException('GitHub create-repo failed.' . $this->failureSuffix());
		}

		return $created;
	}//end createRepo()

	/**
	 * Push the generated tree as a single commit on a new branch.
	 *
	 * Walks the on-disk tree, creates a blob per file via the Git Data API,
	 * assembles a tree + commit, and points a new ref at it.
	 *
	 * @param string $org Organisation/owner.
	 * @param string $repo Repository name.
	 * @param string $branch Branch to create (e.g. `bootstrap`).
	 * @param string $treeDir Absolute path to the generated tree.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential (background context).
	 *
	 * @return string Commit SHA.
	 *
	 * @throws RuntimeException On API failure.
	 *
	 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
	 */
	public function pushTree(
		string $org,
		string $repo,
		string $branch,
		string $treeDir,
		string $credentialId,
		?string $actingUserId = null,
	): string {
		$base = '/repos/' . rawurlencode($org) . '/' . rawurlencode($repo);
		$entries = [];

		foreach ($this->walkTree(root: $treeDir) as $relativePath) {
			$contents = (string)file_get_contents($treeDir . '/' . $relativePath);
			$entries[] = [
				'path' => $relativePath,
				'mode' => '100644',
				'type' => 'blob',
				'sha' => $this->createBlob(
					base: $base,
					contents: $contents,
					credentialId: $credentialId,
					actingUserId: $actingUserId
				),
			];
		}

		$tree = $this->postJson(
			path: $base . '/git/trees',
			body: ['tree' => $entries],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		$treeSha = (string)($tree['sha'] ?? '');

		$commit = $this->postJson(
			path: $base . '/git/commits',
			body: [
				'message' => 'chore: bootstrap from Buildiq',
				'tree' => $treeSha,
			],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);
		$commitSha = (string)($commit['sha'] ?? '');

		$this->postJson(
			path: $base . '/git/refs',
			body: [
				'ref' => 'refs/heads/' . $branch,
				'sha' => $commitSha,
			],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		return $commitSha;
	}//end pushTree()

	/**
	 * Open a pull request from one branch to another.
	 *
	 * @param string $org Organisation/owner.
	 * @param string $repo Repository name.
	 * @param string $fromBranch Source branch.
	 * @param string $toBranch Target branch.
	 * @param string $title PR title.
	 * @param string $body PR body.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential (background context).
	 *
	 * @return string PR URL.
	 *
	 * @throws RuntimeException On API failure.
	 *
	 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
	 */
	public function openPullRequest(
		string $org,
		string $repo,
		string $fromBranch,
		string $toBranch,
		string $title,
		string $body,
		string $credentialId,
		?string $actingUserId = null,
	): string {
		$pullRequest = $this->postJson(
			path: '/repos/' . rawurlencode($org) . '/' . rawurlencode($repo) . '/pulls',
			body: [
				'title' => $title,
				'head' => $fromBranch,
				'base' => $toBranch,
				'body' => $body,
			],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		return (string)($pullRequest['html_url'] ?? '');
	}//end openPullRequest()

	/**
	 * Create a base64 blob and return its SHA.
	 *
	 * @param string $base Repo-relative API path prefix.
	 * @param string $contents Raw file contents.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential (background context).
	 *
	 * @return string Blob SHA.
	 */
	private function createBlob(
		string $base,
		string $contents,
		string $credentialId,
		?string $actingUserId,
	): string {
		$blob = $this->postJson(
			path: $base . '/git/blobs',
			body: [
				'content' => base64_encode($contents),
				'encoding' => 'base64',
			],
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		return (string)($blob['sha'] ?? '');
	}//end createBlob()

	/**
	 * POST a JSON body through the broker and decode the response.
	 *
	 * @param string $path GitHub-relative path (host comes from the
	 *                     broker's host-lock, not from us).
	 * @param array<string,mixed> $body Request body.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential (background context).
	 *
	 * @return array<string,mixed> Decoded response payload.
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function postJson(string $path, array $body, string $credentialId, ?string $actingUserId): array {
		$decoded = $this->brokerCall(
			method: 'POST',
			path: $path,
			body: $body,
			credentialId: $credentialId,
			actingUserId: $actingUserId
		);

		if ($decoded === null) {
			throw new RuntimeException(
				'GitHub API call failed: POST ' . $path . $this->failureSuffix()
			);
		}

		return $decoded;
	}//end postJson()

	/**
	 * Route one GitHub call through OpenRegister's credential broker.
	 *
	 * We send only {method, path, body}: the base URL is the broker's host-lock and
	 * the Authorization header is injected there from the vault. This process never
	 * sees the token, so there is nothing here to leak into a log or an exception.
	 *
	 * A non-2xx (including a broker 403 for an unlisted method/path) returns `null`
	 * rather than throwing, because the one caller that needs to tell "absent" from
	 * "error" — assertRepoAbsent() — treats absence as the happy path. Every other
	 * caller turns `null` into a RuntimeException.
	 *
	 * @param string $method HTTP method.
	 * @param string $path GitHub-relative path.
	 * @param array<string,mixed>|null $body Optional JSON body.
	 * @param string $credentialId Broker credential UUID.
	 * @param string|null $actingUserId Owner of the credential (background context).
	 * @param bool $failQuietly Suppress the error log on failure.
	 *
	 * @return array<string,mixed>|null Decoded payload, or null on any non-2xx.
	 */
	private function brokerCall(
		string $method,
		string $path,
		?array $body,
		string $credentialId,
		?string $actingUserId,
		bool $failQuietly = false,
	): ?array {
		$this->lastFailureDetail = null;

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
		} catch (\Throwable $e) {
			// The broker never puts the secret in its exception messages, but this
			// path is also reached for transport errors, so scrub anyway.
			$scrubbed = $this->scrub(message: $e->getMessage());
			$this->lastFailureDetail = 'transport error: ' . $scrubbed;

			if ($failQuietly === false) {
				$this->logger->warning(
					'Buildiq GitHub push: broker call failed for ' . $method . ' ' . $path
					. ': ' . $scrubbed
				);
			}

			return null;
		}//end try

		$status = (int)($response['status'] ?? 0);
		if ($status < 200 || $status >= 300) {
			$this->lastFailureDetail = $this->failureDetailFromStatus(
				status: $status,
				body: (string)($response['body'] ?? '')
			);

			if ($failQuietly === false) {
				$this->logger->warning(
					'Buildiq GitHub push: ' . $method . ' ' . $path . ' returned ' . $this->lastFailureDetail
				);
			}

			return null;
		}

		return $this->decode(body: (string)($response['body'] ?? ''));
	}//end brokerCall()

	/**
	 * Build the `HTTP {status}: {body excerpt}` failure detail from a completed
	 * non-2xx broker response, scrubbing any GitHub PAT-shaped token and
	 * truncating the body to {@see MAX_FAILURE_DETAIL_LENGTH}.
	 *
	 * Pulled out of {@see brokerCall()} so the detail-assembly logic is testable
	 * without a live broker (a unit test hands in a container that resolves
	 * nothing).
	 *
	 * @param int $status The upstream HTTP status code.
	 * @param string $body The raw (unscrubbed) upstream response body.
	 *
	 * @return string The `HTTP {status}` detail, with a scrubbed/truncated body
	 *                excerpt appended when the body is non-empty.
	 */
	private function failureDetailFromStatus(int $status, string $body): string {
		$scrubbedBody = $this->truncate(value: $this->scrub(message: $body));

		$detail = 'HTTP ' . $status;
		if ($scrubbedBody !== '') {
			$detail .= ': ' . $scrubbedBody;
		}

		return $detail;
	}//end failureDetailFromStatus()

	/**
	 * Whether OpenRegister's credential broker is installed.
	 *
	 * @return bool True when the broker class can be resolved.
	 */
	public function isBrokerAvailable(): bool {
		return class_exists(self::BROKER_CLASS) === true;
	}//end isBrokerAvailable()

	/**
	 * Decode a JSON response body into an array.
	 *
	 * @param string $body Raw response body.
	 *
	 * @return array<string,mixed> Decoded payload (empty on non-array).
	 */
	private function decode(string $body): array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end decode()

	/**
	 * Recursively walk a tree, returning sorted relative file paths.
	 *
	 * @param string $root Tree root directory.
	 *
	 * @return array<int,string> Sorted relative file paths.
	 */
	private function walkTree(string $root): array {
		$paths = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $fileInfo) {
			if ($fileInfo->isFile() === false) {
				continue;
			}

			$paths[] = ltrim(str_replace($root, '', $fileInfo->getPathname()), '/');
		}

		sort($paths);
		return $paths;
	}//end walkTree()

	/**
	 * Remove any leaked PAT-shaped tokens from an error message before it is
	 * surfaced into errorMessage / logs (defence in depth for Decision 3).
	 *
	 * @param string $message Raw error message.
	 *
	 * @return string Scrubbed message.
	 */
	private function scrub(string $message): string {
		return (string)preg_replace('/gh[pousr]_[A-Za-z0-9]{20,}/', '[redacted-token]', $message);
	}//end scrub()

	/**
	 * Truncate a string to {@see MAX_FAILURE_DETAIL_LENGTH}, so a large GitHub
	 * error body cannot bloat an exception message or a log line.
	 *
	 * @param string $value The (already-scrubbed) string to cap.
	 *
	 * @return string The capped string, with a trailing ellipsis when cut.
	 */
	private function truncate(string $value): string {
		if (strlen($value) <= self::MAX_FAILURE_DETAIL_LENGTH) {
			return $value;
		}

		return substr($value, 0, self::MAX_FAILURE_DETAIL_LENGTH) . '…';
	}//end truncate()

	/**
	 * The `' — {detail}'` suffix to append to a generic failure message, built
	 * from the most recent {@see brokerCall()} failure — or `''` when no
	 * detail was captured (should not normally happen once `brokerCall()` has
	 * returned `null`, but a caller must never throw on a missing detail).
	 *
	 * @return string
	 */
	private function failureSuffix(): string {
		if ($this->lastFailureDetail === null || $this->lastFailureDetail === '') {
			return '';
		}

		return ' — ' . $this->lastFailureDetail;
	}//end failureSuffix()
}//end class
