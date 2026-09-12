<?php

/**
 * Buildiq ApplicationInsightsService
 *
 * Computes the four KPI scalars + activity timeline rendered by the
 * Application detail-page maintainer dashboard (spec
 * `buildiq-app-detail-overview`, capability `application-insights`).
 *
 * Responsibilities:
 *   - Resolve the Application + ApplicationVersion records (IDOR-safe).
 *   - Enforce the RBAC gate (REQ-OBAI-002 — same shape as
 *     ManifestResolverService): viewer-or-better for production,
 *     editor-or-better for non-production. Nextcloud admins are NOT
 *     auto-granted.
 *   - Walk `manifest.pages[].config.{register,schema}` to derive the
 *     schema-set scoped to the version's per-version register
 *     (`openbuild-{appSlug}-{versionSlug}`, from
 *     ApplicationVersionService::VERSION_REGISTER_PREFIX, which is frozen at
 *     the old prefix on purpose and is what the applicationVersion schema
 *     pattern accepts).
 *   - Fan out four KPI calls + one chart call to OpenRegister mappers /
 *     services and assemble the response payload.
 *
 * Per ADR-031 §Exceptions: cross-table aggregations that fan across
 * schemas are imperative work, not schema-declarative. The RBAC gate is
 * a cross-cutting service concern (mirrors ManifestResolverService).
 *
 * Defensive `method_exists` guards on the OR `AuditTrailMapper` are used
 * for `getDistinctActorCount` (delivered by
 * `openregister-distinct-actor-aggregation`). When that floor change has
 * not yet landed, the Active-users KPI degrades to `0` rather than
 * 500-ing the whole endpoint.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-16
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-17
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-18
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-19
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-20
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use DateTime;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Insights aggregation for an ApplicationVersion's per-version register.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ApplicationInsightsService {
	/**
	 * Allowed `window` query parameter values (REQ-OBAI-001).
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_WINDOWS = ['7d', '30d', '90d'];

	/**
	 * How long a computed insights payload is memoised (seconds). The
	 * aggregation is expensive for large apps; 10 minutes balances freshness
	 * against recompute cost.
	 *
	 * @var int
	 */
	private const CACHE_TTL_SECONDS = 600;

	/**
	 * Window-to-hours mapping (REQ-OBAI-004).
	 *
	 * @var array<string, int>
	 */
	private const WINDOW_HOURS = [
		'7d' => 168,
		'30d' => 720,
		'90d' => 2160,
	];

	/**
	 * Schema slug for Application records.
	 *
	 * @var string
	 */
	private const APPLICATION_SCHEMA = 'built-app';

	/**
	 * Schema slug for ApplicationVersion records.
	 *
	 * @var string
	 */
	private const APPLICATION_VERSION_SCHEMA = 'applicationVersion';

	/**
	 * Shared register slug carrying Application + ApplicationVersion rows.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'buildiq';

	/**
	 * Constructor.
	 *
	 * Per ADR-022 — no app-local DB access; everything flows through
	 * OpenRegister abstractions.
	 *
	 * @param ObjectServiceInterface $objectService OR object surface
	 * @param AuditTrailMapper $auditTrailMapper Audit-trail aggregations (chart + actors + counts)
	 * @param SchemaMapper $schemaMapper Schema slug-to-integer-ID resolver
	 * @param RegisterMapper $registerMapper Register lookup (installed-app footprint for hybrid apps)
	 * @param ICacheFactory $cacheFactory Distributed-cache factory (memoises computed payloads)
	 * @param LoggerInterface $logger PSR logger
	 * @param PermissionResolver|null $permissionResolver Shared role/group matcher (group-aware insights authz, L9)
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly RegisterMapper $registerMapper,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
		private readonly ?PermissionResolver $permissionResolver = null,
	) {
		$this->cache = $cacheFactory->createDistributed('buildiq_insights');
	}//end __construct()

	/**
	 * Distributed cache for computed insights payloads.
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Resolve + authorise the (Application, Version, caller) tuple.
	 *
	 * Returns `[$application, $version]` on success, or `null` on any 404
	 * mode (unknown app, unknown version, IDOR mismatch, RBAC denial).
	 *
	 * Public so the controller can call it explicitly as a guard step —
	 * hydra gate-7 (no-admin-idor) expects a `require*` / `authorize*` /
	 * `ensure*` / `check*` call in the controller method body alongside
	 * the `#[NoAdminRequired]` annotation, even when the same logic also
	 * lives inside the service layer.
	 *
	 * @param string $appUuid Application UUID.
	 * @param string $versionUuid ApplicationVersion UUID.
	 * @param IUser|null $caller The authenticated user, or null for unauthenticated.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-17
	 */
	public function requireAuthorisedCaller(
		string $appUuid,
		string $versionUuid,
		?IUser $caller,
	): ?array {
		$application = $this->loadApplication(uuid: $appUuid);
		if ($application === null) {
			return null;
		}

		$version = $this->loadVersion(uuid: $versionUuid);
		if ($version === null) {
			return null;
		}

		if ($this->versionBelongsToApplication(version: $version, applicationUuid: $appUuid) === false) {
			return null;
		}

		if ($this->isAuthorised(application: $application, version: $version, caller: $caller) === false) {
			return null;
		}

		return [$application, $version];
	}//end requireAuthorisedCaller()

	/**
	 * Compute the insights payload for a given Application + Version + window.
	 *
	 * Returns `null` for any failure mode the caller maps to 404 (IDOR-safe
	 * — no existence leak):
	 *   - Unknown `appUuid`
	 *   - Unknown `versionUuid`
	 *   - Version whose `application` relation does not point at `appUuid`
	 *   - RBAC failure (viewer-on-non-production, no role at all on production)
	 *
	 * Returns `null` for an invalid `window` value (caller is expected to
	 * pre-validate at the controller layer; the defensive check here keeps
	 * the service safe in isolation).
	 *
	 * The per-version register fallback takes its prefix from
	 * {@see ApplicationVersionService::VERSION_REGISTER_PREFIX}. This method
	 * used to type it as `sprintf('buildiq-%s-%s', ...)`, and that named a
	 * register no instance can hold: every writer of a per-version register
	 * uses the constant, which is `openbuild-` and is frozen there on purpose,
	 * and the applicationVersion schema pins `"pattern": "^openbuild-..."` so a
	 * `buildiq-` register is rejected at write time. The read then matched
	 * nothing and the panel showed zero objects, zero files and zero audit
	 * events, which is byte for byte what a real but empty version looks like.
	 * {@see \OCA\Buildiq\Tests\Unit\Support\RegisterSlugPinTest} guards it now.
	 *
	 * @param string $appUuid Application UUID (path parameter).
	 * @param string $versionUuid ApplicationVersion UUID (path parameter).
	 * @param string $window Window string — one of `7d`, `30d`, `90d`.
	 * @param IUser|null $caller The authenticated user, or null for unauthenticated.
	 *
	 * @return array<string, mixed>|null Insights payload `{kpis, activity}` or null on 404.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-16
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-19
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-20
	 */
	public function computeInsights(
		string $appUuid,
		string $versionUuid,
		string $window,
		?IUser $caller,
	): ?array {
		if (in_array($window, self::ALLOWED_WINDOWS, true) === false) {
			return null;
		}

		try {
			$resolved = $this->requireAuthorisedCaller(
				appUuid: $appUuid,
				versionUuid: $versionUuid,
				caller: $caller
			);
			if ($resolved === null) {
				return null;
			}

			[$application, $version] = $resolved;

			// These aggregations fan out across every schema/register of the
			// (possibly very large) installed app — tens of seconds for a big
			// hybrid app. The PAYLOAD is identical for all callers (RBAC is
			// already enforced above), so memoise it in the distributed cache
			// keyed by (app, version, window). First viewer pays the cost (the
			// dashboard shows a spinner); everyone else gets it instantly until
			// the short TTL lapses.
			$cacheKey = sprintf('payload_%s_%s_%s', $appUuid, $versionUuid, $window);
			$cached = $this->cache->get($cacheKey);
			if (is_array($cached) === true) {
				return $cached;
			}

			$appSlug = (string)($application['slug'] ?? '');
			$hours = self::WINDOW_HOURS[$window];

			// A hybrid app mirrors a live installed Nextcloud app — its KPIs
			// should reflect that installed app's real footprint (objects /
			// audit / files across the registers OpenRegister associates with
			// the app via `register.application`), NOT the override version's
			// per-version register (which is usually empty). See REQ unified-app.
			$isHybrid = (($application['appType'] ?? 'virtual') === 'hybrid');
			if ($isHybrid === true) {
				$payload = $this->computeHybridInsights(appSlug: $appSlug, hours: $hours);
				$this->cache->set($cacheKey, $payload, self::CACHE_TTL_SECONDS);
				return $payload;
			}

			// Prefer the version's REAL register; fall back to the per-version
			// naming convention only when the version carries none. The prefix
			// comes from ApplicationVersionService and is never typed here: see
			// this method's docblock.
			$versionSlug = (string)($version['slug'] ?? '');
			$registerSlug = (string)($version['register'] ?? '');
			if ($registerSlug === '') {
				$registerSlug = ApplicationVersionService::VERSION_REGISTER_PREFIX . $appSlug . '-' . $versionSlug;
			}

			$manifest = $this->extractManifest(version: $version);
			$schemaSlugs = $this->deriveSchemaIds(manifest: $manifest, registerSlug: $registerSlug);
			$schemaIds = $this->resolveSchemaSlugsToIntIds(schemaSlugs: $schemaSlugs);

			$kpis = [
				'activeUsers' => $this->safeDistinctActorCount(schemaIds: $schemaIds, hours: $hours),
				'objectCount' => $this->countObjects(schemaIds: $schemaIds, registerSlug: $registerSlug),
				'filesCount' => $this->countAttachedFiles(registerSlug: $registerSlug, schemaIds: $schemaIds),
				'auditEventCount' => $this->countAuditEvents(schemaIds: $schemaIds, hours: $hours),
			];

			$activity = $this->buildActivityTimeline(schemaIds: $schemaIds, hours: $hours, registerSlug: $registerSlug);

			$payload = [
				'kpis' => $kpis,
				'activity' => $activity,
			];
			$this->cache->set($cacheKey, $payload, self::CACHE_TTL_SECONDS);
			return $payload;
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: ApplicationInsightsService::computeInsights failed: {message}',
				['message' => $e->getMessage(), 'exception' => $e]
			);
			return null;
		}//end try
	}//end computeInsights()

	/**
	 * Compute insights for a hybrid app from its installed-app footprint.
	 *
	 * A hybrid app's slug equals the installed Nextcloud app id. OpenRegister
	 * associates registers with an app via `register.application`, so we gather
	 * every register where `application === appSlug`, union their schema-sets and
	 * run the same KPI aggregations the virtual path uses — but across the real,
	 * live registers instead of the (usually empty) per-version override register.
	 *
	 * @param string $appSlug The hybrid app slug (== installed app id).
	 * @param int $hours Window hours.
	 *
	 * @return array<string, mixed> Insights payload `{kpis, activity}`.
	 *
	 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
	 */
	private function computeHybridInsights(string $appSlug, int $hours): array {
		$registers = $this->resolveInstalledAppRegisters(appSlug: $appSlug);
		$allSchemaIds = [];
		$registerIds = [];
		$objectCount = 0;

		foreach ($registers as $register) {
			$registerIds[] = $register['registerId'];
			$schemaIds = $register['schemaIds'];
			if (empty($schemaIds) === true) {
				continue;
			}

			// Count by register ID (string-numeric) — slugs are NOT unique
			// across registers (e.g. two "pipelinq" registers), so setRegister
			// by slug would resolve ambiguously; the ID is unambiguous.
			$objectCount += $this->countObjects(schemaIds: $schemaIds, registerSlug: (string)$register['registerId']);
			foreach ($schemaIds as $schemaId) {
				$allSchemaIds[$schemaId] = true;
			}
		}

		$schemaIds = array_keys($allSchemaIds);

		// Audit count + activity timeline are computed PER REGISTER (one
		// getActionChartData call per register, not per schema). An installed
		// app can span dozens of schemas (pipelinq ≈ 58), so the per-schema
		// fan-out the virtual path uses would be ~2 audit queries × 58 — the
		// dominant cost. Per-register collapses that to a handful of queries.
		[$auditCount, $activity] = $this->auditByRegisters(registerIds: $registerIds, hours: $hours);

		$kpis = [
			'activeUsers' => $this->safeDistinctActorCount(schemaIds: $schemaIds, hours: $hours),
			'objectCount' => $objectCount,
			'filesCount' => $this->countAttachedFiles(registerSlug: '', schemaIds: $schemaIds),
			'auditEventCount' => $auditCount,
		];

		return [
			'kpis' => $kpis,
			'activity' => $activity,
		];
	}//end computeHybridInsights()

	/**
	 * Audit-event count + activity timeline for a set of registers, computed
	 * with ONE `getActionChartData` call per register (registerId filter) rather
	 * than per schema. Returns `[auditCount, timeline]`.
	 *
	 * @param array<int, int> $registerIds Register IDs to aggregate over.
	 * @param int $hours Window hours.
	 *
	 * @return array{0: int, 1: array<int, array{timestamp: string, eventCount: int}>}
	 *
	 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
	 */
	private function auditByRegisters(array $registerIds, int $hours): array {
		if (empty($registerIds) === true) {
			return [0, []];
		}

		try {
			$from = new DateTime(sprintf('-%d hours', $hours));
			$till = new DateTime();

			$count = 0;
			$buckets = [];
			foreach ($registerIds as $registerId) {
				$chart = $this->auditTrailMapper->getActionChartData(
					from: $from,
					till: $till,
					registerId: (int)$registerId,
					schemaId: null
				);
				$count += $this->sumChartSeries(chart: $chart);
				$this->mergeChartIntoBuckets(chart: $chart, buckets: $buckets);
			}

			ksort($buckets);

			$timeline = [];
			foreach ($buckets as $date => $eventCount) {
				$timeline[] = [
					'timestamp' => sprintf('%sT00:00:00Z', $date),
					'eventCount' => (int)$eventCount,
				];
			}

			return [$count, $timeline];
		} catch (Throwable $e) {
			$this->logger->debug(
				'Buildiq: auditByRegisters failed: {message}',
				['message' => $e->getMessage()]
			);
			return [0, []];
		}//end try
	}//end auditByRegisters()

	/**
	 * Resolve the OpenRegister registers belonging to an installed app.
	 *
	 * Returns one entry per register where `register.application === $appSlug`,
	 * each `{registerId, schemaIds}` with integer schema IDs. Empty array on any
	 * failure (degrades the hybrid KPIs to 0 rather than 500-ing).
	 *
	 * @param string $appSlug The installed app id (hybrid app slug).
	 *
	 * @return array<int, array{registerId: int, schemaIds: array<int, int>}>
	 *
	 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
	 */
	private function resolveInstalledAppRegisters(string $appSlug): array {
		if ($appSlug === '') {
			return [];
		}

		try {
			$registers = $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Buildiq: resolveInstalledAppRegisters findAll failed: {message}',
				['message' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach ($registers as $register) {
			// NB: getApplication() is a magic getter (Entity __call), so a
			// method_exists() guard would skip every register — don't add one.
			if (is_object($register) === false) {
				continue;
			}

			if ((string)$register->getApplication() !== $appSlug) {
				continue;
			}

			$schemaIds = [];
			foreach ((array)$register->getSchemas() as $schemaId) {
				if (is_numeric($schemaId) === true) {
					$schemaIds[] = (int)$schemaId;
				}
			}

			$out[] = [
				'registerId' => (int)$register->getId(),
				'schemaIds' => array_values(array_unique($schemaIds)),
			];
		}//end foreach

		return $out;
	}//end resolveInstalledAppRegisters()

	/**
	 * Derive the schema-set for the version per REQ-OBAI-003.
	 *
	 * Walks `manifest.pages[].config.{register,schema}`, filters to the
	 * version's own per-version register, and uniques by schema id.
	 *
	 * Public so it can be unit-tested in isolation.
	 *
	 * @param array<string, mixed>|null $manifest The version's manifest payload (null tolerated).
	 * @param string $registerSlug The version's per-version register slug.
	 *
	 * @return array<int, string> Unique schema IDs (string form — OR stores audit schema column as VARCHAR).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-18
	 */
	public function deriveSchemaIds(?array $manifest, string $registerSlug): array {
		if ($manifest === null) {
			return [];
		}

		$pages = ($manifest['pages'] ?? null);
		if (is_array($pages) === false) {
			return [];
		}

		$schemaIds = [];
		foreach ($pages as $page) {
			$schemaId = $this->extractSchemaIdForRegister(page: $page, registerSlug: $registerSlug);
			if ($schemaId === null) {
				continue;
			}

			$schemaIds[$schemaId] = true;
		}//end foreach

		return array_keys($schemaIds);
	}//end deriveSchemaIds()

	/**
	 * Resolve an array of schema slugs to their integer database IDs via
	 * SchemaMapper::find(). Slugs that cannot be resolved (not found, OR
	 * not available) are silently skipped so a single bad slug in the
	 * manifest does not zero-out all KPIs.
	 *
	 * @param array<int, string> $schemaSlugs Schema slugs from the manifest.
	 *
	 * @return array<int, int> Integer schema IDs suitable for AuditTrailMapper queries.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-18
	 */
	private function resolveSchemaSlugsToIntIds(array $schemaSlugs): array {
		$intIds = [];
		foreach ($schemaSlugs as $slug) {
			try {
				$intId = $this->schemaMapper->find($slug, _multitenancy: false)->getId();
				if ($intId !== null) {
					$intIds[] = (int)$intId;
				}
			} catch (Throwable $e) {
				$this->logger->debug(
					'Buildiq: could not resolve schema slug "{slug}" to integer ID: {message}',
					['slug' => $slug, 'message' => $e->getMessage()]
				);
			}
		}//end foreach

		return array_values(array_unique($intIds));
	}//end resolveSchemaSlugsToIntIds()

	/**
	 * Extract a schema ID from a manifest page entry IF the entry's
	 * `config.register` matches the supplied register slug AND
	 * `config.schema` is a non-empty string. Returns null otherwise.
	 *
	 * Split out from {@see deriveSchemaIds()} to keep that method below
	 * PHPMD's cyclomatic-complexity threshold.
	 *
	 * @param mixed $page The manifest page entry (or non-array junk).
	 * @param string $registerSlug The version's per-version register slug.
	 *
	 * @return string|null The schema ID, or null when the page does not match.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-18
	 */
	private function extractSchemaIdForRegister(mixed $page, string $registerSlug): ?string {
		if (is_array($page) === false) {
			return null;
		}

		$config = ($page['config'] ?? null);
		if (is_array($config) === false) {
			return null;
		}

		$pageRegister = ($config['register'] ?? null);
		if (is_string($pageRegister) === false || $pageRegister !== $registerSlug) {
			return null;
		}

		$pageSchema = ($config['schema'] ?? null);
		if (is_string($pageSchema) === false || $pageSchema === '') {
			return null;
		}

		return $pageSchema;
	}//end extractSchemaIdForRegister()

	/**
	 * Apply the RBAC gate per REQ-OBAI-002.
	 *
	 * Production version: viewer-or-better required.
	 * Non-production: editor-or-better required.
	 * Nextcloud admins are NOT auto-granted.
	 *
	 * @param array<string, mixed> $application The Application record.
	 * @param array<string, mixed> $version The ApplicationVersion record.
	 * @param IUser|null $caller The authenticated user.
	 *
	 * @return bool True when authorised, false otherwise.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-17
	 */
	private function isAuthorised(array $application, array $version, ?IUser $caller): bool {
		if ($caller === null) {
			return false;
		}

		// Hybrid apps mirror an installed Nextcloud app and are not created with
		// the per-app permission buckets virtual apps carry. Their insights are
		// aggregate counts of the installed app (which has its own RBAC on the
		// underlying data), surfaced on the Buildiq detail page that the caller
		// already reached — so gate on an authenticated caller rather than the
		// (absent) per-app role buckets.
		if ((($application['appType'] ?? 'virtual') === 'hybrid')) {
			return true;
		}

		$prodUuid = $this->extractProductionVersionUuid(application: $application);
		$resolvedUuid = (string)($version['uuid'] ?? $version['id'] ?? '');
		$isProduction = $prodUuid !== '' && $resolvedUuid === $prodUuid;

		$permissions = ($application['permissions'] ?? []);
		if (is_array($permissions) === false) {
			return false;
		}

		if ($isProduction === true) {
			return $this->callerInAnyRole(
				permissions: $permissions,
				caller: $caller,
				roles: ['owners', 'editors', 'viewers']
			);
		}

		return $this->callerInAnyRole(
			permissions: $permissions,
			caller: $caller,
			roles: ['owners', 'editors']
		);
	}//end isAuthorised()

	/**
	 * Check whether the caller appears in any of the named permission roles.
	 *
	 * Matches both `user:<uid>` and bare `<uid>` entries for backwards-compat
	 * with pre-RBAC-canonicalisation manifests (mirrors VersionPromotionService).
	 *
	 * @param array<string, mixed> $permissions The Application's permissions block.
	 * @param IUser $caller The authenticated caller.
	 * @param array<int, string> $roles Roles to check (e.g. ['owners', 'editors']).
	 *
	 * @return bool True when the caller is found in any of the listed buckets.
	 */
	private function callerInAnyRole(array $permissions, IUser $caller, array $roles): bool {
		// Delegate to the shared PermissionResolver so insights authorization is
		// consistent with every other guard — including `group:` principals,
		// which the previous user-only matcher ignored (fail-closed) —
		// (harden-rules-authz-and-audit-parity, L9). No admin bypass: insights
		// access still requires an explicit role match.
		if ($this->permissionResolver !== null) {
			return $this->permissionResolver->matchesCaller(
				permissions: $permissions,
				caller: $caller,
				userGroups: $this->permissionResolver->resolveUserGroups($caller),
				allowAdminBypass: false,
				roles: $roles
			);
		}

		// Fallback (no resolver injected): user-principal match only.
		$callerUid = $caller->getUID();
		foreach ($roles as $role) {
			$bucket = ($permissions[$role] ?? []);
			if (is_array($bucket) === false) {
				continue;
			}

			foreach ($bucket as $principal) {
				if (is_string($principal) === false || $principal === '') {
					continue;
				}

				if ($principal === 'user:' . $callerUid || $principal === $callerUid) {
					return true;
				}
			}
		}

		return false;
	}//end callerInAnyRole()

	/**
	 * Load the Application record by UUID via OR's ObjectService.
	 *
	 * @param string $uuid Application UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadApplication(string $uuid): ?array {
		try {
			$entity = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER_SLUG,
				schema: self::APPLICATION_SCHEMA
			);

			if ($entity === null) {
				return null;
			}

			return $this->normaliseObject(object: $entity);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Buildiq: ApplicationInsightsService::loadApplication failed for uuid={uuid}: {message}',
				['uuid' => $uuid, 'message' => $e->getMessage()]
			);
			return null;
		}
	}//end loadApplication()

	/**
	 * Load the ApplicationVersion record by UUID via OR's ObjectService.
	 *
	 * @param string $uuid ApplicationVersion UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadVersion(string $uuid): ?array {
		try {
			$entity = $this->objectService->find(
				id: $uuid,
				register: self::REGISTER_SLUG,
				schema: self::APPLICATION_VERSION_SCHEMA
			);

			if ($entity === null) {
				return null;
			}

			return $this->normaliseObject(object: $entity);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Buildiq: ApplicationInsightsService::loadVersion failed for uuid={uuid}: {message}',
				['uuid' => $uuid, 'message' => $e->getMessage()]
			);
			return null;
		}
	}//end loadVersion()

	/**
	 * Verify an ApplicationVersion's `application` relation points at the
	 * expected Application UUID (IDOR guard).
	 *
	 * @param array<string, mixed> $version The version record.
	 * @param string $applicationUuid The expected parent UUID.
	 *
	 * @return bool
	 */
	private function versionBelongsToApplication(array $version, string $applicationUuid): bool {
		$relation = ($version['application'] ?? null);

		if (is_string($relation) === true) {
			return $relation === $applicationUuid;
		}

		if (is_array($relation) === true) {
			$relUuid = (string)($relation['uuid'] ?? $relation['id'] ?? '');
			return $relUuid === $applicationUuid;
		}

		return false;
	}//end versionBelongsToApplication()

	/**
	 * Extract the productionVersion UUID from an Application record.
	 *
	 * @param array<string, mixed> $application Application data.
	 *
	 * @return string The UUID, empty string when not determinable.
	 */
	private function extractProductionVersionUuid(array $application): string {
		$productionVersion = ($application['productionVersion'] ?? null);

		if (is_string($productionVersion) === true) {
			return $productionVersion;
		}

		if (is_array($productionVersion) === true) {
			return (string)($productionVersion['uuid'] ?? $productionVersion['id'] ?? '');
		}

		return '';
	}//end extractProductionVersionUuid()

	/**
	 * Pull the manifest payload off an ApplicationVersion record.
	 *
	 * @param array<string, mixed> $version Version record.
	 *
	 * @return array<string, mixed>|null
	 */
	private function extractManifest(array $version): ?array {
		$manifest = ($version['manifest'] ?? null);
		if (is_array($manifest) === true) {
			return $manifest;
		}

		return null;
	}//end extractManifest()

	/**
	 * Active-users KPI: distinct actor UIDs in audit-trail rows scoped to
	 * the schema-set within the window (REQ-OBAI-004).
	 *
	 * Defensively `method_exists` guarded so the controller keeps a 200
	 * response (with `activeUsers: 0`) when running against an OR floor
	 * that has not yet landed the `openregister-distinct-actor-aggregation`
	 * change.
	 *
	 * @param array<int, int> $schemaIds Integer schema IDs.
	 * @param int $hours Window hours.
	 *
	 * @return int Distinct actor count, or 0 when the aggregation API is unavailable.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-19
	 */
	private function safeDistinctActorCount(array $schemaIds, int $hours): int {
		if (empty($schemaIds) === true) {
			return 0;
		}

		if (method_exists($this->auditTrailMapper, 'getDistinctActorCount') === false) {
			$this->logger->debug(
				'Buildiq: getDistinctActorCount not available on AuditTrailMapper — '
				. 'degrade to 0 (depends on openregister-distinct-actor-aggregation)'
			);
			return 0;
		}

		try {
			return (int)$this->auditTrailMapper->getDistinctActorCount($schemaIds, $hours);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: getDistinctActorCount failed: {message}',
				['message' => $e->getMessage()]
			);
			return 0;
		}
	}//end safeDistinctActorCount()

	/**
	 * Object-count KPI: sum of `count()` across each schema in the
	 * schema-set (REQ-OBAI-004).
	 *
	 * Per OR's ObjectService::count() signature, we pass register +
	 * schema via the config array. Schema-set may be empty (returns 0).
	 *
	 * @param array<int, int> $schemaIds Integer schema IDs.
	 * @param string $registerSlug The version's register slug.
	 *
	 * @return int Total object count across the schema-set.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-19
	 */
	private function countObjects(array $schemaIds, string $registerSlug): int {
		if (empty($schemaIds) === true) {
			return 0;
		}

		$total = 0;
		foreach ($schemaIds as $schemaId) {
			try {
				$this->objectService->setRegister($registerSlug);
				$this->objectService->setSchema($schemaId);

				$total += (int)$this->objectService->count();
			} catch (Throwable $e) {
				// Per-schema failure should not kill the aggregate — log and continue.
				$this->logger->debug(
					'Buildiq: count for schema={schemaId} on register={register} failed: {message}',
					['schemaId' => $schemaId, 'register' => $registerSlug, 'message' => $e->getMessage()]
				);
				continue;
			}
		}

		return $total;
	}//end countObjects()

	/**
	 * Files-count KPI: count of OR-attached files across all objects in
	 * the version's register (REQ-OBAI-004 v1 proxy for storage).
	 *
	 * No first-class OR aggregation exists today; we walk OR's audit
	 * trail for `file.attach` actions on the schema-set as a defensive
	 * fallback. The result is a v1 proxy — when the canonical
	 * `FileService::countAttachedFilesForRegister` lands we should swap
	 * the implementation in place without changing the spec contract.
	 *
	 * Returns 0 when the schema-set is empty.
	 *
	 * @param string $registerSlug The version's register slug (reserved for future use).
	 * @param array<int, int> $schemaIds Integer schema IDs.
	 *
	 * @return int File count.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-19
	 */
	private function countAttachedFiles(string $registerSlug, array $schemaIds): int {
		if (empty($schemaIds) === true) {
			return 0;
		}

		if (method_exists($this->auditTrailMapper, 'getStatisticsGroupedBySchema') === false) {
			return 0;
		}

		try {
			$stats = $this->auditTrailMapper->getStatisticsGroupedBySchema($schemaIds);

			$total = 0;
			foreach ($stats as $row) {
				if (is_array($row) === false) {
					continue;
				}

				$total += (int)($row['size'] ?? 0);
			}

			return $total;
		} catch (Throwable $e) {
			$this->logger->debug(
				'Buildiq: countAttachedFiles fallback failed: {message}',
				['message' => $e->getMessage()]
			);
			return 0;
		}
	}//end countAttachedFiles()

	/**
	 * Audit-events KPI: total audit-trail rows scoped to the schema-set
	 * within the window (REQ-OBAI-004).
	 *
	 * Uses `getActionChartData` summed across actions when a dedicated
	 * `countByRegisterAndWindow` is unavailable on the OR floor (today
	 * it is unavailable; this method becomes a one-liner when it lands).
	 *
	 * @param array<int, int> $schemaIds Integer schema IDs.
	 * @param int $hours Window hours.
	 *
	 * @return int Audit-event count.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-19
	 */
	private function countAuditEvents(array $schemaIds, int $hours): int {
		if (empty($schemaIds) === true) {
			return 0;
		}

		if (method_exists($this->auditTrailMapper, 'countByRegisterAndWindow') === true) {
			try {
				return (int)$this->auditTrailMapper->countByRegisterAndWindow($schemaIds, $hours);
			} catch (Throwable $e) {
				$this->logger->debug(
					'Buildiq: countByRegisterAndWindow failed: {message}',
					['message' => $e->getMessage()]
				);
				return 0;
			}
		}

		// Fallback: sum chart rows.
		try {
			$from = new DateTime(sprintf('-%d hours', $hours));
			$till = new DateTime();

			$total = 0;
			foreach ($schemaIds as $schemaId) {
				$chart = $this->auditTrailMapper->getActionChartData(
					from: $from,
					till: $till,
					registerId: null,
					schemaId: $schemaId
				);
				$total += $this->sumChartSeries(chart: $chart);
			}

			return $total;
		} catch (Throwable $e) {
			$this->logger->debug(
				'Buildiq: audit-event fallback failed: {message}',
				['message' => $e->getMessage()]
			);
			return 0;
		}//end try
	}//end countAuditEvents()

	/**
	 * Sum every numeric data point in every series of a getActionChartData
	 * payload. Defensively typed — accepts arbitrary input and returns 0
	 * when any expected key is missing.
	 *
	 * Split out from {@see countAuditEvents()} to keep that method below
	 * PHPMD's cyclomatic-complexity threshold.
	 *
	 * @param mixed $chart The chart payload.
	 *
	 * @return int
	 */
	private function sumChartSeries(mixed $chart): int {
		if (is_array($chart) === false) {
			return 0;
		}

		$series = ($chart['series'] ?? []);
		if (is_array($series) === false) {
			return 0;
		}

		$total = 0;
		foreach ($series as $seriesEntry) {
			if (is_array($seriesEntry) === false) {
				continue;
			}

			$data = ($seriesEntry['data'] ?? []);
			if (is_array($data) === false) {
				continue;
			}

			foreach ($data as $count) {
				$total += (int)$count;
			}
		}

		return $total;
	}//end sumChartSeries()

	/**
	 * Activity timeline (REQ-OBAI-005): one bucket per (date, total-events)
	 * pair sourced from `AuditTrailMapper::getActionChartData`.
	 *
	 * Returns an empty array when the schema-set is empty.
	 *
	 * @param array<int, int> $schemaIds Integer schema IDs.
	 * @param int $hours Window hours.
	 * @param string $registerSlug The register slug (reserved for future use).
	 *
	 * @return array<int, array{timestamp: string, eventCount: int}>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @psalm-suppress UnusedParam
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-20
	 */
	private function buildActivityTimeline(array $schemaIds, int $hours, string $registerSlug): array {
		if (empty($schemaIds) === true) {
			return [];
		}

		try {
			$from = new DateTime(sprintf('-%d hours', $hours));
			$till = new DateTime();

			$merged = [];
			foreach ($schemaIds as $schemaId) {
				$chart = $this->auditTrailMapper->getActionChartData(
					$from,
					$till,
					null,
					$schemaId
				);

				$this->mergeChartIntoBuckets(chart: $chart, buckets: $merged);
			}

			ksort($merged);

			$timeline = [];
			foreach ($merged as $date => $count) {
				$timeline[] = [
					'timestamp' => sprintf('%sT00:00:00Z', $date),
					'eventCount' => (int)$count,
				];
			}

			return $timeline;
		} catch (Throwable $e) {
			$this->logger->debug(
				'Buildiq: buildActivityTimeline failed: {message}',
				['message' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end buildActivityTimeline()

	/**
	 * Merge one `getActionChartData` payload's series rows into the
	 * accumulating date-keyed bucket map.
	 *
	 * @param mixed $chart The chart payload (or anything else —
	 *                     defensively typed).
	 * @param array<string, int> $buckets Accumulator: date string → total event
	 *                                    count.
	 *
	 * @return void
	 */
	private function mergeChartIntoBuckets(mixed $chart, array &$buckets): void {
		if (is_array($chart) === false) {
			return;
		}

		$labels = ($chart['labels'] ?? []);
		$series = ($chart['series'] ?? []);
		if (is_array($labels) === false || is_array($series) === false) {
			return;
		}

		foreach ($series as $seriesEntry) {
			$this->mergeSeriesData(seriesEntry: $seriesEntry, labels: $labels, buckets: $buckets);
		}
	}//end mergeChartIntoBuckets()

	/**
	 * Add one chart-series' `data[]` rows into the accumulating bucket map.
	 *
	 * Split out from {@see mergeChartIntoBuckets()} to keep that method
	 * below PHPMD's cyclomatic-complexity threshold.
	 *
	 * @param mixed $seriesEntry The series entry (or non-array junk).
	 * @param array<int, mixed> $labels Label list parallel to series data.
	 * @param array<string, int> $buckets Accumulator (mutated by reference).
	 *
	 * @return void
	 */
	private function mergeSeriesData(mixed $seriesEntry, array $labels, array &$buckets): void {
		if (is_array($seriesEntry) === false) {
			return;
		}

		$data = ($seriesEntry['data'] ?? []);
		if (is_array($data) === false) {
			return;
		}

		foreach ($data as $idx => $count) {
			$label = ($labels[$idx] ?? null);
			if (is_string($label) === false || $label === '') {
				continue;
			}

			$buckets[$label] = ($buckets[$label] ?? 0) + (int)$count;
		}
	}//end mergeSeriesData()

	/**
	 * Coerce an OR result entry (ObjectEntity or array) to a plain assoc array.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string, mixed>
	 */
	private function normaliseObject(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normaliseObject()
}//end class
