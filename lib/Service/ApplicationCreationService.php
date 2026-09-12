<?php

/**
 * Buildiq ApplicationCreationService
 *
 * Owns the atomic creation flow for the `POST /api/applications/wizard`
 * endpoint (spec `buildiq-app-creation-wizard`, REQ-OBWIZ-007 through
 * REQ-OBWIZ-010).
 *
 * Flow per Decision 7 of the design:
 *   1. Validate the whole payload (slugs, chain, app-slug uniqueness).
 *   2. Create the Application record (caller becomes sole owner).
 *   3. For each version in chain order:
 *      a. Create the ApplicationVersion record.
 *      b. Provision the per-version OR register.
 *   4. Wire the `promotesTo` chain on non-terminal versions.
 *   5. Set Application.productionVersion to the terminal version.
 *
 * On any failure at any step: roll back in reverse creation order —
 * registers first, then ApplicationVersion rows, then Application row.
 * Rollback is best-effort; failures during rollback are logged and
 * accumulated in the WizardCreationException's orphanedResources list.
 *
 * All persistence flows through OpenRegister abstractions (ADR-022).
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-9
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-10
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-11
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-12
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-13
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-14
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-15
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Exception\WizardCreationException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RegisterService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Atomic creation orchestrator for the app-creation wizard.
 *
 * This is an ADR-031 §Exceptions(3) imperative surface — the OR layer has
 * no transaction that spans Application, N ApplicationVersion rows, and
 * N register provisions simultaneously, so we implement a careful-sequencing
 * + reverse-delete rollback strategy.
 */
class ApplicationCreationService {
	/**
	 * Four canonical presets and their hardcoded version chains.
	 * Each entry is [name => string, slug => string] in chain order
	 * (upstream → downstream).
	 *
	 * @var array<string,array<int,array<string,string>>>
	 */
	private const PRESET_CHAINS = [
		'single' => [
			['name' => 'Production', 'slug' => 'production'],
		],
		'dev-prod' => [
			['name' => 'Development', 'slug' => 'development'],
			['name' => 'Production',  'slug' => 'production'],
		],
		'dev-staging-prod' => [
			['name' => 'Development', 'slug' => 'development'],
			['name' => 'Staging',     'slug' => 'staging'],
			['name' => 'Production',  'slug' => 'production'],
		],
	];

	/**
	 * Default semver for every wizard-provisioned ApplicationVersion.
	 */
	private const INITIAL_SEMVER = '0.1.0';

	/**
	 * Schema slug for the BuiltAppRoute index object.
	 *
	 * The `getManifest`/`resolveApplicationBySlug` lookup path resolves a
	 * virtual-app slug to its Application UUID through a BuiltAppRoute object
	 * (one per published app). Without it the manifest endpoint returns 404
	 * `not_found` for every wizard-built app, so the wizard MUST create it.
	 */
	private const BUILT_APP_ROUTE_SCHEMA = 'built-app-route';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger for diagnostics
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 * @param RegisterService $registerService OpenRegister register-level service
	 * @param RegisterMapper $registerMapper Resolves register slugs
	 * @param SchemaMapper $schemaMapper Resolves schema slugs
	 * @param IUserSession $userSession Current Nextcloud user session
	 * @param SlugValidator $slugValidator Slug validation helper
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterService $registerService,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly IUserSession $userSession,
		private readonly SlugValidator $slugValidator,
	) {
	}//end __construct()

	/**
	 * Execute the full atomic creation flow for the wizard payload.
	 *
	 * @param array<string,mixed> $payload The wizard POST payload (validated internally)
	 *
	 * @return string The newly-created Application's UUID
	 *
	 * @throws WizardCreationException On validation failure (failedAtStep=validate)
	 *                                 or on any mid-flight creation failure (with rollback)
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-12
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-13
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-14
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-15
	 */
	public function createApplication(array $payload): string {
		// M2: Acquire a per-slug advisory lock before the slug-uniqueness check so
		// the check and the saveObject are atomic with respect to concurrent callers
		// for the same slug (issue #163 — TOCTOU).  The lock key is intentionally
		// NOT an object UUID (no application exists yet) so we prefix with "slug:"
		// to avoid colliding with real object locks in the OR lock table.
		$appSlug = (string)($payload['slug'] ?? '');
		$lockKey = 'createApp:' . $appSlug;
		$locked = false;

		if ($appSlug !== '') {
			try {
				$this->objectService->lockObject(
					identifier: $lockKey,
					process: 'buildiq.createApplication',
					duration: 10,
					advisory: true
				);
				$locked = true;
			} catch (Throwable $lockError) {
				throw new WizardCreationException(
					errorCode: 'app_slug_conflict',
					failedAtStep: 'validate',
					message: sprintf(
						'An Application with slug "%s" is being created by another request.',
						$appSlug
					),
					rollbackStatus: 'none',
					previous: $lockError
				);
			}
		}//end if

		try {
			// ---- Step 1: Validate -----------------------------------------------
			$this->validatePayload(payload: $payload);
			$appName = (string)($payload['name'] ?? '');
			$description = (string)($payload['description'] ?? '');
			$versions = $this->resolveVersionChain(payload: $payload);

			// ---- State tracker for rollback -------------------------------------
			// Indexed by version slug.
			$state = [
				'applicationUuid' => null,
				'versionUuids' => [],
				'registerSlugs' => [],
				'versionPayloads' => [],
				'routeUuids' => [],
			];

			// ---- Step 2: Create Application -------------------------------------
			$caller = $this->resolveCallerUid();
			$permissions = [
				'owners' => ['user:' . $caller],
				'editors' => [],
				'viewers' => [],
			];

			$applicationPayload = [
				'slug' => $appSlug,
				'name' => $appName,
				'description' => $description,
				'permissions' => $permissions,
			];

			try {
				$created = $this->objectService->saveObject(
					object: $applicationPayload,
					register: ApplicationVersionService::REGISTER_SLUG,
					schema: ApplicationVersionService::APPLICATION_SCHEMA
				);
				$appData = $this->normaliseObject(object: $created);
				$state['applicationUuid'] = (string)($appData['id'] ?? $appData['uuid'] ?? '');
			} catch (Throwable $e) {
				// Detect TOCTOU duplicate-slug races: two concurrent createApp calls both
				// passed appSlugExists() then both tried saveObject — the second one will
				// receive a unique-constraint violation (issue #163).
				$errorMsg = $e->getMessage();
				$isSlugConflict = (
					str_contains($errorMsg, 'Duplicate')
					|| str_contains($errorMsg, 'duplicate')
					|| str_contains($errorMsg, 'unique constraint')
					|| str_contains($errorMsg, 'UNIQUE')
				);
				if ($isSlugConflict === true) {
					throw new WizardCreationException(
						errorCode: 'app_slug_conflict',
						failedAtStep: 'validate',
						message: sprintf('An Application with slug "%s" already exists.', $appSlug),
						rollbackStatus: 'none',
						previous: $e
					);
				}

				$this->logger->error(
					'Buildiq: wizard create-application failed for slug ' . $appSlug . ': ' . $errorMsg,
					['exception' => $e]
				);
				throw new WizardCreationException(
					errorCode: 'wizard_rollback',
					failedAtStep: 'create-application',
					message: $errorMsg,
					rollbackStatus: 'complete',
					previous: $e
				);
			}//end try

			if ($state['applicationUuid'] === '') {
				$this->rollbackAndThrow(
					state: $state,
					failedAtStep: 'create-application',
					cause: 'Application record was not assigned a UUID by OR.'
				);
			}

			// ---- Step 3: Create ApplicationVersions + provision registers -------
			$defaultManifest = $this->loadDefaultManifest();
			$defaultSchemas = $this->loadDefaultSchemas();

			foreach ($versions as $versionDef) {
				$versionSlug = (string)($versionDef['slug'] ?? '');
				$versionName = (string)($versionDef['name'] ?? '');
				// Prefix from the constant, never typed: see
				// ApplicationVersionService::VERSION_REGISTER_PREFIX.
				$registerSlug = ApplicationVersionService::VERSION_REGISTER_PREFIX . $appSlug . '-' . $versionSlug;

				// 3a: Create ApplicationVersion
				$versionManifest = $this->substituteVersionContext(
					manifest: $defaultManifest,
					registerSlug: $registerSlug,
					schemaSlugPrefix: $appSlug . '-' . $versionSlug . '-'
				);

				$versionPayload = [
					'name' => $versionName,
					'slug' => $versionSlug,
					'manifest' => $versionManifest,
					'register' => $registerSlug,
					'semver' => self::INITIAL_SEMVER,
					'status' => 'draft',
					'application' => $state['applicationUuid'],
				];

				try {
					$createdVersion = $this->objectService->saveObject(
						object: $versionPayload,
						register: ApplicationVersionService::REGISTER_SLUG,
						schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA
					);
					$versionData = $this->normaliseObject(object: $createdVersion);
					$versionUuid = (string)($versionData['id'] ?? $versionData['uuid'] ?? '');
					$state['versionUuids'][$versionSlug] = $versionUuid;
					$state['registerSlugs'][$versionSlug] = $registerSlug;
					// Keep the full payload so we can do the chain-wiring patch
					// below without OR rejecting a partial payload against the
					// schema's `required[]` validator (issue #71).
					$state['versionPayloads'][$versionSlug] = $versionPayload;
				} catch (Throwable $e) {
					$this->logger->error(
						'Buildiq: wizard create-version failed for ' . $versionSlug . ': ' . $e->getMessage(),
						['exception' => $e]
					);
					$this->rollbackAndThrow(state: $state, failedAtStep: 'create-version-' . $versionSlug, cause: $e);
				}//end try

				// 3b: Provision per-version register
				try {
					$this->provisionRegister(
						registerSlug: $registerSlug,
						appSlug: $appSlug,
						versionSlug: $versionSlug,
						defaultSchemas: $defaultSchemas
					);
				} catch (Throwable $e) {
					$this->logger->error(
						'Buildiq: wizard register-provision failed for ' . $registerSlug . ': ' . $e->getMessage(),
						['exception' => $e]
					);
					$this->rollbackAndThrow(state: $state, failedAtStep: 'register-provision-' . $versionSlug, cause: $e);
				}//end try
			}//end foreach

			// ---- Step 4: Wire promotesTo chain ----------------------------------
			$versionSlugs = array_column($versions, 'slug');
			$lastIdx = count($versionSlugs) - 1;

			for ($i = 0; $i < $lastIdx; $i++) {
				$currentSlug = $versionSlugs[$i];
				$nextSlug = $versionSlugs[$i + 1];

				$currentUuid = (string)($state['versionUuids'][$currentSlug] ?? '');
				$nextUuid = (string)($state['versionUuids'][$nextSlug] ?? '');

				if ($currentUuid === '' || $nextUuid === '') {
					continue;
				}

				try {
					// OR's saveObject runs full-schema validation against `required[]`
					// even when a UUID is passed (no separate PATCH semantics for an
					// ApplicationVersion in this OR floor). Merge `promotesTo` into
					// the full payload we kept from the create step so the validator
					// sees all required fields.
					$fullPayload = ($state['versionPayloads'][$currentSlug] ?? []);
					$fullPayload['promotesTo'] = $nextUuid;

					$this->objectService->saveObject(
						object: $fullPayload,
						register: ApplicationVersionService::REGISTER_SLUG,
						schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA,
						uuid: $currentUuid
					);
				} catch (Throwable $e) {
					$this->logger->error(
						'Buildiq: wizard chain-wiring failed for ' . $currentSlug . ' → ' . $nextSlug . ': ' . $e->getMessage(),
						['exception' => $e]
					);
					$this->rollbackAndThrow(state: $state, failedAtStep: 'wire-chain-' . $currentSlug . '-to-' . $nextSlug, cause: $e);
				}//end try
			}//end for

			// ---- Step 5: Set productionVersion on Application -------------------
			$terminalSlug = $versionSlugs[$lastIdx];
			$terminalUuid = (string)($state['versionUuids'][$terminalSlug] ?? '');

			try {
				// OR runs full-schema validation on saveObject even with UUID set;
				// build a full Application payload with the productionVersion field
				// patched, mirroring the chain-wiring fix above (issue #71).
				$fullPayload = [
					'slug' => $appSlug,
					'name' => $appName,
					'description' => $description,
					'permissions' => [
						'owners' => ['user:' . $this->resolveCallerUid()],
						'editors' => [],
						'viewers' => [],
					],
					'productionVersion' => $terminalUuid,
				];

				$this->objectService->saveObject(
					object: $fullPayload,
					register: ApplicationVersionService::REGISTER_SLUG,
					schema: ApplicationVersionService::APPLICATION_SCHEMA,
					uuid: $state['applicationUuid']
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'Buildiq: wizard set-productionVersion failed: ' . $e->getMessage(),
					['exception' => $e]
				);
				$this->rollbackAndThrow(state: $state, failedAtStep: 'set-production-version', cause: $e);
			}//end try

			// ---- Step 6: Publish the BuiltAppRoute index -----------------------
			// The manifest endpoint (ApplicationsController::getManifest →
			// resolveApplicationBySlug) resolves a virtual-app slug to its
			// Application UUID through a BuiltAppRoute object. Without this step
			// the wizard-built app is unreachable by slug and the manifest
			// endpoint returns 404 `not_found` even though the app, its versions
			// and its productionVersion pointer all exist (buildiq publish gap).
			try {
				$createdRoute = $this->objectService->saveObject(
					object: ['slug' => $appSlug, 'applicationUuid' => $state['applicationUuid']],
					register: ApplicationVersionService::REGISTER_SLUG,
					schema: self::BUILT_APP_ROUTE_SCHEMA
				);
				$routeData = $this->normaliseObject(object: $createdRoute);
				$routeUuid = (string)($routeData['id'] ?? $routeData['uuid'] ?? '');
				if ($routeUuid !== '') {
					$state['routeUuids'][] = $routeUuid;
				}
			} catch (Throwable $e) {
				$this->logger->error(
					'Buildiq: wizard built-app-route publish failed for slug ' . $appSlug . ': ' . $e->getMessage(),
					['exception' => $e]
				);
				$this->rollbackAndThrow(state: $state, failedAtStep: 'publish-route', cause: $e);
			}//end try

			$versionCount = count($versions);
			$this->logger->info(
				'Buildiq: wizard successfully created Application ' . $appSlug
				. ' (uuid: ' . $state['applicationUuid'] . ') with ' . $versionCount . ' version(s).'
			);

			return $state['applicationUuid'];
		} finally {
			// M2: Release the per-slug advisory lock regardless of outcome.
			if ($locked === true) {
				try {
					$this->objectService->unlockObject(identifier: $lockKey, advisory: true);
				} catch (Throwable $unlockError) {
					$this->logger->warning(
						'Buildiq: failed to release slug lock ' . $lockKey,
						['exception' => $unlockError->getMessage()]
					);
				}
			}
		}//end try

	}//end createApplication()

	/**
	 * Validate the wizard payload before any persistence.
	 *
	 * @param array<string,mixed> $payload The wizard POST payload
	 *
	 * @return void
	 *
	 * @throws WizardCreationException With failedAtStep=validate on any failure
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-10
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-11
	 */
	private function validatePayload(array $payload): void {
		$appSlug = (string)($payload['slug'] ?? '');
		$appName = (string)($payload['name'] ?? '');

		if ($appName === '') {
			throw new WizardCreationException(
				errorCode: 'validation_error',
				failedAtStep: 'validate',
				message: 'Application name must not be empty.',
				rollbackStatus: 'none'
			);
		}

		$slugError = $this->slugValidator->validateAppSlug(slug: $appSlug);
		if ($slugError !== []) {
			throw new WizardCreationException(
				errorCode: 'validation_error',
				failedAtStep: 'validate',
				message: (string)($slugError['message'] ?? 'Invalid application slug.'),
				rollbackStatus: 'none'
			);
		}

		// Validate preset or custom versions.
		$versions = $this->resolveVersionChain(payload: $payload);

		if ($versions === []) {
			throw new WizardCreationException(
				errorCode: 'validation_error',
				failedAtStep: 'validate',
				message: 'At least one version is required.',
				rollbackStatus: 'none'
			);
		}

		// Validate each version slug.
		foreach ($versions as $versionDef) {
			$versionSlug = (string)($versionDef['slug'] ?? '');
			$versionName = (string)($versionDef['name'] ?? '');

			if ($versionName === '') {
				throw new WizardCreationException(
					errorCode: 'validation_error',
					failedAtStep: 'validate',
					message: 'Version name must not be empty.',
					rollbackStatus: 'none'
				);
			}

			$slugError = $this->slugValidator->validateVersionSlug(slug: $versionSlug);
			if ($slugError !== []) {
				throw new WizardCreationException(
					errorCode: (string)($slugError['code'] ?? 'validation_error'),
					failedAtStep: 'validate',
					message: (string)($slugError['message'] ?? 'Invalid version slug.'),
					rollbackStatus: 'none'
				);
			}
		}//end foreach

		// Validate no duplicate version slugs in chain.
		$slugList = array_column($versions, 'slug');
		$chainError = $this->slugValidator->validateChainSlugs(slugs: $slugList);
		if ($chainError !== []) {
			throw new WizardCreationException(
				errorCode: 'duplicate_version_slug',
				failedAtStep: 'validate',
				message: sprintf(
					'Duplicate version slug "%s" at rows [%s].',
					$chainError['slug'] ?? '',
					implode(', ', (array)($chainError['rows'] ?? []))
				),
				rollbackStatus: 'none'
			);
		}

		// Check app slug uniqueness across existing Applications.
		if ($this->appSlugExists(slug: $appSlug) === true) {
			throw new WizardCreationException(
				errorCode: 'app_slug_conflict',
				failedAtStep: 'validate',
				message: sprintf('An Application with slug "%s" already exists.', $appSlug),
				rollbackStatus: 'none'
			);
		}
	}//end validatePayload()

	/**
	 * Resolve the version chain from the payload.
	 *
	 * For canned presets, returns the hardcoded chain; for `custom` returns
	 * the versions array from the payload.
	 *
	 * @param array<string,mixed> $payload The wizard POST payload
	 *
	 * @return array<int,array<string,string>> Version definitions [{name, slug}, ...]
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-9
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-11
	 */
	public function resolveVersionChain(array $payload): array {
		$preset = (string)($payload['preset'] ?? '');

		if (isset(self::PRESET_CHAINS[$preset]) === true) {
			return self::PRESET_CHAINS[$preset];
		}

		// Custom preset — use the versions array.
		$versions = $payload['versions'] ?? [];
		if (is_array($versions) === false) {
			return [];
		}

		$result = [];
		foreach ($versions as $v) {
			if (is_array($v) === false) {
				continue;
			}

			$result[] = [
				'name' => (string)($v['name'] ?? ''),
				'slug' => (string)($v['slug'] ?? ''),
			];
		}

		return $result;
	}//end resolveVersionChain()

	/**
	 * Check whether an Application with the given slug already exists.
	 *
	 * @param string $slug The slug to check
	 *
	 * @return bool True when a conflicting row exists
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-10
	 */
	private function appSlugExists(string $slug): bool {
		try {
			// Pin _multitenancy: false throughout so all three calls operate in
			// the same scope and a slug taken in one org cannot appear free to
			// another (issue #163 — cross-org multitenancy flag mismatch).
			$registerId = $this->registerMapper->find(
				ApplicationVersionService::REGISTER_SLUG,
				_multitenancy: false
			)->getId();
			$schemaId = $this->schemaMapper->find(
				ApplicationVersionService::APPLICATION_SCHEMA,
				_multitenancy: false
			)->getId();

			// `_rbac` and `_multitenancy` are NAMED PARAMETERS of searchObjects
			// (`searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true, ...)`),
			// not query keys. `_multitenancy` used to be passed inside `@self`,
			// which failed twice over: the real parameter kept its `true`
			// default, AND `@self._multitenancy` became a filter condition on a
			// field that does not exist, so the search matched nothing. The
			// check therefore reported every slug as free and the wizard
			// answered 201 Created for an already-taken slug instead of 422
			// app_slug_conflict — which is what littered this instance with
			// duplicate `hello-world` Applications and made OpenRegister's
			// find-by-slug 500 on the ambiguous result.
			//
			// `_rbac: false` matters just as much here: application slugs are a
			// GLOBAL namespace (OR resolves objects by slug without an owner
			// scope), but the seeded `hello-world` is owned by `__system__`, so
			// an RBAC-filtered search run as `admin` would not see it and would
			// again report the slug as free. A uniqueness check must see every
			// row, exactly as AppNavigationService does when it enumerates apps.
			$rows = $this->objectService->searchObjects(
				query: [
					'@self' => [
						'register' => $registerId,
						'schema' => $schemaId,
					],
					'slug' => $slug,
				],
				_rbac: false,
				_multitenancy: false
			);

			return is_array($rows) === true && $rows !== [];
		} catch (Throwable $e) {
			// M3: Re-throw on driver error so a failing uniqueness check does NOT
			// silently fall open.  A broken OR/DB connection must never be treated as
			// "no conflict" — callers must get a clear error rather than a potential
			// duplicate-creation.
			throw new WizardCreationException(
				errorCode: 'slug_check_failed',
				failedAtStep: 'validate',
				message: 'Could not verify slug uniqueness: ' . $e->getMessage(),
				rollbackStatus: 'none',
				previous: $e
			);
		}//end try
	}//end appSlugExists()

	/**
	 * Provision a per-version OR register and seed its schema set.
	 *
	 * @param string $registerSlug The register slug to create
	 * @param string $appSlug Parent application slug (for labels)
	 * @param string $versionSlug Version slug (for labels)
	 * @param array<int,array<string,mixed>> $defaultSchemas Seed schema blobs from default-schemas.json
	 *
	 * @return void
	 *
	 * @throws Throwable When register creation or schema seeding fails
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-13
	 */
	private function provisionRegister(
		string $registerSlug,
		string $appSlug,
		string $versionSlug,
		array $defaultSchemas,
	): void {
		$register = $this->registerMapper->createFromArray(
			[
				'slug' => $registerSlug,
				'title' => 'Buildiq — ' . $appSlug . ' (' . $versionSlug . ')',
				'description' => 'Per-version schema namespace for Buildiq app `' . $appSlug . '` version `' . $versionSlug . '`.',
				'version' => '0.1.0',
				'schemas' => [],
			]
		);

		// Seed the default schema set into the freshly-provisioned register.
		// Schema slugs are unique per organisation, so namespace each seed slug
		// with the app+version prefix to avoid colliding with the same seed
		// already installed in another register (e.g. the global `buildiq`
		// register or another wizard-provisioned app's register).
		$slugPrefix = $appSlug . '-' . $versionSlug . '-';
		$createdIds = [];
		foreach ($defaultSchemas as $schemaBlob) {
			$blob = $schemaBlob;
			$originalSlug = (string)($blob['slug'] ?? '');
			if ($originalSlug !== '') {
				$blob['slug'] = $slugPrefix . $originalSlug;
			}

			$schema = $this->schemaMapper->createFromArray(object: $blob);
			$createdIds[] = $schema->getId();
		}

		if ($createdIds !== []) {
			$existing = $register->getSchemas();
			if (is_array($existing) === false) {
				$existing = [];
			}

			$register->setSchemas(array_values(array_unique(array_merge($existing, $createdIds))));
			$this->registerMapper->update($register);
		}
	}//end provisionRegister()

	/**
	 * Delete a per-version register as part of rollback.
	 *
	 * Returns false on failure so the caller can accumulate orphaned resources.
	 *
	 * @param string $registerSlug The OR register slug to drop
	 *
	 * @return bool True on success, false on failure
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-12
	 */
	private function deleteRegister(string $registerSlug): bool {
		try {
			$register = $this->registerMapper->find($registerSlug, _multitenancy: false);
			$this->registerService->delete(register: $register);
			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: wizard rollback failed to delete register ' . $registerSlug . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return false;
		}
	}//end deleteRegister()

	/**
	 * Roll back everything created so far, in reverse creation order.
	 *
	 * Reverse order: registers (last created first), then ApplicationVersion
	 * rows, then the Application row.
	 *
	 * Each rollback step is wrapped in try/catch; failures are logged and
	 * appended to `$orphaned` (passed by reference) rather than aborting
	 * the remaining rollback.
	 *
	 * @param array<string,mixed> $state Creation state tracker
	 * @param array<int,string> $orphaned Accumulates resources that could not be cleaned (by ref)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-12
	 */
	private function rollback(array $state, array &$orphaned): void {
		// 0. BuiltAppRoute objects first (last created, first torn down).
		$this->rollbackObjects(
			uuids: (array)($state['routeUuids'] ?? []),
			label: 'BuiltAppRoute',
			prefix: 'route:',
			orphaned: $orphaned
		);

		// 1. Delete registers (reverse order of creation).
		$registerSlugs = array_reverse(array_values((array)($state['registerSlugs'] ?? [])));
		foreach ($registerSlugs as $registerSlug) {
			if ($this->deleteRegister(registerSlug: (string)$registerSlug) === false) {
				$orphaned[] = (string)$registerSlug;
			}
		}

		// 2. Delete ApplicationVersion rows (reverse order of creation).
		$this->rollbackObjects(
			uuids: (array)($state['versionUuids'] ?? []),
			label: 'ApplicationVersion',
			prefix: 'version:',
			orphaned: $orphaned
		);

		// 3. Delete Application row.
		$this->rollbackObjects(
			uuids: [(string)($state['applicationUuid'] ?? '')],
			label: 'Application',
			prefix: 'application:',
			orphaned: $orphaned
		);
	}//end rollback()

	/**
	 * Roll back all created state, then throw a wizard_rollback exception.
	 *
	 * Collapses the repeated "best-effort rollback → derive partial/complete
	 * status → throw WizardCreationException" boilerplate shared by every
	 * mid-flight creation failure in {@see createApplication()}.
	 *
	 * @param array<string,mixed> $state Creation state tracker
	 * @param string $failedAtStep The step identifier for the exception
	 * @param string|Throwable $cause The underlying failure, or a literal message string
	 *
	 * @return never
	 *
	 * @throws WizardCreationException Always — with the rollback status derived from orphaned resources
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-12
	 */
	private function rollbackAndThrow(array $state, string $failedAtStep, string|Throwable $cause): never {
		$orphaned = [];
		$this->rollback(state: $state, orphaned: $orphaned);
		$status = 'complete';
		if ($orphaned !== []) {
			$status = 'partial';
		}

		$message = $cause;
		$previous = null;
		if (is_string($cause) === false) {
			$message = $cause->getMessage();
			$previous = $cause;
		}

		throw new WizardCreationException(
			errorCode: 'wizard_rollback',
			failedAtStep: $failedAtStep,
			message: $message,
			rollbackStatus: $status,
			orphanedResources: $orphaned,
			previous: $previous
		);
	}//end rollbackAndThrow()

	/**
	 * Best-effort delete a list of OR objects by UUID as part of rollback.
	 *
	 * Iterates in reverse creation order, skipping empty UUIDs. Each failure is
	 * logged and the UUID (prefixed) appended to $orphaned rather than aborting
	 * the remaining deletes.
	 *
	 * @param array<int|string,mixed> $uuids UUIDs to delete (creation order; reversed here)
	 * @param string $label Human label for log lines (e.g. "BuiltAppRoute")
	 * @param string $prefix Orphan-list prefix (e.g. "route:")
	 * @param array<int,string> $orphaned Accumulates un-deletable UUIDs (by ref)
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-12
	 */
	private function rollbackObjects(array $uuids, string $label, string $prefix, array &$orphaned): void {
		$ordered = array_reverse(array_values($uuids));
		foreach ($ordered as $candidate) {
			$uuid = (string)$candidate;
			if ($uuid === '') {
				continue;
			}

			try {
				$this->objectService->deleteObject(uuid: $uuid);
			} catch (Throwable $e) {
				$this->logger->error(
					'Buildiq: wizard rollback failed to delete ' . $label . ' ' . $uuid . ': ' . $e->getMessage(),
					['exception' => $e]
				);
				$orphaned[] = $prefix . $uuid;
			}
		}
	}//end rollbackObjects()

	/**
	 * Load the default manifest from the static fixture file.
	 *
	 * @return array<string,mixed> The parsed manifest blob
	 *
	 * @throws WizardCreationException When the fixture cannot be read or decoded
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-14
	 */
	private function loadDefaultManifest(): array {
		$path = __DIR__ . '/../Resources/wizard/default-manifest.json';
		if (file_exists($path) === false) {
			throw new WizardCreationException(
				errorCode: 'wizard_rollback',
				failedAtStep: 'load-default-manifest',
				message: 'Default manifest fixture not found at ' . $path,
				rollbackStatus: 'none'
			);
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new WizardCreationException(
				errorCode: 'wizard_rollback',
				failedAtStep: 'load-default-manifest',
				message: 'Could not read default manifest fixture.',
				rollbackStatus: 'none'
			);
		}

		$decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
		if (is_array($decoded) === false) {
			throw new WizardCreationException(
				errorCode: 'wizard_rollback',
				failedAtStep: 'load-default-manifest',
				message: 'Default manifest fixture is not a JSON object.',
				rollbackStatus: 'none'
			);
		}

		return $decoded;
	}//end loadDefaultManifest()

	/**
	 * Load the default schema set from the static fixture file.
	 *
	 * @return array<int,array<string,mixed>> The parsed schema blobs
	 *
	 * @throws WizardCreationException When the fixture cannot be read or decoded
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-13
	 */
	private function loadDefaultSchemas(): array {
		$path = __DIR__ . '/../Resources/wizard/default-schemas.json';
		if (file_exists($path) === false) {
			throw new WizardCreationException(
				errorCode: 'wizard_rollback',
				failedAtStep: 'load-default-schemas',
				message: 'Default schemas fixture not found at ' . $path,
				rollbackStatus: 'none'
			);
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new WizardCreationException(
				errorCode: 'wizard_rollback',
				failedAtStep: 'load-default-schemas',
				message: 'Could not read default schemas fixture.',
				rollbackStatus: 'none'
			);
		}

		$decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
		if (is_array($decoded) === false) {
			throw new WizardCreationException(
				errorCode: 'wizard_rollback',
				failedAtStep: 'load-default-schemas',
				message: 'Default schemas fixture is not a JSON array.',
				rollbackStatus: 'none'
			);
		}

		return $decoded;
	}//end loadDefaultSchemas()

	/**
	 * Substitute the `{registerSlug}` placeholder in a manifest template.
	 *
	 * Walks through all `pages[*].config.register` fields and replaces the
	 * template token with the actual per-version register slug.
	 *
	 * @param array<string,mixed> $manifest The manifest template blob
	 * @param string $registerSlug The per-version register slug
	 *
	 * @return array<string,mixed> The manifest with the token substituted
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-13
	 */
	public function substituteRegisterSlug(array $manifest, string $registerSlug): array {
		return $this->substituteVersionContext(
			manifest: $manifest,
			registerSlug: $registerSlug,
			schemaSlugPrefix: ''
		);
	}//end substituteRegisterSlug()

	/**
	 * Substitute the per-version context tokens in a manifest template.
	 *
	 * Walks all `pages[*].config` blocks. Replaces the `{registerSlug}`
	 * token in `register` and rewrites every non-empty `config.schema`
	 * to the namespaced seed slug `{schemaSlugPrefix}{originalSchemaSlug}`
	 * so the manifest references the actual per-version schemas created
	 * by {@see provisionRegister()} (buildiq#75 — without this the
	 * KPI / insights cards aggregated against a non-existent schema and
	 * leaked the same numbers across all tiers).
	 *
	 * @param array<string,mixed> $manifest The manifest template blob
	 * @param string $registerSlug The per-version register slug
	 * @param string $schemaSlugPrefix Namespaced prefix for schema slugs (e.g. `permit-flow-development-`)
	 *
	 * @return array<string,mixed> The manifest with tokens substituted
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-14
	 */
	public function substituteVersionContext(
		array $manifest,
		string $registerSlug,
		string $schemaSlugPrefix,
	): array {
		if (isset($manifest['pages']) === false || is_array($manifest['pages']) === false) {
			return $manifest;
		}

		foreach ($manifest['pages'] as &$page) {
			if (is_array($page) === false) {
				continue;
			}

			if (isset($page['config']) === false || is_array($page['config']) === false) {
				continue;
			}

			if (isset($page['config']['register']) === true && $page['config']['register'] === '{registerSlug}') {
				$page['config']['register'] = $registerSlug;
			}

			if ($schemaSlugPrefix !== ''
				&& isset($page['config']['schema']) === true
				&& is_string($page['config']['schema']) === true
				&& $page['config']['schema'] !== ''
				&& str_starts_with($page['config']['schema'], $schemaSlugPrefix) === false
			) {
				$page['config']['schema'] = $schemaSlugPrefix . $page['config']['schema'];
			}
		}//end foreach

		unset($page);
		return $manifest;
	}//end substituteVersionContext()

	/**
	 * Get the UID of the currently authenticated user.
	 *
	 * @return string The user UID, or 'unknown' when no session is active
	 */
	private function resolveCallerUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'unknown';
		}

		return $user->getUID();
	}//end resolveCallerUid()

	/**
	 * Coerce an OR result entry to a plain associative array.
	 *
	 * @param mixed $object The OR object / result entry
	 *
	 * @return array<string,mixed>
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
