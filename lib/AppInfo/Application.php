<?php

/**
 * Buildiq Application
 *
 * Main application class for the Buildiq Nextcloud app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppInfo
 * @package  OCA\Buildiq\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\AppInfo;

use OCA\Buildiq\Capabilities;
use OCA\Buildiq\Controller\DashboardController;
use OCA\Buildiq\Controller\PreferencesController;
use OCA\Buildiq\Controller\SettingsController;
use OCA\Buildiq\Lifecycle\ApplicationVersionOwnerGuard;
use OCA\Buildiq\Listener\ApprovalOutcomeListener;
use OCA\Buildiq\Listener\AutomationApprovalTriggerListener;
use OCA\Buildiq\Listener\AutomationCleanupListener;
use OCA\Buildiq\Listener\DocumentGenerationListener;
use OCA\Buildiq\Listener\HybridMetadataLockListener;
use OCA\Buildiq\Listener\ProductionVersionGuardListener;
use OCA\Buildiq\Mcp\BuildiqToolProvider;
use OCA\Buildiq\Repair\InitializeSettings;
use OCA\Buildiq\Sections\SettingsSection;
use OCA\Buildiq\Service\AppNavigationService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\SettingsService;
use OCA\Buildiq\Settings\AdminSettings;
use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Main application class for the Buildiq Nextcloud app.
 *
 * @spec openspec/specs/openbuild-rbac/spec.md
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'buildiq';

	/**
	 * Constructor for the Application class.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register event listeners and services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/openbuild-rbac/spec.md
	 */
	public function register(IRegistrationContext $context): void {

		// ADR-084: services type-hint OpenRegister's PUBLISHED interface, never its
		// concrete class, so this app's unit tests can mock a type they are able to
		// load. Nextcloud autowires concrete classes across apps but not interfaces,
		// so the binding has to be stated — and the composition root is where this
		// app says how it is wired.
		//
		// An ALIAS, not a factory: it resolves when something actually asks for the
		// interface, so an instance without OpenRegister fails at the route that
		// needed the data rather than at registration. Both names are strings and
		// neither triggers an autoload, which is what keeps ADR-083 rule 3's promise
		// that the start screen still boots.
		$context->registerServiceAlias(
			ObjectServiceInterface::class,
			'OCA\OpenRegister\Service\ObjectService'
		);

		// The register-slug resolver, bound the same way and for the same reason.
		//
		// Register slugs live in `openregister_registers`, and nine fleet apps
		// ship a repair step that renames theirs. The step is per instance, so
		// both slugs are live across the estate at once and a literal is wrong
		// on half of it. The old-slug case is the quiet one: OpenRegister finds
		// no register, matches no rows, and returns an empty set that is
		// byte-for-byte what a healthy empty register returns. No exception, no
		// 404, no log line. This app read the connectors channel that way.
		//
		// Verified against this container, not assumed: OpenRegister registers
		// the resolver in its OWN container, so nothing of that registration
		// reaches here. What reaches here is the alias stated here plus autowiring
		// of the concrete class, whose only dependencies are `RegisterMapper`
		// and `LoggerInterface`. Both resolve from a leaf app's DIContainer, and
		// the interface then answers with a live resolution. The one thing lost
		// is OpenRegister's shared-instance registration: a leaf container
		// autowires a fresh resolver per injection point, so the request-scoped
		// memo is per consumer rather than per request. That costs one indexed
		// read per consumer and changes no answer.
		$context->registerServiceAlias(
			RegisterSlugResolverInterface::class,
			'OCA\OpenRegister\Service\RegisterSlugResolver'
		);

		// ADR-040 AppHost adoption: one call wires the standard plumbing —
		// the generic dashboard/settings/preferences controllers, the
		// observability (health + metrics) controllers, the install repair
		// steps, the admin settings panel + section, and the manifest-driven
		// deep-link listener (reads src/manifest.json `deepLinks`, replacing
		// the bespoke DeepLinkRegistrationListener that was deleted).
		//
		// Every Bootstrap registration is a lazy closure, so a disabled/absent
		// OpenRegister never fatals NC bootstrap (see Bootstrap docblock).
		//
		// `mcpProvider` is wired below explicitly (not via Bootstrap) because
		// the domain registrations that follow override the relevant aliases.
		//
		// GUARDED: `Bootstrap::register` is an eager static call that fatals if
		// OpenRegister's AppHost engine class is not autoloadable on this
		// instance (e.g. a stale optimized/authoritative composer classmap that
		// predates AppHost, or a stub OCA\OpenRegister PSR-4 prefix). That fatal
		// would abort the WHOLE register() method BEFORE the domain listeners
		// below — silently disabling the per-Application RBAC guards and the
		// hybrid metadata-lock (so a hybrid app's immutable identity fields would
		// become writable). Skip the generic AppHost plumbing when it is absent
		// and fall through to Buildiq's own concrete controllers + listeners,
		// which is what this app actually relies on (the comment that the lazy
		// closures "never fatal NC bootstrap" only held for the closures, not for
		// resolving the Bootstrap class itself).
		//
		// LOAD-ORDER HAZARD (measured, not theoretical). OC_App::getEnabledApps()
		// sort()s the app list, and Coordinator::registerApps() walks THAT sorted
		// list calling OC_App::registerAutoloading($appId) and then $app->register()
		// for one app at a time. So every app registers before the PSR-4 prefix of
		// every alphabetically-LATER app exists: `buildiq` < `openregister`, so
		// OCA\OpenRegister\ is not autoloadable at this point on a perfectly
		// healthy instance with OpenRegister enabled.
		//
		// That is what actually happened here, under the CLI SAPI: `Buildiq:
		// OpenRegister AppHost\Bootstrap is not autoloadable` was logged on EVERY
		// occ call in CI (3 per E2E run, run 31081906401) while
		// lib/AppHost/Bootstrap.php existed on OpenRegister all along. The
		// class_exists() guard below turned a fatal into a SILENT degradation, so
		// for every occ command, background job and repair step the generic
		// dashboard/settings/preferences controllers, the observability
		// controllers, the install repair steps and the deep-link listener were
		// simply absent — with nothing anywhere to say so.
		//
		// Scope, honestly: in that SAME run /api/health and /api/metrics answered
		// 200, and those routes exist ONLY as Bootstrap::register() DI aliases, so
		// under the web SAPI the guard was answering true. The CLI/web divergence
		// is not explained — see REQ-OBS-006's Notes. The prelude removes the
		// dependence on whatever that difference is.
		//
		// The fix is to put OpenRegister's prefix on the autoloader ourselves,
		// which is exactly what Nextcloud will do a few iterations later. Two
		// properties make this the correct call. First,
		// OC_App::registerAutoloading() touches ONLY the autoloader and is
		// idempotent — it early-returns on an $alreadyRegistered key. Second,
		// IAppManager::loadApp() would NOT be correct here: it marks OpenRegister
		// loaded and calls Coordinator::bootApp(), booting OpenRegister BEFORE
		// its own register() has run.
		//
		// The prelude lives in its own class so the "never throws" contract is
		// reachable from a unit test — Application cannot be constructed without
		// a Nextcloud DI container. It swallows every Throwable and reports
		// nothing: when OpenRegister really is absent, the class_exists() guard
		// below still skips the AppHost plumbing exactly as before.
		OpenRegisterAutoloader::register();

		if (class_exists(Bootstrap::class) === true) {
			Bootstrap::register(
				$context,
				self::APP_ID,
				[
					'namespace' => 'OCA\\Buildiq',
					'sectionName' => 'Buildiq',
					'observability' => true,
				]
			);
		}

		// No else branch, deliberately. It used to write a line to the PHP error
		// log, and with the prelude above in place that line no longer means what
		// it said: before the prelude it fired on a HEALTHY instance, because the
		// probe was answering "not autoloadable YET" — measured 3x per E2E run,
		// once per occ call. It was the symptom, not a diagnostic: the very noise
		// that made the real defect read as ambient log spam.
		//
		// Now a false class_exists() means OpenRegister genuinely is absent or
		// disabled, which is a whole-instance condition an app cannot usefully
		// narrate from its composition root: no PSR logger is resolvable this
		// early, so the only channel left is the one hydra gate-2 forbids in
		// lib/, for good reason. The degraded state is reported where it can be
		// read instead — /api/health, wired by `observability => true` and bound
		// below regardless of load order. Same choice doriath made.
		//
		// Buildiq keeps three concrete controllers + the settings service that
		// the AppHost generics cannot cover on OpenRegister `development` (see
		// openspec/changes/adopt-apphost/design.md "Engine reality"):
		//
		// 1. DashboardController — publishes `currentUserGroups` to
		// IInitialState (REQ-OBR-009); the generic dashboard controller
		// does not, which would break client-side per-Application role
		// derivation.
		// 2. PreferencesController — there is NO GenericPreferencesController
		// in OpenRegister `development` (Bootstrap aliases a class that does
		// not exist), so the preferences routes would 500 under the alias.
		// 3. SettingsController + SettingsService — the generic
		// AppHostSettingsService::loadConfiguration() calls OR's
		// ConfigurationService::importFromApp() with a 2-argument signature
		// that no longer matches `development` (4 required args) and performs
		// no ADR-037 `register.d/` fragment merge, which Buildiq relies on
		// (10-business-rules.json). The bespoke SettingsController also
		// enforces body-level admin guards on create()/load().
		//
		// Re-registering these concrete classes AFTER Bootstrap::register lets
		// NC's DI container resolve the leaf class names to the real Buildiq
		// controllers (last registration wins), so the canonical routes keep
		// working while everything else is generic.
		$context->registerService(
			DashboardController::class,
			static fn ($c): DashboardController => new DashboardController(
				request: $c->get('OCP\\IRequest'),
				initialState: $c->get('OCP\\AppFramework\\Services\\IInitialState'),
				userSession: $c->get('OCP\\IUserSession'),
				groupManager: $c->get('OCP\\IGroupManager'),
				navigationManager: $c->get('OCP\\INavigationManager')
			)
		);
		$context->registerService(
			PreferencesController::class,
			static fn ($c): PreferencesController => new PreferencesController(
				request: $c->get('OCP\\IRequest'),
				config: $c->get('OCP\\IConfig'),
				userSession: $c->get('OCP\\IUserSession')
			)
		);
		// SettingsService — bind the concrete Buildiq implementation so it wins
		// over the generic AppHost binding (last registration wins). When
		// Bootstrap::register ran (above), it aliases this leaf class name to
		// OpenRegister's generic AppHostSettingsService, whose loadConfiguration()
		// calls ConfigurationService::importFromApp() with a stale 2-argument
		// signature (OR `development` now requires 4) and skips the ADR-037
		// register.d/ fragment merge. Without this override the SettingsController
		// factory below also resolves `$c->get(SettingsService::class)` to the
		// generic AppHostSettingsService and fatals with a TypeError (constructor
		// arg #2 type mismatch), 500-ing `GET /api/settings` and leaving the app
		// detail page in its empty fallback ("Untitled application", `buildiq--`
		// register). On the app-enable/install path the InitializeSettings repair
		// step resolves THIS class directly, so under the generic binding the
		// register import fails with "importFromApp(): Argument #2 ($data) not
		// passed" — leaving the buildiq register uncreated until a manual
		// re-import. Registering the concrete class here (mirroring the controllers
		// above) guarantees every caller — the InitializeSettings repair step AND
		// SettingsController — uses the correct 4-arg importer + fragment merge on
		// all paths.
		$context->registerService(
			SettingsService::class,
			static fn ($c): SettingsService => new SettingsService(
				appConfig: $c->get('OCP\\IAppConfig'),
				appManager: $c->get('OCP\\App\\IAppManager'),
				container: $c,
				groupManager: $c->get('OCP\\IGroupManager'),
				userSession: $c->get('OCP\\IUserSession'),
				logger: $c->get('Psr\\Log\\LoggerInterface')
			)
		);
		// InitializeSettings repair step — bind Buildiq's own class so it wins
		// over the generic AppHost binding (last registration wins). Bootstrap
		// above re-registers this exact class name to OpenRegister's
		// GenericInitializeSettings, which imports via the broken generic
		// AppHostSettingsService (2-arg importFromApp) and does NO register.d/
		// fragment merge. info.xml declares OCA\Buildiq\Repair\InitializeSettings
		// as an <install>/<post-migration> step; NC's repair runner resolves that
		// class name through the container, so without this the generic (failing)
		// step runs on install and the buildiq register is never created.
		// Re-registering the concrete step here makes install use the correct
		// 4-arg importer + fragment merge (via the concrete SettingsService above).
		$context->registerService(
			InitializeSettings::class,
			static fn ($c): InitializeSettings => new InitializeSettings(
				settingsService: $c->get(SettingsService::class),
				logger: $c->get('Psr\\Log\\LoggerInterface')
			)
		);
		$context->registerService(
			SettingsController::class,
			static fn ($c): SettingsController => new SettingsController(
				request: $c->get('OCP\\IRequest'),
				settingsService: $c->get(SettingsService::class),
				userSession: $c->get('OCP\\IUserSession'),
				groupManager: $c->get('OCP\\IGroupManager')
			)
		);

		// 4. SettingsSection + AdminSettings — the admin settings section and
		// panel referenced by info.xml `<settings>`. Both are one-line leaf
		// subclasses of OpenRegister's GenericSettingsSection / GenericAdminSettings,
		// whose constructors take scalar params (sectionId, name, icon, priority)
		// that are NOT autowireable — they MUST be bound by a service factory.
		// Bootstrap::register() normally binds them, but when Bootstrap is not
		// autoloadable at register() time (it lives in OCA\OpenRegister and that
		// app's autoloader is not guaranteed to be registered before Buildiq
		// boots — `buildiq` sorts before `openregister`), the else branch above
		// skips it. NC's settings Manager instantiates EVERY registered section to
		// render ANY settings page, so an unbound `sectionId` throws
		// QueryNotFoundException that escapes NC's catch and blanks ALL admin AND
		// personal settings pages instance-wide (not just Buildiq's). Register
		// the leaf classes unconditionally here with Bootstrap's exact defaults so
		// they resolve regardless of app load order (last registration wins).
		$context->registerService(
			SettingsSection::class,
			static fn ($c): SettingsSection => new SettingsSection(
				sectionId: self::APP_ID,
				name: 'Buildiq',
				appId: self::APP_ID,
				iconFile: 'app-dark.svg',
				priority: 75,
				urlGenerator: $c->get(IURLGenerator::class)
			)
		);
		$context->registerService(
			AdminSettings::class,
			static fn ($c): AdminSettings => new AdminSettings(
				appId: self::APP_ID,
				sectionId: self::APP_ID,
				priority: 10,
				appManager: $c->get(IAppManager::class),
				initialState: $c->get(IInitialState::class),
				appConfig: $c->get(IAppConfig::class)
			)
		);

		// 5. Observability probes (health + metrics) — same load-order hazard as
		// the settings section above, but with a worse symptom. `routes.php`
		// calls \OCA\OpenRegister\AppHost\Routes::standard(), which emits
		// `health#index` and `metrics#index` UNCONDITIONALLY. routes.php is
		// evaluated at request-routing time, by which point OpenRegister's
		// autoloader IS registered — so the routes always exist. The controllers
		// behind them, however, are only bound by Bootstrap::register(), which
		// the guard above skips because `buildiq` sorts before `openregister`
		// and OR is not autoloadable that early. The result is a routed endpoint
		// with no controller: GET /api/health and GET /api/metrics both 500.
		//
		// Buildiq has no concrete Health/MetricsController of its own (ADR-040
		// moved them to the AppHost engine), so bind the leaf class names to the
		// generic implementations here, mirroring Bootstrap::registerControllers.
		// The OpenRegister class names are referenced as STRINGS and every
		// dependency is resolved INSIDE the closure, so nothing touches the
		// OCA\OpenRegister namespace until the container resolves the controller
		// at request time — deferring the binding instead of guarding at boot.
		$context->registerService(
			'OCA\\Buildiq\\Controller\\HealthController',
			static function ($c) {
				$class = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController';
				return new $class(
					appName: self::APP_ID,
					request: $c->get('OCP\\IRequest'),
					manifestLoader: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader'),
					executor: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor')
				);
			}
		);
		$context->registerService(
			'OCA\\Buildiq\\Controller\\MetricsController',
			static function ($c) {
				$class = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericMetricsController';
				return new $class(
					appName: self::APP_ID,
					request: $c->get('OCP\\IRequest'),
					manifestLoader: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader'),
					engine: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine')
				);
			}
		);

		// Per ADR-002 the snapshot-on-publish writeback listener has been
		// retired. ApplicationVersion is now a first-class long-lived row,
		// not an append-only snapshot, and `Application.currentVersion` has
		// been removed in favour of an explicit `productionVersion` relation
		// set by the admin. Object time-travel on the ApplicationVersion row
		// captures audit history. The corresponding spec retirement lives
		// in buildiq-versioning-model/specs/buildiq-version-snapshots.
		// Cross-row integrity guard: on every Application save (create or
		// update), verify that `productionVersion` (when set) points at an
		// ApplicationVersion whose `application` relation refers back to
		// this Application (ADR-031 §Exceptions(1) — cross-row validation
		// that OR's per-row x-openregister-validation cannot perform).
		//
		// The CREATE half declares its schema interest up front and is
		// therefore subscribed from boot() — see boot().
		//
		// The UPDATE half stays a plain registration: `ObjectUpdatingEvent`
		// exposes `getNewObject()`/`getOldObject()` but no `getObject()`, which
		// is the accessor OpenRegister's subscription proxy identifies the
		// written row with. It would fail open on every dispatch, so declaring
		// an interest there buys nothing and only adds indirection.
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: ProductionVersionGuardListener::class
		);

		// Metadata-lock for hybrid apps (unify-apps-with-app-type). On every
		// Application UPDATE, reject a change to a hybrid app's identity
		// metadata (slug/name) — it mirrors the installed Nextcloud app it
		// customizes and renaming it would desync the baseRef.id link and the
		// /api/app-overrides/{appId} shim key. Cross-row check (compares the
		// proposed payload against the stored row) realized as a pre-save
		// listener, the imperative companion to the same-row
		// `hybrid-requires-baseRef` x-openregister-validation rule (ADR-031
		// §Exceptions(1)). Virtual apps keep full slug/name edit.
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: HybridMetadataLockListener::class
		);

		// Automation-approval-steps: trigger-fire half of the `approval`
		// action kind (spec REQ-AUTD-004 approval scenarios). No declarative
		// primitive already dispatches "start a compiled ApprovalChain
		// instance when this object-event/lifecycle-transition fires" — OR's
		// own AnnotationNotificationListener plays that role for the
		// notifications dialect, but nothing analogous exists for approval
		// chains started from a plain object event or a non-blocking
		// transition side effect (ApprovalChainGateListener is a BLOCKING
		// gate, a different use case). Registered for all four trigger-shape
		// events the v1 matrix supports (design.md Decision 1 of
		// automation-approval-steps).
		//
		// Deliberately NOT narrowed with ObjectEventSubscription: this
		// listener has no static schema interest at all. It reacts to whatever
		// schema an author names in an `automation` row's trigger config, in
		// whichever per-app register that automation targets — both created at
		// runtime. Declaring anything here would silently stop the listener
		// firing for every automation authored after this line was written.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: AutomationApprovalTriggerListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: AutomationApprovalTriggerListener::class
		);
		$context->registerEventListener(
			event: ObjectDeletedEvent::class,
			listener: AutomationApprovalTriggerListener::class
		);
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: AutomationApprovalTriggerListener::class
		);

		// Automation-approval-steps: on-approve/on-reject follow-up dispatch
		// (design.md Decision 3). OpenRegister dispatches these typed events for
		// every decision regardless of who started the sequence; this listener
		// resolves the originating automation by its `aut-<slug>` chain key and
		// fires the configured follow-up actions through the shared
		// RuleActionDispatcher.
		//
		// ⚠️ The two halves subscribe to events at DIFFERENT levels, and that is
		// the published mapping rather than an oversight (openregister #3302):
		// approval concludes when the SEQUENCE completes, while a rejection is a
		// TASK completing with a rejecting outcome — a rejecting task closes its
		// whole sequence, so the reject half still fires exactly once.
		$context->registerEventListener(
			event: TaskSequenceCompletedEvent::class,
			listener: ApprovalOutcomeListener::class
		);
		$context->registerEventListener(
			event: TaskTerminalEvent::class,
			listener: ApprovalOutcomeListener::class
		);

		// Automation-document-action: trigger-fire half of the
		// `generateDocument` action kind (spec REQ-AUTD-004 generateDocument
		// scenarios). Mirrors AutomationApprovalTriggerListener exactly —
		// AutomationCompilerService compiles no artifact for this action
		// kind (Docudesk's generate route is stateless), so the actual
		// owner-impersonated call happens here, at trigger-fire time.
		// Registered for the same four trigger-shape events the v1 matrix
		// supports (design.md Decision 2 of automation-document-action).
		//
		// Deliberately NOT narrowed with ObjectEventSubscription, for exactly
		// the reason given on AutomationApprovalTriggerListener above: the
		// schema it reacts to comes from runtime automation config, not from
		// anything statically knowable here.
		$context->registerEventListener(
			event: ObjectCreatedEvent::class,
			listener: DocumentGenerationListener::class
		);
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: DocumentGenerationListener::class
		);
		$context->registerEventListener(
			event: ObjectDeletedEvent::class,
			listener: DocumentGenerationListener::class
		);
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: DocumentGenerationListener::class
		);

		// Register BuildiqToolProvider as the MCP tool provider for the AI Chat Companion.
		// The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::buildiq' is the format
		// that OR's McpToolsService enumerates to discover per-app providers (hydra ADR-035).
		// The interface ships in openregister PR #1466 (ai-chat-companion-orchestrator);
		// until then Buildiq implements the test stub at tests/Stubs/Mcp/IMcpToolProvider.php.
		$context->registerServiceAlias(
			'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::buildiq',
			BuildiqToolProvider::class
		);

		// Per-Application RBAC lifecycle guard (ADR-022/ADR-023; buildiq-rbac).
		// OpenRegister's LifecycleGuardRegistry resolves the destructive
		// ApplicationVersion transitions' `requires` tag — keyed by this guard's
		// FQCN in the schema's x-openregister-lifecycle.transitions[*].requires —
		// to this concrete instance. It is the real, default-secure, fail-closed
		// ownership rule the descriptive `authorization` block cannot express
		// (issue #1). Registered explicitly because the guard's dependencies
		// (PermissionResolver, ObjectService) need wiring through the container.
		$context->registerService(
			ApplicationVersionOwnerGuard::class,
			static function ($c): ApplicationVersionOwnerGuard {
				return new ApplicationVersionOwnerGuard(
					objectService: $c->get(ObjectServiceInterface::class),
					permissionResolver: $c->get(PermissionResolver::class),
					userManager: $c->get(IUserManager::class),
					logger: $c->get(LoggerInterface::class)
				);
			}
		);

		// Edit-availability capability (buildiq-inline-edit-persistence, spec
		// buildiq-capability). Advertises `{ buildiq: { enabled, canEdit } }`
		// so a fleet app's in-place edit button has a robust per-user signal
		// (IAppManager::isEnabledForUser respects the NC app group-restriction)
		// instead of inferring availability from OC.appswebroots. `canEdit` is a
		// UI hint only — the write/delete endpoints re-check access server-side.
		$context->registerCapability(Capabilities::class);

		// Repair steps (InitializeSettings + MigrateToVersionedModel + …) are declared in info.xml.
	}//end register()

	/**
	 * Register an object-lifecycle listener that declares its interest up front.
	 *
	 * OpenRegister's `ObjectEventSubscription` records the register/schema slugs
	 * a listener reacts to and routes dispatches through a single shared proxy,
	 * so an uninterested listener is neither constructed nor invoked. When
	 * OpenRegister is absent — Buildiq carries no hard dependency on it — this
	 * degrades to the plain global registration it replaced, which is exactly
	 * the behaviour every listener had before.
	 *
	 * MUST be called from boot(), never from register(). Nextcloud enables each
	 * app's own autoloader immediately before calling that app's register(), so
	 * during register() OpenRegister's classes are only autoloadable to apps
	 * that happen to be registered after it — the class_exists() guard below
	 * would then resolve differently purely by app load order and silently fall
	 * back to an unfiltered registration. boot() runs only after every app's
	 * register() has completed, so the guard is order-independent there.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 * @param string $event OpenRegister event class name.
	 * @param string $listener Listener class name.
	 * @param array<int,string>|null $registers Register slugs the listener reacts to, or null for all.
	 * @param array<int,string>|null $schemas Schema slugs the listener reacts to, or null for all.
	 *
	 * @return void
	 */
	private function registerFilteredObjectListener(
		IEventDispatcher $dispatcher,
		string $event,
		string $listener,
		?array $registers,
		?array $schemas,
	): void {
		$subscription = '\\OCA\\OpenRegister\\Event\\ObjectEventSubscription';
		if (class_exists($subscription) === true) {
			$subscription::subscribe(
				dispatcher: $dispatcher,
				event: $event,
				listener: $listener,
				registers: $registers,
				schemas: $schemas
			);
			return;
		}

		// Loud on purpose. This fallback is correct but UNFILTERED, and while it
		// was silent it was indistinguishable from a working narrowing.
		\OCP\Server::get(LoggerInterface::class)->warning(
			'OpenRegister ObjectEventSubscription unavailable: ' . $listener
			. ' fell back to an UNFILTERED registration for ' . $event
			. ' and will be invoked on every object write instance-wide.',
			['app' => self::APP_ID]
		);

		$dispatcher->addServiceListener($event, $listener);
	}//end registerFilteredObjectListener()

	/**
	 * Boot the application.
	 *
	 * Registers per-published-app top-bar navigation entries via
	 * AppNavigationService (REQ-OBNAV-001 / buildiq-nextcloud-nav).
	 * Lazily resolved from the DI container to avoid instantiating the
	 * service tree when OR is not installed.
	 *
	 * Also subscribes the narrowed object-lifecycle listeners — see
	 * registerFilteredObjectListener() for why that cannot happen in register().
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-17-openbuild-nextcloud-nav/tasks.md
	 */
	public function boot(IBootContext $context): void {
		$dispatcher = $context->getServerContainer()->get(IEventDispatcher::class);

		// Cross-row integrity guard, CREATE half (see register() for the full
		// rationale and the UPDATE half). Declares its schema interest up front:
		// the handler's very first guard is
		// `$schema !== ApplicationVersionService::APPLICATION_SCHEMA` (= the
		// `application` schema slug), read off the entity's own
		// `getSchemaSlug()`. Registered globally it woke on every object write
		// on the instance. No register is declared on purpose — Buildiq
		// creates registers dynamically, one per built app and environment
		// (`buildiq-pet-store-production`, `buildiq-library-desk-development`,
		// …), so a static register list could never cover a register that does
		// not exist yet. Schema-only is exactly the handler's own guard.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectCreatingEvent::class,
			listener: ProductionVersionGuardListener::class,
			registers: null,
			schemas: ['built-app']
		);

		// Automation-designer artifact cleanup (spec REQ-AUTD-005). Automation
		// CRUD (including delete) stays on OR REST per ADR-022 — no delete
		// route on AutomationsController — so removing exactly the
		// provenance-listed compiled artifacts on delete is realized here as
		// the imperative companion to that declarative delete (ADR-031
		// §Exceptions(1)), mirroring the guard listeners in register().
		//
		// Declares its schema interest up front: the handler's first guard is
		// `$schema !== AutomationCompilerService::AUTOMATION_SCHEMA` (= the
		// `automation` schema slug), read off the entity's own
		// `getSchemaSlug()`. Schema-only, no register — see the
		// ProductionVersionGuardListener note above on Buildiq's dynamically
		// created per-app registers.
		$this->registerFilteredObjectListener(
			dispatcher: $dispatcher,
			event: ObjectDeletedEvent::class,
			listener: AutomationCleanupListener::class,
			registers: null,
			schemas: ['automation']
		);

		try {
			$container = $context->getAppContainer();
			$container->get(AppNavigationService::class)
				->registerNavEntries($container->get(INavigationManager::class));
		} catch (\Throwable $e) {
			// Boot must never throw — log and continue.
			// OpenRegister may not be installed on this instance.
			\OCP\Server::get(LoggerInterface::class)->warning(
				'Buildiq: nav-entry registration failed during boot: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try
	}//end boot()
}//end class
