<?php

/**
 * Buildiq Applications Controller
 *
 * Serves the per-virtual-app manifest endpoint, the RBAC-filtered list
 * endpoint used by the editor (REQ-OBRBAC-002 / REQ-OBR-007), the
 * manifest-diff endpoint (buildiq-versioning REQ-OBV-005) and the
 * clone-from-template action (buildiq-templates-marketplace
 * REQ-OBTC-004 / REQ-OBTC-005). Per design.md Decision 6 this is the
 * single app-local HTTP surface; `listMine` exists because OR's
 * schema-level read rule is a coarse group-ACL (not a row-level filter on
 * the Application's `permissions` block) so the list MUST be filtered
 * server-side here, and `createFromTemplate` is the thin-glue clone action
 * (ADR-032) that provisions a per-app `buildiq-{slug}` register and
 * deep-copies the template's companion schemas into it (hybrid register
 * model).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-45
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-46
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-47
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-48
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-49
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-50
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-51
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-55
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-56
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-58
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-69
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-70
 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-the-runtime-must-inject-the-current-user-s-group-context
 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for the Buildiq manifest, list, diff and clone-from-template endpoints.
 */
class ApplicationsController extends Controller {
	/**
	 * Nextcloud admin group identifier used as the bypass anchor and the
	 * fallback owner per design.md Decision 5 of buildiq-rbac.
	 */
	private const ADMIN_GROUP = 'admin';

	/**
	 * Audit-event identifier emitted to the OR audit trail when an admin
	 * bypasses the per-Application permissions check (REQ-OBRBAC-006).
	 */
	private const EVENT_ADMIN_BYPASS = 'rbac.admin_bypass';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request
	 * @param LoggerInterface $logger PSR logger for diagnostics
	 * @param ObjectServiceInterface $objectService OpenRegister object service (hard dep via info.xml)
	 * @param RegisterMapper $registerMapper Resolves slugs/UUIDs to numeric register IDs
	 * @param SchemaMapper $schemaMapper Resolves slugs/UUIDs to numeric schema IDs
	 * @param IUserSession $userSession Current Nextcloud user session
	 * @param IGroupManager $groupManager Group membership resolver
	 * @param ManifestResolverService $manifestResolver Version-aware manifest resolver (REQ-OBVR-002)
	 * @param PermissionResolver $permissionResolver Shared permission-grammar resolver (H1/H2 fix)
	 * @param AppChannelApplier $channelApplier Applies the v2 repo channels (apply-v2-channels)
	 * @param AuditTrailMapper|null $auditTrailMapper Optional OR audit-trail writer (null until OR loaded)
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ManifestResolverService $manifestResolver,
		private readonly PermissionResolver $permissionResolver,
		private readonly AppChannelApplier $channelApplier,
		private readonly ?AuditTrailMapper $auditTrailMapper = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Return the stored manifest JSON blob for a given virtual-app slug.
	 *
	 * Lookup path: slug → BuiltAppRoute → applicationUuid → Application →
	 * manifest. The manifest is returned UNWRAPPED (no OR envelope) so
	 * useAppManifest in @conduction/nextcloud-vue consumes it directly.
	 *
	 * Version routing (spec `buildiq-version-routing` REQ-OBVR-001):
	 * ---------------------------------------------------------------
	 * An optional `?_version=<versionSlug>` query parameter selects a specific
	 * ApplicationVersion. The underscore-prefix form (`_version`, not `version`)
	 * is Buildiq's system-reserved namespace marker — it prevents collision
	 * with user-defined `?version=` params that citizen developers may add to
	 * their virtual apps' routes.
	 *
	 * When `?_version=` is present, the request is routed through
	 * ManifestResolverService which enforces RBAC: viewers and non-members
	 * receive 404 (not 403) for non-production versions; unknown version slugs
	 * also 404 (same response — no existence leak, REQ-OBVR-003 / Decision 8).
	 *
	 * When `?_version=` is absent the endpoint behaves exactly as before,
	 * returning the production manifest to any authenticated caller with any
	 * role on the Application (the existing requirePermission check).
	 *
	 * Visibility model
	 * ----------------
	 * `#[NoAdminRequired]` is intentional: the hello-world seed app (and any
	 * future "always-on" virtual app) is publicly mountable as soon as a route
	 * exists. RBAC lives inside ManifestResolverService (for versioned access)
	 * and requirePermission (for the production path).
	 *
	 * Owner signal (admin-settings-owner-gating): before the `permissions`
	 * block is stripped, {@see injectOwnerSignal()} projects a read-only
	 * `runtime.user.isOwner` boolean onto the returned manifest, computed via
	 * `PermissionResolver::matchesCaller(...['owners'])` with no NC
	 * super-admin fallback — see design.md Decision D3.
	 *
	 * @param string $slug The virtual-app slug from the URL
	 *
	 * @return JSONResponse The manifest blob (carrying `runtime.user.isOwner`), or a 404 envelope when not found
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-50
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-51
	 * @spec openspec/changes/openbuild-admin-settings-abstraction/specs/admin-settings-owner-gating/spec.md#requirement-owner-signal-is-derived-from-existing-buildiq-primitives
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getManifest(string $slug): JSONResponse {
		try {
			// REQ-OBVR-001: read the `?_version=` query parameter.
			// The param name uses a leading underscore to avoid colliding with any
			// user-defined `?version=` params in citizen-developer apps. Null when absent.
			$versionSlugRaw = $this->request->getParam('_version');
			$versionSlug = null;
			if ($versionSlugRaw !== null && $versionSlugRaw !== '') {
				$versionSlug = $versionSlugRaw;
			}

			// When `?_version=` is present, delegate to ManifestResolverService which
			// performs the two-step lookup (Application → ApplicationVersion) and RBAC
			// gate (REQ-OBVR-002 / REQ-OBVR-003). Both "unknown version" and
			// "unauthorised caller" return identical 404 to prevent slug enumeration.
			if ($versionSlug !== null) {
				return $this->resolveVersionedManifestResponse(slug: $slug, versionSlug: $versionSlug);
			}

			// No `?_version=` param: original production-manifest path (backwards-compat).
			$resolved = $this->resolveApplicationBySlug(slug: $slug);
			if ($resolved instanceof JSONResponse) {
				return $resolved;
			}

			[$application, $applicationArray, $applicationUuid] = $resolved;

			// RBAC enforcement per REQ-OBRBAC-002 / REQ-OBR-006 — deny-by-default
			// before any branch that would emit the manifest payload (ADR-005,
			// ADR-022 §Exceptions(1)). Returns 403 with a fixed error envelope
			// when the caller has no role intersection and is not exercising
			// the audited admin bypass declared in REQ-OBRBAC-006.
			$applicationEntity = null;
			if ($application instanceof ObjectEntity) {
				$applicationEntity = $application;
			}

			$denial = $this->requirePermission(
				application: $applicationEntity,
				applicationArray: $applicationArray,
				slug: $slug
			);
			if ($denial !== null) {
				return $denial;
			}

			// Resolve the production manifest from the application's
			// productionVersion. The versioned model (ADR-002) stores the
			// manifest on the ApplicationVersion, not on the Application, so
			// reading `applicationArray['manifest']` directly returns null for
			// every app. ManifestResolverService resolves productionVersion →
			// version.manifest and itself falls back to a legacy
			// application-level `manifest` field; the direct read is kept as a
			// last-resort fallback for safety.
			$manifest = $this->manifestResolver->resolve(
				appSlug: $slug,
				versionSlug: null,
				caller: $this->userSession->getUser()
			);
			if ($manifest === null) {
				$manifest = ($applicationArray['manifest'] ?? null);
			}

			if ($manifest === null) {
				$this->logger->warning('Buildiq: Application ' . $applicationUuid . ' has no resolvable manifest');
				return new JSONResponse(
					data: ['error' => 'no_manifest', 'message' => 'Application has no manifest'],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			// Strip the `permissions` block (and any other owning-user PII) from
			// the public manifest response (issue #165). The caller's own role was
			// already verified by requirePermission above; they do not need the
			// full owners/editors/viewers roster to render the app. Before the
			// block is stripped, project a read-only `runtime.user.isOwner`
			// signal (admin-settings-owner-gating) computed from that same
			// `permissions` block, so the frontend can gate the admin-settings
			// surface without ever seeing the raw owners/editors/viewers roster.
			if (is_array($manifest) === true) {
				$manifest = $this->injectOwnerSignal(
					manifest: $manifest,
					applicationArray: $applicationArray,
					caller: $this->userSession->getUser()
				);
				// Project the Application's authoritative display `name` onto the
				// manifest so the /builder/{slug} runtime top-bar always shows the
				// cased name, not the raw slug. The stored manifest blob's own
				// `name` can be absent or stale (it is not re-synced when the
				// Application is renamed), so the Application entity's `name` is the
				// single source of truth. Additive projection, same pattern as
				// injectOwnerSignal above; only overwrite when the Application
				// actually carries a non-empty name.
				$authoritativeName = (string)($applicationArray['name'] ?? '');
				if ($authoritativeName !== '') {
					$manifest['name'] = $authoritativeName;
				}

				// Group-scoped runtime access (spec runtime-group-scoped-access
				// REQ-2): strip menu/page entries the caller's `permission` set
				// does not satisfy BEFORE the response leaves the server — the
				// authoritative server-side gate, not just client-side hiding.
				// MUST run before `permissions` is stripped below: the filter
				// reads `applicationArray['permissions']` for the owner/editor
				// write-role bypass.
				$manifest = $this->manifestResolver->filterManifestForCaller(
					manifest: $manifest,
					application: $applicationArray,
					caller: $this->userSession->getUser()
				);
				// Client-side mirror (design.md Decision 4, defense in depth):
				// hand the runtime host the same permission set the server
				// just enforced, ready to forward to CnAppRoot's `permissions`
				// prop — no client-side group/role derivation needed.
				$manifest = $this->injectPermissionsSignal(
					manifest: $manifest,
					applicationArray: $applicationArray,
					caller: $this->userSession->getUser()
				);
				unset($manifest['permissions']);
			}//end if

			// Return the manifest UNWRAPPED — useAppManifest expects the bare object.
			return new JSONResponse(data: $manifest, statusCode: Http::STATUS_OK);
		} catch (Throwable $e) {
			// Generate a correlation ID so the client and server logs share an
			// identifier — operators can grep `correlationId=<id>` in app.log
			// without needing the request timestamp. Per MWest review on PR #2.
			$correlationId = bin2hex(random_bytes(8));
			$this->logger->error(
				'Buildiq: getManifest failed for slug ' . $slug . ': ' . $e->getMessage(),
				['exception' => $e, 'correlationId' => $correlationId, 'slug' => $slug]
			);
			return new JSONResponse(
				data: [
					'error' => 'internal_error',
					'message' => 'Failed to resolve manifest',
					'correlationId' => $correlationId,
				],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end getManifest()

	/**
	 * Persist an in-app manifest edit (pages / menu / settings / sidebar / actions).
	 *
	 * Symmetric write counterpart to {@see getManifest}: the standalone runtime's
	 * Buildiq edit shell (ADR-041) PUTs the full edited manifest here on Save.
	 * Resolves the app by slug, enforces owner/editor RBAC (viewers are read-only;
	 * NC admins get an audited bypass), then surgically writes the `manifest` field
	 * onto the production ApplicationVersion (versioned model, ADR-002), falling
	 * back to the Application object for legacy un-versioned apps. The caller-
	 * supplied `permissions` block is stripped so a non-owner cannot escalate.
	 *
	 * @param string $slug The virtual-app slug from the URL.
	 *
	 * @return JSONResponse 200 on save, 400 on bad body, 403 without write role.
	 *
	 * @spec openspec/specs/openbuild-rbac/spec.md
	 */
	#[NoAdminRequired]
	public function saveManifest(string $slug): JSONResponse {
		try {
			$resolved = $this->resolveApplicationBySlug(slug: $slug);
			if ($resolved instanceof JSONResponse) {
				return $resolved;
			}

			[$application, $applicationArray] = $resolved;

			$user = $this->userSession->getUser();
			if ($user === null) {
				return new JSONResponse(
					data: ['error' => 'forbidden', 'code' => 'buildiq.rbac.no_role'],
					statusCode: Http::STATUS_FORBIDDEN
				);
			}

			// Write requires owner/editor (NOT viewer). NC admins get an audited bypass.
			$hasWrite = $this->permissionResolver->matchesCaller(
				permissions: ($applicationArray['permissions'] ?? []),
				caller: $user,
				userGroups: $this->permissionResolver->resolveUserGroups($user),
				allowAdminBypass: false,
				roles: ['owners', 'editors']
			);
			if ($hasWrite === false && $this->groupManager->isInGroup($user->getUID(), self::ADMIN_GROUP) === false) {
				return new JSONResponse(
					data: ['error' => 'forbidden', 'code' => 'buildiq.rbac.no_role'],
					statusCode: Http::STATUS_FORBIDDEN
				);
			}

			if ($hasWrite === false) {
				$bypassApplication = null;
				if ($application instanceof ObjectEntity) {
					$bypassApplication = $application;
				}

				$this->recordAdminBypass(
					application: $bypassApplication,
					slug: $slug,
					actor: $user->getUID()
				);
			}

			$manifest = $this->request->getParam('manifest');
			if (is_array($manifest) === false) {
				return new JSONResponse(
					data: ['error' => 'bad_request', 'message' => 'Missing or invalid manifest'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// A manifest the runtime cannot render must not be persisted.
			//
			// The Design tab already refuses to save one (REQ-OBPD-009 puts the
			// guarantee in the client: the Save button is disabled and a tooltip
			// states why), but the guarantee stopped at the browser — an API
			// caller could PUT `{version, menu}` with no `pages` and get a 200,
			// leaving the app with a manifest that renders nothing. Nothing on
			// the read path repairs that.
			//
			// These are the SAME two rules the GitHub-import path has always
			// enforced in AppRepoParser::validateManifest() — a string `version`
			// and an array `pages`. Applying them here makes one endpoint agree
			// with the other rather than inventing a new contract.
			$manifestError = $this->manifestShapeError(manifest: $manifest);
			if ($manifestError !== null) {
				return new JSONResponse(
					data: ['error' => 'manifest_invalid', 'message' => $manifestError],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}

			// Never let a manifest body inject/overwrite the permissions roster.
			unset($manifest['permissions']);

			// Versioned model: write onto the production ApplicationVersion when one
			// is resolvable; fall back to the Application object for legacy apps.
			// Mirror ManifestResolverService::resolveProductionManifest's read
			// shape EXACTLY so the write target matches the read target:
			// productionVersion is either a UUID string (→ the ApplicationVersion
			// object), an inline embedded object (→ its nested manifest on the
			// Application), or absent (→ legacy application-level manifest).
			$productionVersion = ($applicationArray['productionVersion'] ?? null);

			// Case 1: UUID reference — write onto that ApplicationVersion. The
			// uuid comes from the already-RBAC'd Application, so no cross-app check
			// is needed (and the version's app-link field is `application`, not
			// `applicationUuid`).
			if (is_string($productionVersion) === true && $productionVersion !== '' && $productionVersion !== 'draft') {
				$versionEntity = $this->objectService->find(
					id: $productionVersion,
					register: 'buildiq',
					schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA
				);
				if ($versionEntity !== null) {
					$versionArray = $this->normaliseObject(object: $versionEntity);
					$versionArray['manifest'] = $manifest;
					$this->objectService->saveObject(
						object: $versionArray,
						register: 'buildiq',
						schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA
					);
					return new JSONResponse(data: ['status' => 'ok', 'target' => 'version'], statusCode: Http::STATUS_OK);
				}
			}

			// Case 2: inline embedded version object — update its nested manifest
			// and persist the Application (the embedded version travels with it).
			if (is_array($productionVersion) === true) {
				$applicationArray['productionVersion']['manifest'] = $manifest;
				$this->objectService->saveObject(
					object: $applicationArray,
					register: 'buildiq',
					schema: 'built-app'
				);
				return new JSONResponse(data: ['status' => 'ok', 'target' => 'embedded'], statusCode: Http::STATUS_OK);
			}

			// Case 3: legacy un-versioned app — application-level manifest.
			$applicationArray['manifest'] = $manifest;
			$this->objectService->saveObject(
				object: $applicationArray,
				register: 'buildiq',
				schema: 'built-app'
			);
			return new JSONResponse(data: ['status' => 'ok', 'target' => 'application'], statusCode: Http::STATUS_OK);
		} catch (Throwable $e) {
			$correlationId = bin2hex(random_bytes(8));
			$this->logger->error(
				'Buildiq: saveManifest failed for slug ' . $slug . ': ' . $e->getMessage(),
				['exception' => $e, 'correlationId' => $correlationId, 'slug' => $slug]
			);
			return new JSONResponse(
				data: ['error' => 'internal_error', 'message' => 'Failed to save manifest', 'correlationId' => $correlationId],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end saveManifest()

	/**
	 * Return why a manifest cannot be rendered, or null when its shape is sound.
	 *
	 * Deliberately the SAME two rules AppRepoParser::validateManifest() applies
	 * to an imported manifest.json — a string `version` and an array `pages`.
	 * Structural only: it says nothing about whether an individual page is
	 * well-formed, which is the renderer's business.
	 *
	 * @param array<string,mixed> $manifest The decoded manifest body.
	 *
	 * @return string|null Reason the manifest is unusable, or null when it is fine.
	 *
	 * @spec openspec/specs/openbuild-page-designer/spec.md
	 */
	private function manifestShapeError(array $manifest): ?string {
		if ($manifest === []) {
			return 'The manifest is an empty object; a virtual app manifest must declare version + pages.';
		}

		if (is_string($manifest['version'] ?? null) === false) {
			return 'The manifest is missing a string `version` property.';
		}

		if (is_array($manifest['pages'] ?? null) === false) {
			return 'The manifest is missing a `pages` array.';
		}

		return null;
	}//end manifestShapeError()

	/**
	 * Delegate to ManifestResolverService for versioned-manifest access.
	 *
	 * Performs the two-step lookup (Application → ApplicationVersion) and RBAC
	 * gate (REQ-OBVR-002 / REQ-OBVR-003). Both "unknown version" and
	 * "unauthorised caller" return identical 404 to prevent slug enumeration.
	 *
	 * @param string $slug The virtual-app slug from the URL.
	 * @param string $versionSlug The version slug from `?_version=`.
	 *
	 * @return JSONResponse 200 with manifest, or 404 when not found / not authorised.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-69
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-70
	 */
	private function resolveVersionedManifestResponse(string $slug, string $versionSlug): JSONResponse {
		$caller = $this->userSession->getUser();
		$manifest = $this->manifestResolver->resolve(
			appSlug: $slug,
			versionSlug: $versionSlug,
			caller: $caller
		);

		if ($manifest === null) {
			return new JSONResponse(
				data: ['status' => Http::STATUS_NOT_FOUND, 'message' => 'Version not found'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		// Group-scoped runtime access (spec runtime-group-scoped-access REQ-2):
		// same server-side filter as the production path. Non-production
		// access already required an owner/editor role (checkNonProductionAccess
		// in ManifestResolverService::resolve()), so this is a no-op for the
		// only callers who can reach this branch today — kept for symmetry and
		// so a future loosening of the version-access gate does not silently
		// skip permission filtering.
		$resolved = $this->resolveApplicationBySlug(slug: $slug);
		if (is_array($resolved) === true) {
			[, $applicationArray] = $resolved;
			$manifest = $this->manifestResolver->filterManifestForCaller(
				manifest: $manifest,
				application: $applicationArray,
				caller: $caller
			);
			$manifest = $this->injectPermissionsSignal(
				manifest: $manifest,
				applicationArray: $applicationArray,
				caller: $caller
			);
		}

		return new JSONResponse(data: $manifest, statusCode: Http::STATUS_OK);
	}//end resolveVersionedManifestResponse()

	/**
	 * Return two manifest blobs side-by-side so the client diff component
	 * can render without a second round-trip (REQ-OBV-005, chain spec #6).
	 *
	 * Resolves `{slug}` to an Application via the BuiltAppRoute index,
	 * accepts the literal string `draft` for either `from`/`to` to mean
	 * "the current draft manifest on the Application", otherwise looks
	 * up both referenced ApplicationVersion rows. Returns a shape of
	 * `{ from: { manifest, version, publishedAt }, to: { manifest,
	 * version, publishedAt } }`. Per ADR-032 this is thin glue
	 * (~30 LOC of logic); no service class.
	 *
	 * @param string $slug The virtual-app slug from the URL
	 * @param string $from ApplicationVersion UUID or the literal `draft`
	 * @param string $to ApplicationVersion UUID or the literal `draft`
	 *
	 * @return JSONResponse Both blobs on 200, or a 404 envelope on miss
	 *
	 * IDOR-safe: slug → BuiltAppRoute lookup enforces org scope via OR's
	 * standard multitenancy (RegisterMapper::find + ObjectService::searchObjects),
	 * and the resolveVersionBlob() check on `applicationUuid` rejects snapshots
	 * that do not belong to this Application. Mirrors getManifest()'s pattern.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-58
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function diffVersions(string $slug, string $from, string $to): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->errorResponse(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$registerId = $this->registerMapper->find(ApplicationVersionService::REGISTER_SLUG, _multitenancy: false)->getId();
			$routeSchema = $this->schemaMapper->find('built-app-route', _multitenancy: false)->getId();

			$routeResults = $this->objectService->searchObjects(
				query: [
					'@self' => [
						'register' => $registerId,
						'schema' => $routeSchema,
					],
					'slug' => $slug,
				]
			);

			if (empty($routeResults) === true) {
				return new JSONResponse(
					data: ['error' => 'not_found', 'message' => 'No published virtual app found for slug ' . $slug],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			$route = $this->normaliseObject(object: $routeResults[0]);
			$applicationUuid = ($route['applicationUuid'] ?? null);

			if ($applicationUuid === null) {
				return new JSONResponse(
					data: ['error' => 'inconsistent_state', 'message' => 'Route exists but has no applicationUuid'],
					statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
				);
			}

			$application = $this->objectService->find(
				id: $applicationUuid,
				register: 'buildiq',
				schema: 'built-app'
			);

			if ($application === null) {
				return new JSONResponse(
					data: ['error' => 'not_found', 'message' => 'Application not found'],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			$applicationArray = $this->normaliseObject(object: $application);

			// RBAC enforcement (C5 / REQ-OBRBAC-002): deny-by-default before
			// returning any manifest data. Mirrors the identical gate in getManifest().
			// Both `from` and `to` blobs come from this Application, so a single
			// requirePermission call on the resolved Application is sufficient.
			$applicationEntity = null;
			if ($application instanceof ObjectEntity) {
				$applicationEntity = $application;
			}

			$denial = $this->requirePermission(
				application: $applicationEntity,
				applicationArray: $applicationArray,
				slug: $slug
			);
			if ($denial !== null) {
				return $denial;
			}

			$fromBlob = $this->resolveVersionBlob(token: $from, application: $applicationArray, applicationUuid: $applicationUuid);
			if ($fromBlob === null) {
				return new JSONResponse(
					data: ['error' => 'not_found', 'message' => 'from version not found: ' . $from],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			$toBlob = $this->resolveVersionBlob(token: $to, application: $applicationArray, applicationUuid: $applicationUuid);
			if ($toBlob === null) {
				return new JSONResponse(
					data: ['error' => 'not_found', 'message' => 'to version not found: ' . $to],
					statusCode: Http::STATUS_NOT_FOUND
				);
			}

			return new JSONResponse(
				data: ['from' => $fromBlob, 'to' => $toBlob],
				statusCode: Http::STATUS_OK
			);
		} catch (\Throwable $e) {
			$this->logger->error('Buildiq: diffVersions failed for slug ' . $slug . ': ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(
				data: ['error' => 'internal_error', 'message' => 'Failed to resolve diff'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end diffVersions()

	/**
	 * Resolve a `from`/`to` token to a `{ manifest, version, publishedAt }` blob.
	 *
	 * The literal string `draft` returns the Application's current draft
	 * fields. Any other value is treated as an ApplicationVersion UUID
	 * and looked up via OR's ObjectService. Returns null on miss so the
	 * caller can surface 404.
	 *
	 * @param string $token Token (`draft` or UUID).
	 * @param array<string, mixed> $application Normalised Application data.
	 * @param string $applicationUuid Parent Application UUID for scoping.
	 *
	 * @return array<string, mixed>|null Blob or null if the version is missing.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-58
	 */
	private function resolveVersionBlob(string $token, array $application, string $applicationUuid): ?array {
		if ($token === 'draft') {
			return [
				'manifest' => ($application['manifest'] ?? null),
				'version' => ($application['version'] ?? null),
				'publishedAt' => null,
			];
		}

		// AN EMPTY TOKEN IS A MISS, NOT A LOOKUP.
		//
		// `GET .../versions/diff?from=&to=` arrives here with `''`. Asking the
		// object store to find the object whose id is the empty string is not a
		// question with an answer; it just throws further down. Answer it here,
		// where it is still a 404 about a version the caller named badly.
		if ($token === '') {
			return null;
		}

		// `ObjectService::find()` THROWS when the object is absent — it does not
		// return null. This method's own docblock says "Returns null on miss so
		// the caller can surface 404", and diffVersions() duly has two
		// `if (...Blob === null) return 404` branches — and NEITHER COULD EVER
		// BE TAKEN. The throw went straight past them into diffVersions()'s
		// outer `catch (Throwable)`, which answers 500 `internal_error` and logs
		// "Buildiq: diffVersions failed for slug hello-world: Object not found
		// in magic table".
		//
		// This is the eighth instance of the family PR #159 fixed ("seven 404
		// branches were unreachable — the lookup threw first"). It was not among
		// those seven because gate-49 only flags an untranslated lookup OUTSIDE
		// a try/catch, and this one sits inside diffVersions()'s. Being inside a
		// catch does not make it correct: it converts a precise 404 naming the
		// missing version into an opaque 500, on a #[NoAdminRequired] endpoint.
		//
		// Translated at the lookup, with the cause logged, so the null the
		// signature has always promised is actually reachable.
		try {
			$version = $this->objectService->find(
				id: $token,
				register: 'buildiq',
				schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'Buildiq: diff token {token} did not resolve to an ApplicationVersion: {message}',
				['token' => $token, 'message' => $e->getMessage(), 'exception' => $e]
			);
			return null;
		}//end try

		if ($version === null) {
			return null;
		}

		$versionArray = $this->normaliseObject(object: $version);

		// Organisation-scope enforcement: a snapshot from another Application is a miss.
		if (($versionArray['applicationUuid'] ?? null) !== $applicationUuid) {
			return null;
		}

		return [
			'manifest' => ($versionArray['manifest'] ?? null),
			'version' => ($versionArray['version'] ?? null),
			'publishedAt' => ($versionArray['publishedAt'] ?? null),
		];
	}//end resolveVersionBlob()

	/**
	 * Resolve a virtual-app slug to the Application object + array form + uuid.
	 *
	 * Returns either a `JSONResponse` (404 / 500) when resolution fails, or a
	 * tuple `[ObjectEntityInterface|array, array, string]` of (raw entity,
	 * normalised data, applicationUuid) for the happy path. Splitting this out
	 * keeps `getManifest` below PHPMD's 100-line method-length budget.
	 *
	 * Element 0 is whatever `ObjectServiceInterface::find()` returns, i.e. an
	 * `ObjectEntityInterface` (ADR-084). The callers that need the concrete
	 * `ObjectEntity` for an audit-trail write still narrow with `instanceof`.
	 *
	 * @param string $slug The virtual-app slug from the URL
	 *
	 * @return JSONResponse|array{0: ObjectEntityInterface|array<string, mixed>, 1: array<string, mixed>, 2: string}
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-50
	 */
	private function resolveApplicationBySlug(string $slug): JSONResponse|array {
		// Resolve register + schema slugs to numeric IDs. OR's searchObjects
		// expects numeric IDs in @self; the slug-resolution shortcut isn't
		// applied at this layer (verified during smoke-test 2026-05-11).
		// _multitenancy=false bypasses the org filter on the LOOKUP only —
		// object-level multitenancy is still enforced via searchObjects below.
		//
		// Both find() calls THROW DoesNotExistException when the register or
		// schema is absent — they do not return null. This method's whole
		// contract is "a JSONResponse on failure or a tuple on success", and
		// an uncaught throw here broke it: `getManifest` is #[NoAdminRequired],
		// so an unprovisioned OpenRegister answered an unauthenticated-ish
		// caller with a framework 500 and a stack trace rather than a
		// translated error. Translated below, cause logged.
		try {
			$registerId = $this->registerMapper->find(ApplicationVersionService::REGISTER_SLUG, _multitenancy: false)->getId();
			$routeSchema = $this->schemaMapper->find('built-app-route', _multitenancy: false)->getId();
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: could not resolve the buildiq register / built-app-route schema: {message}',
				['message' => $e->getMessage(), 'exception' => $e]
			);
			return new JSONResponse(
				data: [
					'error' => 'internal_error',
					'message' => 'The Buildiq register is not available.',
				],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		// Step 1 — resolve slug → applicationUuid via the BuiltAppRoute index.
		$routeResults = $this->objectService->searchObjects(
			query: [
				'@self' => [
					'register' => $registerId,
					'schema' => $routeSchema,
				],
				'slug' => $slug,
			]
		);

		if (empty($routeResults) === true) {
			$this->logger->debug('Buildiq: no BuiltAppRoute found for slug=' . $slug);
			return new JSONResponse(
				data: ['error' => 'not_found', 'message' => 'No published virtual app found for slug ' . $slug],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		// FindAll renders entities; result entries may be ObjectEntity or arrays.
		$route = $this->normaliseObject(object: $routeResults[0]);
		$applicationUuid = ($route['applicationUuid'] ?? null);

		if ($applicationUuid === null) {
			$this->logger->warning('Buildiq: BuiltAppRoute for slug ' . $slug . ' is missing applicationUuid');
			return new JSONResponse(
				data: ['error' => 'inconsistent_state', 'message' => 'Route exists but has no applicationUuid'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		// Step 2 — load the Application object.
		$application = $this->objectService->find(
			id: $applicationUuid,
			register: 'buildiq',
			schema: 'built-app'
		);

		if ($application === null) {
			$this->logger->warning('Buildiq: Application ' . $applicationUuid . ' (for slug ' . $slug . ') not found');
			return new JSONResponse(
				data: ['error' => 'inconsistent_state', 'message' => 'Route points to an Application that does not exist'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return [$application, $this->normaliseObject(object: $application), (string)$applicationUuid];
	}//end resolveApplicationBySlug()

	/**
	 * Return the list of Applications the caller has any role on.
	 *
	 * Closes the list-endpoint IDOR (REQ-OBRBAC-002 / REQ-OBR-007). OR's
	 * schema-level read rule is a coarse group ACL — not a row-level
	 * predicate on `permissions.owners ∪ editors ∪ viewers` — so the
	 * frontend cannot rely on OR's REST list endpoint without leaking
	 * every Application's permissions block and manifest to every
	 * authenticated user. This action fetches all Applications via OR
	 * and filters them server-side using the same role-derivation rule
	 * as `requirePermission`. Admin callers receive the full unfiltered
	 * list and a single audit event is recorded (REQ-OBRBAC-006).
	 *
	 * Output shape mirrors what `ApplicationEditor.vue` previously
	 * received from OR REST: a flat array of Application objects with
	 * `uuid`, `id`, `slug`, `name`, `status`, `version`, `manifest`,
	 * `permissions` — no OR envelope, no pagination metadata.
	 *
	 * @return JSONResponse The filtered Application list
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-46
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-47
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-48
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function listMine(): JSONResponse {
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return new JSONResponse(
					data: ['error' => 'forbidden', 'code' => 'buildiq.rbac.no_role'],
					statusCode: Http::STATUS_FORBIDDEN
				);
			}

			$registerId = $this->registerMapper->find(ApplicationVersionService::REGISTER_SLUG, _multitenancy: false)->getId();
			$appSchema = $this->schemaMapper->find('built-app', _multitenancy: false)->getId();

			// Fetch all Applications scoped to the buildiq register +
			// application schema. OR's multitenancy + RBAC still applies;
			// the per-Application filter below is the load-bearing
			// authorization boundary.
			$results = $this->objectService->searchObjects(
				query: [
					'@self' => [
						'register' => $registerId,
						'schema' => $appSchema,
					],
				]
			);

			if (is_array($results) === false) {
				$results = [];
			}

			$userGroups = $this->permissionResolver->resolveUserGroups($user);
			$isAdmin = $this->groupManager->isInGroup($user->getUID(), self::ADMIN_GROUP);

			[$filtered, $adminBypassUsed] = $this->filterApplicationsByRole(
				results: $results,
				userGroups: $userGroups,
				isAdmin: $isAdmin
			);

			if ($adminBypassUsed === true) {
				$this->logger->info(
					'Buildiq: rbac.admin_bypass exercised on Application list',
					[
						'actor' => $user->getUID(),
						'event' => self::EVENT_ADMIN_BYPASS . '.list',
						'count' => count($filtered),
						'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
					]
				);
			}

			$filtered = $this->attachProductionVersionDetail(applications: $filtered);

			return new JSONResponse(data: $filtered, statusCode: Http::STATUS_OK);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: listMine failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(
				data: ['error' => 'internal_error', 'message' => 'Failed to load applications'],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end listMine()

	/**
	 * Filter a raw OR result set to Applications the caller is authorised to see.
	 *
	 * Returns a two-element tuple: [filteredApps, adminBypassUsed].
	 *
	 * @param array<mixed> $results Raw OR search result entries.
	 * @param array<string> $userGroups Caller's group IDs.
	 * @param bool $isAdmin Whether the caller is in the Nextcloud admin group.
	 *
	 * @return array{0: array<array<string,mixed>>, 1: bool} [filtered list, adminBypassUsed].
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-48
	 */
	private function filterApplicationsByRole(
		array $results,
		array $userGroups,
		bool $isAdmin,
	): array {
		$filtered = [];
		$adminBypassUsed = false;

		$caller = $this->userSession->getUser();
		if ($caller === null) {
			return [[], false];
		}

		foreach ($results as $entry) {
			$app = $this->normaliseObject(object: $entry);
			if ($app === []) {
				continue;
			}

			$permissions = ($app['permissions'] ?? []);

			// Check all three roles (owners + editors + viewers) for list visibility.
			$hasRole = $this->permissionResolver->matchesCaller(
				permissions: $permissions,
				caller: $caller,
				userGroups: $userGroups,
				allowAdminBypass: false,
				roles: ['owners', 'editors', 'viewers']
			);

			if ($hasRole === true) {
				// L1: strip the internal permissions roster for callers who are
				// viewers-only (not owner/editor). Owners and editors need the
				// permissions block to manage the app; viewers do not.
				$hasWriteRole = $this->permissionResolver->matchesCaller(
					permissions: $permissions,
					caller: $caller,
					userGroups: $userGroups,
					allowAdminBypass: false,
					roles: ['owners', 'editors']
				);
				if ($hasWriteRole === false) {
					unset($app['permissions']);
				}

				$filtered[] = $app;
				continue;
			}

			if ($isAdmin === true) {
				$filtered[] = $app;
				$adminBypassUsed = true;
			}
		}//end foreach

		return [$filtered, $adminBypassUsed];
	}//end filterApplicationsByRole()

	/**
	 * Attach the resolved production ApplicationVersion to each listed Application.
	 *
	 * WHY THIS EXISTS
	 * ---------------
	 * `productionVersion` on an Application is a UUID *string* (the versioned
	 * model, ADR-002). Every detail-side consumer treats it that way and looks
	 * the UUID up in a separately-fetched versions list —
	 * `ApplicationDetailHeader.productionVersionUuid`,
	 * `ApplicationDetailDashboard`, `ApplicationVersionsTab`,
	 * `promoteVersionDefaults`, `useApplicationVersion`.
	 *
	 * The LIST view has no such list to resolve against, and `ApplicationCard.vue`
	 * bailed unless `productionVersion` was already an object. It never is on this
	 * endpoint, so `statusKey` fell through to `'draft'` and `productionSemver` to
	 * `'—'` for EVERY card, whatever the app's real state — measured on the e2e
	 * instance, where `hello-world` renders "Draft / Version —" while its
	 * production ApplicationVersion is `{status: 'published', semver: '1.0.0'}`.
	 * That made REQ-OBR-007b ("newly published Application shows published badge")
	 * unsatisfiable from the list.
	 *
	 * `productionVersion` is deliberately LEFT ALONE — changing it to an object
	 * would break every string consumer named above. The resolved record is added
	 * alongside it as `productionVersionDetail`, and only the fields a card needs
	 * are projected: the full version row carries the whole manifest blob, which
	 * would bloat a list response enormously for data no card reads.
	 *
	 * One extra query total, not one per row: every ApplicationVersion is fetched
	 * once and indexed by UUID. This mirrors
	 * {@see ApplicationVersionsController::index()}, which fetches all rows and
	 * filters client-side for the same reason (OR's `searchObjects` does not
	 * reliably filter by relation-string equality on the `application` field).
	 *
	 * @param array<array<string,mixed>> $applications Applications the caller may see.
	 *
	 * @return array<array<string,mixed>> The same list, each entry gaining
	 *                                    `productionVersionDetail` when its
	 *                                    production version resolves.
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#req-obr-007b
	 */
	private function attachProductionVersionDetail(array $applications): array {
		// Nothing to resolve — skip the query entirely.
		$wanted = [];
		foreach ($applications as $app) {
			$uuid = ($app['productionVersion'] ?? null);
			if (is_string($uuid) === true && $uuid !== '' && $uuid !== 'draft') {
				$wanted[$uuid] = true;
			}
		}

		if ($wanted === []) {
			return $applications;
		}

		try {
			$registerId = $this->registerMapper->find(
				ApplicationVersionService::REGISTER_SLUG,
				_multitenancy: false
			)->getId();
			$schemaId = $this->schemaMapper->find(
				ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
				_multitenancy: false
			)->getId();

			$rows = $this->objectService->searchObjects(
				query: [
					'@self' => [
						'register' => $registerId,
						'schema' => $schemaId,
					],
				]
			);
		} catch (Throwable $e) {
			// Fail SOFT and SAY SO: the list is still perfectly usable without
			// the badge detail, but a silent catch here would recreate exactly
			// the defect this method fixes — a card quietly showing "Draft"
			// because the data never arrived.
			$this->logger->warning(
				'Buildiq: could not resolve productionVersion detail for the application list; '
				. 'cards will fall back to their placeholder status/version: ' . $e->getMessage(),
				['exception' => $e]
			);
			return $applications;
		}//end try

		if (is_array($rows) === false) {
			return $applications;
		}

		$byUuid = [];
		foreach ($rows as $row) {
			$version = $this->normaliseObject(object: $row);
			if ($version === []) {
				continue;
			}

			$uuid = (string)($version['id'] ?? $version['uuid'] ?? '');
			if ($uuid === '' || isset($wanted[$uuid]) === false) {
				continue;
			}

			$byUuid[$uuid] = [
				'uuid' => $uuid,
				'slug' => ($version['slug'] ?? null),
				'name' => ($version['name'] ?? null),
				'semver' => ($version['semver'] ?? null),
				'status' => ($version['status'] ?? null),
			];
		}

		foreach ($applications as $index => $app) {
			$uuid = ($app['productionVersion'] ?? null);
			if (is_string($uuid) === true && isset($byUuid[$uuid]) === true) {
				$applications[$index]['productionVersionDetail'] = $byUuid[$uuid];
			}
		}

		return $applications;
	}//end attachProductionVersionDetail()

	/**
	 * Project a read-only owner flag onto the manifest's `runtime.user` context.
	 *
	 * Computes `runtime.user.isOwner` via {@see PermissionResolver::matchesCaller()}
	 * against the Application's `permissions.owners` bucket ONLY — the same
	 * grammar and bucket used elsewhere in this controller, no new permission
	 * model. `allowAdminBypass` is deliberately `false`: per design.md
	 * Decision D3 there is NO Nextcloud super-admin fallback for the
	 * admin-settings gate — super-admin and app-owner are different sets, and
	 * a caller who reached this method via the existing admin-bypass RBAC
	 * read-gate (see {@see requirePermission()}) must still resolve to
	 * `isOwner = false` unless they are also literally in `permissions.owners`.
	 *
	 * Additive-only: any existing `runtime`/`runtime.user` fields on the
	 * manifest are preserved untouched; only the `isOwner` key is set/overwritten.
	 * Degrades to `isOwner = false` (never fatals) when the caller is null or
	 * the Application context / its `permissions` block is absent or malformed —
	 * e.g. the legacy un-versioned fallback path that has no Application record.
	 *
	 * @param array<string, mixed> $manifest The resolved manifest payload (mutated copy is returned).
	 * @param array<string, mixed>|null $applicationArray The normalised Application data (source of
	 *                                                    `permissions`), or null when no Application
	 *                                                    context is available.
	 * @param IUser|null $caller The authenticated caller, or null for an
	 *                           unauthenticated request.
	 *
	 * @return array<string, mixed> The manifest with `runtime.user.isOwner` set (boolean).
	 *
	 * @spec openspec/changes/openbuild-admin-settings-abstraction/specs/admin-settings-owner-gating/spec.md#requirement-owner-signal-is-derived-from-existing-buildiq-primitives
	 */
	private function injectOwnerSignal(array $manifest, ?array $applicationArray, ?IUser $caller): array {
		$isOwner = false;

		if ($caller !== null && $applicationArray !== null) {
			$permissions = ($applicationArray['permissions'] ?? []);
			if (is_array($permissions) === true) {
				$isOwner = $this->permissionResolver->matchesCaller(
					permissions: $permissions,
					caller: $caller,
					userGroups: $this->permissionResolver->resolveUserGroups($caller),
					allowAdminBypass: false,
					roles: ['owners']
				);
			}
		}

		$runtime = ($manifest['runtime'] ?? []);
		if (is_array($runtime) === false) {
			$runtime = [];
		}

		$runtimeUser = ($runtime['user'] ?? []);
		if (is_array($runtimeUser) === false) {
			$runtimeUser = [];
		}

		$runtimeUser['isOwner'] = $isOwner;
		$runtime['user'] = $runtimeUser;
		$manifest['runtime'] = $runtime;

		return $manifest;
	}//end injectOwnerSignal()

	/**
	 * Project the caller's `permissions` array onto `runtime.user.permissions`
	 * (spec `runtime-group-scoped-access` REQ-1) — the ready-to-use set the
	 * runtime host forwards to `CnAppRoot`'s `permissions` prop.
	 *
	 * Additive-only, same pattern as {@see injectOwnerSignal()}: any existing
	 * `runtime`/`runtime.user` fields (including the `isOwner` just set) are
	 * preserved; only `permissions` is set/overwritten. Delegates the actual
	 * computation to {@see ManifestResolverService::resolveCallerPermissionsForDisplay()}
	 * so the client-mirror set and the server's own authoritative filter
	 * ({@see \OCA\Buildiq\Service\ManifestResolverService::filterManifestForCaller()})
	 * are derived from the exact same admin/write-role/group logic — they
	 * cannot drift apart into two different permission grammars.
	 *
	 * @param array<string, mixed> $manifest The manifest payload (mutated copy is returned).
	 * @param array<string, mixed> $applicationArray The normalised Application data.
	 * @param IUser|null $caller The authenticated caller, or null.
	 *
	 * @return array<string, mixed> The manifest with `runtime.user.permissions` set.
	 *
	 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-the-runtime-must-inject-the-current-user-s-group-context
	 */
	private function injectPermissionsSignal(array $manifest, array $applicationArray, ?IUser $caller): array {
		$permissions = $this->manifestResolver->resolveCallerPermissionsForDisplay(
			application: $applicationArray,
			caller: $caller
		);

		$runtime = ($manifest['runtime'] ?? []);
		if (is_array($runtime) === false) {
			$runtime = [];
		}

		$runtimeUser = ($runtime['user'] ?? []);
		if (is_array($runtimeUser) === false) {
			$runtimeUser = [];
		}

		$runtimeUser['permissions'] = $permissions;
		$runtime['user'] = $runtimeUser;
		$manifest['runtime'] = $runtime;

		return $manifest;
	}//end injectPermissionsSignal()

	/**
	 * Enforce the per-Application RBAC permissions block.
	 *
	 * Computes the caller's group set and intersects with the Application's
	 * `permissions.owners ∪ permissions.editors ∪ permissions.viewers`.
	 * Returns null when the caller has any role, or a `JSONResponse` 403
	 * with the fixed `buildiq.rbac.no_role` error envelope otherwise.
	 *
	 * Admin bypass per design.md Decision 5: a caller in the Nextcloud
	 * `admin` group always passes; the bypass is recorded as a
	 * `rbac.admin_bypass` event in OR's per-object audit trail
	 * (REQ-OBRBAC-006) so it surfaces in REQ-OBRBAC-007's permission
	 * history panel. The bypass MUST stay narrow — controller-only,
	 * audited — to avoid becoming a hidden parallel auth pathway.
	 *
	 * @param ObjectEntity|null $application The Application entity (for audit-trail write)
	 * @param array<string, mixed> $applicationArray The Application data (for permission inspection)
	 * @param string $slug The slug used in the audit envelope
	 *
	 * @return JSONResponse|null Null on allow, 403 JSONResponse on deny
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-45
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-47
	 */
	private function requirePermission(
		?ObjectEntity $application,
		array $applicationArray,
		string $slug,
	): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			// Unauthenticated callers should not reach a #[NoAdminRequired]
			// route — Nextcloud's framework rejects them earlier. Treat as
			// forbidden defensively (ADR-005 deny-by-default).
			return new JSONResponse(
				data: ['error' => 'forbidden', 'code' => 'buildiq.rbac.no_role'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$userGroups = $this->permissionResolver->resolveUserGroups($user);
		$permissions = ($applicationArray['permissions'] ?? []);

		// Check all three roles (any role grants read access to the manifest).
		$hasRole = $this->permissionResolver->matchesCaller(
			permissions: $permissions,
			caller: $user,
			userGroups: $userGroups,
			allowAdminBypass: false,
			roles: ['owners', 'editors', 'viewers']
		);

		if ($hasRole === true) {
			return null;
		}

		if ($this->groupManager->isInGroup($user->getUID(), self::ADMIN_GROUP) === true) {
			$this->recordAdminBypass(application: $application, slug: $slug, actor: $user->getUID());
			return null;
		}

		return new JSONResponse(
			data: ['error' => 'forbidden', 'code' => 'buildiq.rbac.no_role'],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end requirePermission()

	/**
	 * Record an admin-bypass event in OR's audit trail (REQ-OBRBAC-006).
	 *
	 * Writes a structured entry to OpenRegister's per-object audit trail
	 * via AuditTrailMapper so the bypass surfaces in REQ-OBRBAC-007's
	 * Permission history panel rather than being buried in the Nextcloud
	 * log. Falls back to the PSR logger when OR's audit mapper is
	 * unavailable (e.g. OR not loaded in a unit-test harness) so the
	 * controller never silently drops an audit event.
	 *
	 * @param ObjectEntity|null $application The Application entity bypassed
	 * @param string $slug The slug used in the audit envelope
	 * @param string $actor The bypassing user's UID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-49
	 */
	private function recordAdminBypass(?ObjectEntity $application, string $slug, string $actor): void {
		$timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$context = [
			'event' => self::EVENT_ADMIN_BYPASS,
			'actor' => $actor,
			'slug' => $slug,
			'timestamp' => $timestamp,
		];

		if ($this->auditTrailMapper !== null && $application !== null) {
			try {
				$this->auditTrailMapper->createAuditTrailEntry(
					object: $application,
					action: self::EVENT_ADMIN_BYPASS,
					context: $context
				);
				// Mirror to the PSR logger at info level so the bypass is
				// discoverable in operator-facing log streams as well — the
				// audit trail is the system of record, the PSR log is the
				// operational tap.
				$this->logger->info(
					'Buildiq: rbac.admin_bypass exercised',
					$context
				);
				return;
			} catch (Throwable $e) {
				// WF3: audit-trail write failure is a COMPLIANCE gap, not a
				// routine warning. Emit at CRITICAL so ops alerting picks it up.
				// Per REQ-OBRBAC-007 the OR audit trail is the system of record
				// for admin-bypass events; silent fallback defeats forensic review.
				$this->logger->critical(
					'Buildiq: failed to record admin bypass in OR audit trail — COMPLIANCE GAP; bypass event lost from system of record',
					array_merge($context, ['exception' => $e->getMessage()])
				);
			}//end try
		}//end if

		// Fallback path — audit mapper unavailable or no Application
		// entity (defensive). Emit to PSR logger at info level so the
		// event still surfaces somewhere reviewable.
		$this->logger->info(
			'Buildiq: rbac.admin_bypass exercised',
			$context
		);
	}//end recordAdminBypass()

	/**
	 * Clone an Application from a template.
	 *
	 * Reads the ApplicationTemplate identified by $templateSlug, creates a
	 * per-app `buildiq-{newSlug}` register, deep-copies its companion JSON
	 * schemas into that per-app register (REQ-OBTC-005 / hybrid register
	 * model), rewrites manifest schema refs to the new slug, and creates a
	 * new Application record in the shared `buildiq` register, tagged
	 * with the caller's UID (multi-user isolation).
	 *
	 * @param string $templateSlug The source template slug
	 *
	 * @return JSONResponse The new application's uuid + slug, or an error envelope
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-55
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-56
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 3600)]
	public function createFromTemplate(string $templateSlug): JSONResponse {
		// 1. Auth + request validation.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->errorResponse(code: 'unauthenticated', status: Http::STATUS_UNAUTHORIZED);
		}

		$ownerUid = $user->getUID();

		// Parity with ApplicationCreationController::wizard: cloning an app
		// provisions an OpenRegister register (an admin-only operation in OR,
		// issue #157), so this fan-out is admin-gated too. Without the gate a
		// non-admin fails opaquely deep in provisioning; with it they get a
		// clear 403, and the endpoint cannot be used as an unthrottled
		// register/schema-sprawl amplifier (paired with the rate limit above).
		if ($this->groupManager->isInGroup($ownerUid, self::ADMIN_GROUP) === false) {
			return $this->errorResponse(
				code: 'forbidden',
				detail: 'Cloning an app from a template requires Nextcloud admin privileges.',
				status: Http::STATUS_FORBIDDEN
			);
		}

		$validation = $this->validateCloneRequest(body: $this->request->getParams());
		if (isset($validation['error']) === true) {
			return new JSONResponse(data: $validation['error'], statusCode: $validation['status']);
		}

		[$name, $newSlug] = $validation;

		// 2. Resolve shared register + schemas.
		$ctx = $this->resolveSharedContext();
		if ($ctx === null) {
			return $this->errorResponse(
				code: 'not_configured',
				detail: 'Buildiq register/schemas not initialised',
				status: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		// 3. Lookup the local template, then delegate the clone to the shared
		// seam (also reused by the remote-template store install path).
		$template = $this->lookupOne(
			registerId: $ctx['register'],
			schemaId: $ctx['templateSchema'],
			slug: $templateSlug
		);
		if ($template === null) {
			return $this->errorResponse(
				code: 'template_not_found',
				detail: $templateSlug,
				status: Http::STATUS_NOT_FOUND
			);
		}

		$result = $this->installFromTemplateArray(
			template: $template,
			name: $name,
			newSlug: $newSlug,
			ownerUid: $ownerUid
		);

		return new JSONResponse(data: $result['data'], statusCode: $result['status']);
	}//end createFromTemplate()

	/**
	 * Clone a template ARRAY into a new local Application (shared install seam).
	 *
	 * This is the reusable clone body extracted from createFromTemplate so the
	 * remote-template store (buildiq-remote-template-store) can install a
	 * template fetched from a remote catalogue through the exact same path —
	 * companion-schema namespacing, manifest rewrite, per-app register
	 * provisioning, owner-tagged persist. The only difference between the local
	 * and remote callers is WHERE the `$template` array comes from.
	 *
	 * Returns a plain `{status, data}` result (NOT a Response) so both thin
	 * controller actions — the local createFromTemplate and the remote
	 * StoreController::install — own their own JSONResponse; this also keeps it
	 * out of the route-reachability surface (ADR-029: a Response-returning
	 * public controller method without a route is a latent unrouted action; a
	 * result-computer returning an array is not).
	 *
	 * @param array<string,mixed> $template The template record (local or remote payload).
	 * @param string $name Human-readable name for the new app.
	 * @param string $newSlug The new (kebab-case, pre-validated) app slug.
	 * @param string $ownerUid The owner UID (becomes the app owner).
	 * @param string|null $sourceOwner GitHub owner the template was fetched from, when
	 *                                 applicable — forwarded to the skills channel so a
	 *                                 private-repo Hermiq bundle fetch can authenticate.
	 * @param string|null $sourceRepo GitHub repo the template was fetched from, when applicable.
	 * @param string|null $credentialId Broker credential UUID used for the original fetch, when applicable.
	 *
	 * @return array{status:int,data:array<string,mixed>} 201 + app on success; 409/500/503 on failure.
	 *
	 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
	 */
	public function installFromTemplateArray(
		array $template,
		string $name,
		string $newSlug,
		string $ownerUid,
		?string $sourceOwner = null,
		?string $sourceRepo = null,
		?string $credentialId = null,
	): array {
		$ctx = $this->resolveSharedContext();
		if ($ctx === null) {
			return [
				'status' => Http::STATUS_SERVICE_UNAVAILABLE,
				'data' => ['error' => 'not_configured', 'detail' => 'Buildiq register/schemas not initialised'],
			];
		}

		// Slug uniqueness org-wide (no owner filter) to prevent squatting.
		$existing = $this->lookupOne(
			registerId: $ctx['register'],
			schemaId: $ctx['applicationSchema'],
			slug: $newSlug
		);
		if ($existing !== null) {
			return [
				'status' => Http::STATUS_CONFLICT,
				'data' => ['error' => 'slug_collision', 'detail' => $newSlug],
			];
		}

		// Clone the template into a live, manifest-resolvable Application:
		// companion-schema namespacing, per-app register provisioning, the
		// owner-tagged Application record, and its production ApplicationVersion.
		$built = $this->materialiseApplication(
			template: $template,
			name: $name,
			newSlug: $newSlug,
			ownerUid: $ownerUid,
			ctx: $ctx
		);
		if (isset($built['error']) === true) {
			return ['status' => $built['status'], 'data' => $built['error']];
		}

		// Apply the app-repo-format-v2 channels. Until this call existed, the four
		// channels were parsed and then dropped, so an installed app arrived with
		// its manifest and nothing that makes it run — and reported success.
		//
		// owner/repo/credentialId are forwarded (both null for the local-template
		// and remote-registry callers) because the skills channel delegates to
		// Hermiq's own bundle installer, which does an INDEPENDENT GitHub fetch —
		// it does not reuse the file map already fetched for `$template`. Without
		// these, that fetch runs anonymous and 404s on any private source repo,
		// which is indistinguishable from "this repo has no skill bundle" and was
		// being reported as a clean `skipped` rather than a credential gap.
		$channels = $this->channelApplier->apply(
			template: $template,
			owner: $sourceOwner,
			repo: $sourceRepo,
			actingUserId: $ownerUid,
			credentialId: $credentialId,
			applicationUuid: (string)($built['uuid'] ?? ''),
			applicationSlug: $newSlug
		);

		return [
			'status' => Http::STATUS_CREATED,
			'data' => [
				'uuid' => $built['uuid'],
				'slug' => $newSlug,
				'register' => $built['registerSlug'],
				'companionSchemas' => $built['schemaIds'],
				'channels' => $channels,
				// Copied onto the top level so a caller does not have to know
				// to look inside `channels.<name>.reason` to find an
				// actionable degradation — e.g. a credential missing the
				// scope the skills channel's hermiq delegation needs.
				'warnings' => ($channels['warnings'] ?? []),
			],
		];
	}//end installFromTemplateArray()

	/**
	 * Clone a template into a persisted, manifest-resolvable Application.
	 *
	 * The whole "turn a template array into live artefacts" half of
	 * {@see installFromTemplateArray()}: companion-schema namespacing, manifest
	 * rewrite, per-app register provisioning, the owner-tagged Application
	 * record, and the ApplicationVersion its manifest actually lives on. The
	 * caller keeps the parts that are about the REQUEST — validating it,
	 * applying the app-repo channels, and shaping the response.
	 *
	 * @param array<string,mixed> $template The template record (local or remote payload).
	 * @param string $name Human-readable name for the new app.
	 * @param string $newSlug The new (kebab-case, pre-validated) app slug.
	 * @param string $ownerUid The owner UID (becomes the app owner).
	 * @param array{register:int,templateSchema:int,applicationSchema:int} $ctx Shared context.
	 *
	 * @return array{uuid:string|null,registerSlug:string,schemaIds:array<int,int>}|array{error:array<string,mixed>,status:int}
	 */
	private function materialiseApplication(
		array $template,
		string $name,
		string $newSlug,
		string $ownerUid,
		array $ctx,
	): array {
		// Prepare manifest + companion-schema clone map.
		$companionInput = $this->extractCompanionSchemas(template: $template);
		$rewriteMap = $this->buildRewriteMap(companions: $companionInput, newSlug: $newSlug);
		$manifest = $this->buildClonedManifest(template: $template, rewriteMap: $rewriteMap);

		// Provision per-app register + clone companion schemas into it.
		$cloneResult = $this->provisionPerAppArtifacts(
			newSlug: $newSlug,
			ownerUid: $ownerUid,
			companions: $companionInput,
			rewriteMap: $rewriteMap
		);
		if (isset($cloneResult['error']) === true) {
			return ['status' => $cloneResult['status'], 'error' => $cloneResult['error']];
		}

		// Persist the Application record (shared register), tagged with owner.
		$persistResult = $this->persistApplication(
			name: $name,
			newSlug: $newSlug,
			ownerUid: $ownerUid,
			manifest: $manifest,
			template: $template,
			templateSlug: (string)($template['slug'] ?? $newSlug),
			ctx: $ctx
		);
		if (isset($persistResult['error']) === true) {
			return ['status' => $persistResult['status'], 'error' => $persistResult['error']];
		}

		// Create the ApplicationVersion the manifest actually lives on and wire
		// productionVersion to it. `persistApplication()` above stores a
		// `manifest` key directly on the Application object, but the `application`
		// schema does not declare that property — OpenRegister silently drops it
		// on save (confirmed live: a freshly cloned Application has NO `manifest`
		// key at all). `getManifest()` resolves via `productionVersion` →
		// ApplicationVersion.manifest (ADR-002); its application-level fallback
		// can therefore never fire. Without this step every template-installed
		// app (local, remote-registry, or GitHub-shop) publishes successfully
		// but its manifest/builder endpoints permanently 404 `no_manifest` —
		// live-verified on a fresh instance for both spectr and hydra-console
		// before this fix.
		$newAppUuid = (string)($persistResult['uuid'] ?? '');
		if ($newAppUuid !== '') {
			$this->linkProductionVersion(
				application: ($persistResult['application'] ?? []),
				appUuid: $newAppUuid,
				manifest: $manifest,
				registerSlug: $cloneResult['register']->getSlug()
			);
		}

		return [
			'uuid' => $persistResult['uuid'],
			'registerSlug' => $cloneResult['register']->getSlug(),
			'schemaIds' => $cloneResult['schemaIds'],
		];
	}//end materialiseApplication()

	/**
	 * Build a uniform error response.
	 *
	 * @param string $code The error code
	 * @param string|null $detail Optional detail message
	 * @param int $status The HTTP status code
	 *
	 * @return JSONResponse
	 */
	private function errorResponse(string $code, ?string $detail = null, int $status = Http::STATUS_BAD_REQUEST): JSONResponse {
		$body = ['error' => $code];
		if ($detail !== null) {
			$body['detail'] = $detail;
		}

		return new JSONResponse(data: $body, statusCode: $status);
	}//end errorResponse()

	/**
	 * Resolve the shared register + schema IDs (template, application).
	 *
	 * @return array{register:int,templateSchema:int,applicationSchema:int}|null
	 */
	private function resolveSharedContext(): ?array {
		try {
			return [
				'register' => $this->registerMapper->find(ApplicationVersionService::REGISTER_SLUG, _multitenancy: false)->getId(),
				'templateSchema' => $this->schemaMapper->find('application-template', _multitenancy: false)->getId(),
				'applicationSchema' => $this->schemaMapper->find('built-app', _multitenancy: false)->getId(),
			];
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: register/schema resolution failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveSharedContext()

	/**
	 * Build the cloned manifest (apply rewrite map to template manifest).
	 *
	 * @param array<string,mixed> $template The template record
	 * @param array<string,string> $rewriteMap Source-slug → prefixed-slug map
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-56
	 */
	private function buildClonedManifest(array $template, array $rewriteMap): array {
		$manifestRaw = ($template['manifest'] ?? null);
		$manifest = [];
		if (is_array($manifestRaw) === true) {
			$manifest = $manifestRaw;
		}

		$rewritten = $this->rewriteSchemaRefs(node: $manifest, map: $rewriteMap);
		if (is_array($rewritten) === true) {
			return $rewritten;
		}

		return [];
	}//end buildClonedManifest()

	/**
	 * Provision per-app register + clone companion schemas.
	 *
	 * @param string $newSlug The new application slug
	 * @param string $ownerUid The owner UID
	 * @param array<int,array<string,mixed>> $companions The companion schema blobs
	 * @param array<string,string> $rewriteMap Source-slug → prefixed-slug map
	 *
	 * @return array{register:\OCA\OpenRegister\Db\Register,schemaIds:array<int,int>}|array{error:array<string,mixed>,status:int}
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-56
	 */
	private function provisionPerAppArtifacts(
		string $newSlug,
		string $ownerUid,
		array $companions,
		array $rewriteMap,
	): array {
		try {
			$register = $this->provisionPerAppRegister(newSlug: $newSlug, ownerUid: $ownerUid);
			$schemaIds = $this->cloneCompanionSchemas(
				companions: $companions,
				rewriteMap: $rewriteMap,
				perAppRegister: $register
			);

			return ['register' => $register, 'schemaIds' => $schemaIds];
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: companion-schema clone failed',
				['exception' => $e->getMessage()]
			);
			return [
				'error' => ['error' => 'clone_failed', 'detail' => 'Failed to provision per-app register/schemas'],
				'status' => Http::STATUS_INTERNAL_SERVER_ERROR,
			];
		}
	}//end provisionPerAppArtifacts()

	/**
	 * Persist the cloned Application record.
	 *
	 * @param string $name Human-readable name
	 * @param string $newSlug The new application slug
	 * @param string $ownerUid The owner UID (multi-user isolation)
	 * @param array<string,mixed> $manifest The cloned manifest
	 * @param array<string,mixed> $template The source template record
	 * @param string $templateSlug The source template slug
	 * @param array{register:int,templateSchema:int,applicationSchema:int} $ctx Shared context
	 *
	 * @return array{uuid:string|null,application:array<string,mixed>}|array{error:array<string,mixed>,status:int}
	 *
	 * Exception contract: the save is wrapped in `catch (Throwable)`, which
	 * deliberately covers OpenRegister's ValidationException and
	 * DoesNotExistException as well as anything else the write path raises — all
	 * of them are translated into the `clone_failed` envelope rather than leaking
	 * out of the controller.
	 *
	 * @throws Throwable From normaliseObject() AFTER a successful save. Deliberately
	 *                   NOT folded into the envelope: by then the Application record
	 *                   already exists, so returning `clone_failed` would tell the
	 *                   caller nothing was created when something was.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-55
	 */
	private function persistApplication(
		string $name,
		string $newSlug,
		string $ownerUid,
		array $manifest,
		array $template,
		string $templateSlug,
		array $ctx,
	): array {
		try {
			$created = $this->objectService->saveObject(
				object: [
					'name' => $name,
					'slug' => $newSlug,
					'status' => 'draft',
					'version' => '0.1.0',
					'owner' => $ownerUid,
					'manifest' => $manifest,
					'templateOrigin' => [
						'slug' => (string)($template['slug'] ?? $templateSlug),
						'version' => (string)($template['version'] ?? ''),
					],
					// Grant the creating user full ownership so PermissionResolver
					// grants them access to read/edit/delete their own clone
					// (REQ-OBP-008). Without this block, matchesCaller returns false
					// on empty permissions and the user is immediately 403'd.
					'permissions' => [
						'owners' => ['user:' . $ownerUid],
						'editors' => [],
						'viewers' => [],
					],
				],
				register: $ctx['register'],
				schema: $ctx['applicationSchema']
			);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: application save failed', ['exception' => $e->getMessage()]);
			return [
				'error' => ['error' => 'clone_failed', 'detail' => $e->getMessage()],
				'status' => Http::STATUS_INTERNAL_SERVER_ERROR,
			];
		}//end try

		$createdArray = $this->normaliseObject(object: $created);
		return [
			'uuid' => ($createdArray['uuid'] ?? $createdArray['id'] ?? null),
			// The STORED payload, handed back so a follow-up write can patch one
			// field without dropping the rest — OpenRegister's saveObject() is
			// PUT, not PATCH (see linkProductionVersion()).
			'application' => $createdArray,
		];
	}//end persistApplication()

	/**
	 * Create the single ApplicationVersion a template-installed app's manifest
	 * actually lives on, and point the Application's `productionVersion` at it.
	 *
	 * Best-effort + never-throw: a failure here leaves the app installed and
	 * published but unreachable by slug (the pre-existing behaviour), logged
	 * for follow-up rather than failing the whole install over a step that
	 * only affects manifest resolution, not data/connectors/skills.
	 *
	 * @param array<string,mixed> $application The Application payload as persistApplication() stored it.
	 * @param string $appUuid The just-created Application's UUID.
	 * @param array<string,mixed> $manifest The cloned manifest.
	 * @param string $registerSlug The per-app register slug already provisioned for this install.
	 *
	 * @return void
	 */
	private function linkProductionVersion(
		array $application,
		string $appUuid,
		array $manifest,
		string $registerSlug,
	): void {
		$appSlug = (string)($application['slug'] ?? '');
		try {
			$versionPayload = [
				'name' => 'Production',
				'slug' => 'production',
				'manifest' => $manifest,
				'register' => $registerSlug,
				'semver' => '1.0.0',
				'status' => 'published',
				'application' => $appUuid,
			];

			$createdVersion = $this->objectService->saveObject(
				object: $versionPayload,
				register: ApplicationVersionService::REGISTER_SLUG,
				schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA
			);
			$versionData = $this->normaliseObject(object: $createdVersion);
			$versionUuid = (string)($versionData['uuid'] ?? $versionData['id'] ?? '');
			if ($versionUuid === '') {
				return;
			}

			// Re-save the WHOLE stored Application with productionVersion patched
			// in — never a hand-built subset. OpenRegister's saveObject() is PUT,
			// not PATCH: on the update path `SaveObject::prepareObjectForUpdate()`
			// calls `fillMissingSchemaPropertiesWithNull()`, which NULLs every
			// schema property absent from the payload, then `setObject()` replaces
			// the stored data outright — there is no merge with the existing
			// object anywhere in that path. A four-field payload here therefore
			// wiped `owner`, `status`, `version` and `templateOrigin` off the
			// record persistApplication() had just written, one statement earlier.
			// Mirrors ApplicationPublishController::setStatus(): take the stored
			// object, drop the OR-managed envelope, patch the one field.
			unset($application['@self']);
			$application['productionVersion'] = $versionUuid;

			$this->objectService->saveObject(
				object: $application,
				register: ApplicationVersionService::REGISTER_SLUG,
				schema: ApplicationVersionService::APPLICATION_SCHEMA,
				uuid: $appUuid
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: linkProductionVersion failed for ' . $appSlug . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try
	}//end linkProductionVersion()

	/**
	 * Validate the clone-from-template request body.
	 *
	 * @param array<string,mixed> $body The request params
	 *
	 * @return array{0:string,1:string}|array{error:array<string,mixed>,status:int}
	 *                                                                              Either [name, slug] on success, or an error+status envelope.
	 */
	private function validateCloneRequest(array $body): array {
		$name = (string)($body['name'] ?? '');
		$slug = (string)($body['slug'] ?? '');

		if ($name === '' || $slug === '' || preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $slug) !== 1) {
			return [
				'error' => ['error' => 'invalid_request', 'detail' => 'name and kebab-case slug required'],
				'status' => Http::STATUS_BAD_REQUEST,
			];
		}

		if (strlen($slug) > 32) {
			return [
				'error' => ['error' => 'slug_too_long', 'detail' => 'slug must be <= 32 chars'],
				'status' => Http::STATUS_BAD_REQUEST,
			];
		}

		return [$name, $slug];
	}//end validateCloneRequest()

	/**
	 * Extract companionSchemas array from a template record.
	 *
	 * @param array<string,mixed> $template The template record
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-56
	 */
	private function extractCompanionSchemas(array $template): array {
		$companionRaw = ($template['companionSchemas'] ?? null);
		if (is_array($companionRaw) === false) {
			return [];
		}

		return array_values(
			array_filter(
				$companionRaw,
				static fn ($entry): bool => is_array($entry) === true && isset($entry['slug']) === true
			)
		);
	}//end extractCompanionSchemas()

	/**
	 * Build the source-slug → prefixed-slug rewrite map.
	 *
	 * @param array<int,array<string,mixed>> $companions The companion schema blobs
	 * @param string $newSlug The new app slug used as prefix
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-56
	 */
	private function buildRewriteMap(array $companions, string $newSlug): array {
		$map = [];
		foreach ($companions as $companion) {
			$sourceSlug = (string)$companion['slug'];
			$map[$sourceSlug] = $newSlug . '-' . $sourceSlug;
		}

		return $map;
	}//end buildRewriteMap()

	/**
	 * Provision (or fetch existing) the per-app register `buildiq-{newSlug}`.
	 *
	 * Per the hybrid register model, each cloned app gets its own register so
	 * companion schemas don't collide across apps.
	 *
	 * @param string $newSlug The new app slug
	 * @param string $ownerUid The Nextcloud UID of the owner
	 *
	 * @return \OCA\OpenRegister\Db\Register
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-56
	 */
	private function provisionPerAppRegister(string $newSlug, string $ownerUid): \OCA\OpenRegister\Db\Register {
		// Cross-user collision guard (#77): OR's register slugs are
		// organisation-wide unique, so two callers wanting the same
		// template+slug would otherwise share a register. The first
		// attempt at scoping always namespaced by owner, but that
		// breaks every existing single-tenant install (their existing
		// registers are still at `buildiq-{slug}`). So we keep the
		// legacy un-namespaced slug AS LONG AS the existing register
		// belongs to the caller; otherwise fall back to the
		// owner-namespaced form.
		// Prefix from the constant, never typed: see
		// ApplicationVersionService::VERSION_REGISTER_PREFIX.
		$legacyRegisterSlug = ApplicationVersionService::VERSION_REGISTER_PREFIX . $newSlug;

		try {
			$existing = $this->registerMapper->find($legacyRegisterSlug, _multitenancy: false);

			$existingOwner = $this->extractRegisterOwner(register: $existing);
			if ($existingOwner === '' || $existingOwner === $ownerUid) {
				return $existing;
			}

			// Different user owns the org-wide slug — namespace ours.
			return $this->findOrCreateRegister(
				slug: ApplicationVersionService::VERSION_REGISTER_PREFIX . $ownerUid . '-' . $newSlug,
				appSlug: $newSlug,
				ownerUid: $ownerUid,
			);
		} catch (Throwable) {
			// Legacy slug not taken — claim it for this user.
		}

		return $this->findOrCreateRegister(
			slug: $legacyRegisterSlug,
			appSlug: $newSlug,
			ownerUid: $ownerUid,
		);
	}//end provisionPerAppRegister()

	/**
	 * Find or create a per-app register at an exact slug.
	 *
	 * @param string $slug The register slug to find/create
	 * @param string $appSlug The application slug (for the title)
	 * @param string $ownerUid The owner UID (for the description audit trail)
	 *
	 * @return \OCA\OpenRegister\Db\Register
	 */
	private function findOrCreateRegister(
		string $slug,
		string $appSlug,
		string $ownerUid,
	): \OCA\OpenRegister\Db\Register {
		try {
			return $this->registerMapper->find($slug, _multitenancy: false);
		} catch (Throwable) {
			// Not found — create it.
		}

		return $this->registerMapper->createFromArray(
			[
				'slug' => $slug,
				'title' => 'Buildiq — ' . $appSlug,
				'description' => 'Per-app schema namespace for Buildiq app `' . $appSlug . '` (owner: ' . $ownerUid . ').',
				'version' => '0.1.0',
				'schemas' => [],
			]
		);
	}//end findOrCreateRegister()

	/**
	 * Extract the owner UID from a Register entity, tolerating either an
	 * `owner` field on the entity itself or one inside an `@self` block.
	 *
	 * @param mixed $register The Register entity (or non-Register junk).
	 *
	 * @return string The owner UID, or empty string when not determinable.
	 */
	private function extractRegisterOwner(mixed $register): string {
		if (is_object($register) === true && method_exists($register, 'getOwner') === true) {
			$owner = $register->getOwner();
			if (is_string($owner) === true) {
				return $owner;
			}
		}

		if (is_object($register) === true && method_exists($register, 'jsonSerialize') === true) {
			$data = $register->jsonSerialize();
			if (is_array($data) === true) {
				$owner = ($data['owner'] ?? ($data['@self']['owner'] ?? null));
				if (is_string($owner) === true) {
					return $owner;
				}
			}
		}

		return '';
	}//end extractRegisterOwner()

	/**
	 * Clone companion schemas into the per-app register.
	 *
	 * Critical fix: companion schemas are CREATED AS SCHEMAS via SchemaMapper
	 * (NOT saved as Application objects, which was the bug at the previous
	 * line 168). The per-app register's `schemas` array is updated to include
	 * the new schema IDs.
	 *
	 * @param array<int,array<string,mixed>> $companions The companion schema blobs from the template
	 * @param array<string,string> $rewriteMap Source-slug → prefixed-slug map
	 * @param \OCA\OpenRegister\Db\Register $perAppRegister The target per-app register
	 *
	 * @return array<int,int> List of created schema IDs
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-56
	 */
	private function cloneCompanionSchemas(
		array $companions,
		array $rewriteMap,
		\OCA\OpenRegister\Db\Register $perAppRegister,
	): array {
		$createdIds = [];

		foreach ($companions as $companion) {
			$sourceSlug = (string)$companion['slug'];
			if (isset($rewriteMap[$sourceSlug]) === false) {
				continue;
			}

			$schemaPayload = $companion;
			$schemaPayload['slug'] = $rewriteMap[$sourceSlug];
			// Ensure a stable version (templates ship with their own; default to 0.1.0).
			if (isset($schemaPayload['version']) === false) {
				$schemaPayload['version'] = '0.1.0';
			}

			$schema = $this->schemaMapper->createFromArray(object: $schemaPayload);
			$createdIds[] = $schema->getId();
		}

		if ($createdIds !== []) {
			$existing = $perAppRegister->getSchemas();
			$perAppRegister->setSchemas(array_values(array_unique(array_merge($existing, $createdIds))));
			$this->registerMapper->update($perAppRegister);
		}

		return $createdIds;
	}//end cloneCompanionSchemas()

	/**
	 * Recursively rewrite manifest page-config schema references.
	 *
	 * @param mixed $node The manifest node
	 * @param array<string,string> $map Map of source-slug => prefixed-slug
	 *
	 * @return mixed The rewritten node
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-56
	 */
	private function rewriteSchemaRefs(mixed $node, array $map): mixed {
		if (is_array($node) === false) {
			return $node;
		}

		foreach ($node as $key => $value) {
			if (($key === 'schema' || $key === 'relatedSchema')
				&& is_string($value) === true
				&& isset($map[$value]) === true
			) {
				$node[$key] = $map[$value];
				continue;
			}

			if (is_array($value) === true) {
				$node[$key] = $this->rewriteSchemaRefs(node: $value, map: $map);
			}
		}

		return $node;
	}//end rewriteSchemaRefs()

	/**
	 * Look up a single object by slug (optionally scoped by owner).
	 *
	 * @param int|string $registerId The register ID
	 * @param int|string $schemaId The schema ID
	 * @param string $slug The slug to look up
	 * @param string|null $owner Optional owner UID (multi-user isolation scope)
	 *
	 * @return array<string,mixed>|null
	 */
	private function lookupOne(
		int|string $registerId,
		int|string $schemaId,
		string $slug,
		?string $owner = null,
	): ?array {
		try {
			$query = [
				'@self' => [
					'register' => $registerId,
					'schema' => $schemaId,
				],
				'slug' => $slug,
			];

			if ($owner !== null) {
				// OR records ownership under `@self.owner`, not at the
				// top level. Placing the filter on the top-level `owner`
				// field made every owner-scoped lookup miss (#51) — the
				// slug-collision check then fell through and the org-wide
				// register-slug unique constraint raised `clone_failed`
				// instead of the documented `slug_collision`.
				$query['@self']['owner'] = $owner;
			}

			$results = $this->objectService->searchObjects(query: $query);

			if (is_array($results) === false || count($results) === 0) {
				return null;
			}

			return $this->normaliseObject(object: $results[0]);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: lookup failed', ['exception' => $e->getMessage()]);
			return null;
		}//end try
	}//end lookupOne()

	/**
	 * Coerce an OR result entry (ObjectEntity or array) to a plain associative array.
	 *
	 * FindAll() and find() may return ObjectEntity instances; we normalise to an
	 * array so the caller can use array access uniformly. Uses jsonSerialize()
	 * when present (the canonical ObjectEntity surface).
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
