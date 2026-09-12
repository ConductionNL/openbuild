<?php

/**
 * Buildiq AutomationCompilerService
 *
 * Compiles a stored `Automation` declarative object (design.md Decision 1 of
 * the automation-designer change) to Buildiq's EXISTING declarative
 * primitives, and nothing else (ADR-031 — no new imperative engine):
 *
 *   - Dialect backend — event/lifecycle-transition triggers + `send-notification`
 *     compile to an `x-openregister-notifications` entry on the target schema
 *     (the shape verified live at `lib/Settings/register.d/10-business-rules.json:153`);
 *     lifecycle-transition + `object-op`/`webhook` compile to typed
 *     `related-object-upsert`/`webhook-dispatch` records appended to that
 *     transition's `x-openregister-lifecycle` actions.
 *   - Schedules backend — `schedule` trigger + `run-synchronization` compiles
 *     to a `manifest.schedules[]` entry using the existing
 *     `openconnector:synchronization` action (validated shape:
 *     `src/services/manifestValidation/schedules.js`).
 *   - Rules backend — `manual` trigger compiles condition + actions to a
 *     namespaced RuleSet (`aut-<uuid8>`) plus one ConditionActionRule
 *     evaluated by the existing {@see RuleEngineService}.
 *
 * The v1 compilation matrix (design.md Decision 2, extended by design.md
 * Decision 1 of the automation-approval-steps change) is enforced fail-closed
 * — {@see compile()} throws {@see UnsupportedAutomationCombinationException}
 * naming the unsupported trigger/action/condition combination; nothing is
 * ever stubbed, silently dropped, or partially compiled.
 *
 *   - Approval backend — `object-created|object-updated|object-deleted|
 *     lifecycle-transition` + `approval` compiles to an OpenRegister
 *     approval-chain DECLARATION on the schema (one approver, `role` = the
 *     assignee group), written to `x-openregister-approval-chains` under the
 *     `aut-<slug>` provenance name and compiled into a task template on demand.
 *     Opening a task sequence against a fired object's uuid happens OUT of this pure
 *     compiler, in {@see \OCA\Buildiq\Listener\AutomationApprovalTriggerListener}
 *     — consume-not-rebuild (ADR-022): Buildiq never implements an approval
 *     engine, only a compiler that provisions OR's existing one. On-approve/
 *     on-reject follow-up actions are NOT compiled into a separate artifact —
 *     they stay on the `Automation` object's own `actions[].onApprove/
 *     onReject` and are dispatched by
 *     {@see \OCA\Buildiq\Listener\ApprovalOutcomeListener} at outcome time
 *     via the shared {@see RuleActionDispatcher}, exactly mirroring how the
 *     rules backend's `manual` trigger dispatches its own actions.
 *
 *   - Document-generation backend (automation-document-action) — the same
 *     four triggers + `generateDocument` require NO compile-time upsert
 *     (unlike `approval`'s `ApprovalChain`) — Docudesk's
 *     `correspondence/generate` route is stateless, so {@see compile()} only
 *     validates the action's config (`templateId` present, `output` a
 *     known, non-empty set, `notify` never alone) and, when Docudesk is
 *     absent at compile time, throws
 *     {@see UnsupportedAutomationCombinationException} naming the missing
 *     dependency (mirrors `docudesk-document-templates` REQ-DDT-005's
 *     editor-side degradation). Dispatching the actual owner-impersonated
 *     Docudesk call against a concretely fired object is an imperative,
 *     per-event side effect realized OUT of this pure compiler, in
 *     {@see \OCA\Buildiq\Listener\DocumentGenerationListener} →
 *     {@see \OCA\Buildiq\Service\DocumentGenerationService} — the same
 *     compile/dispatch split the approval backend above already uses.
 *
 * DEVIATION FROM design.md (documented, not silent — tasks.md apply-notes
 * instruct flagging rather than inventing a runner): design.md's Decision 2
 * matrix table marks `manual` + `run-synchronization` as a ✅ "rules backend"
 * cell. No primitive to invoke an OpenConnector synchronization on demand
 * exists anywhere in buildiq's `lib/` (the ONLY existing trigger for a
 * synchronization run is the AppHost schedules reconciler); the rules
 * engine's typed action vocabulary
 * ({@see ConditionActionExecutor::SIDE_EFFECT_ACTIONS}) has no
 * `run-synchronization` action and inventing an ad-hoc HTTP call into
 * OpenConnector without a verified contract would be worse than declining
 * the cell. This compiler therefore treats `manual` + `run-synchronization`
 * as ⛔ (blocked fail-closed) pending a verified OpenConnector "run now" API
 * — a v1.1 follow-up, not a v1 regression (no scenario in
 * `specs/automation-designer/spec.md` REQ-AUTD-002/004 exercises this cell).
 *
 * Compilation is deterministic (identical input → identical plan + hash),
 * idempotent (recompiling an unchanged automation changes nothing) and
 * reversible (delete removes exactly the provenance-listed artifacts). Every
 * compiled artifact id/key carries the `aut-` prefix so hand-authored
 * entries are never touched.
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
 * @spec openspec/changes/automation-designer/tasks.md#2.1
 * @spec openspec/changes/automation-designer/tasks.md#2.2
 * @spec openspec/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-existing-declarative-primitives-req-autd-004
 * @spec openspec/specs/automation-designer/spec.md#requirement-an-automation-is-managed-as-one-unit-with-provenance-req-autd-005
 * @spec openspec/changes/automation-approval-steps/tasks.md#1.1
 * @spec openspec/changes/automation-approval-steps/tasks.md#1.2
 * @spec openspec/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-existing-declarative-primitives-req-autd-004
 * @spec openspec/changes/automation-document-action/tasks.md#1.1
 * @spec openspec/changes/automation-document-action/tasks.md#1.2
 * @spec openspec/changes/automation-document-action/tasks.md#1.3
 * @spec openspec/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-existing-declarative-primitives-req-autd-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Pure compile() + I/O-performing apply()/remove()/status().
 *
 * @spec openspec/changes/automation-designer/tasks.md#2.1
 */
class AutomationCompilerService {
	/**
	 * Shared Buildiq register slug.
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * Schema slug of the Automation object itself.
	 */
	public const AUTOMATION_SCHEMA = 'automation';

	/**
	 * The v1 compilation matrix: trigger type → allowed action types.
	 *
	 * See the class docblock for the one documented deviation from
	 * design.md's table (`manual` + `run-synchronization`).
	 *
	 * @var array<string,array<int,string>>
	 */
	private const MATRIX = [
		'object-created' => ['send-notification', 'approval', 'generateDocument'],
		'object-updated' => ['send-notification', 'approval', 'generateDocument'],
		'object-deleted' => ['send-notification', 'approval', 'generateDocument'],
		'lifecycle-transition' => ['send-notification', 'object-op', 'webhook', 'approval', 'generateDocument'],
		'schedule' => ['run-synchronization'],
		'manual' => ['send-notification', 'object-op', 'webhook'],
	];

	/**
	 * Docudesk app id — presence-checked at compile time for a
	 * `generateDocument` action (design.md Decision 3 of automation-document-
	 * action, mirrors `docudesk-document-templates` REQ-DDT-005's
	 * missing-dependency posture).
	 */
	/**
	 * The schema-annotation key an approval chain is declared under.
	 *
	 * OpenRegister #3302 retired the ApprovalChain row; the declaration now
	 * lives on the schema and is compiled into a task template on demand.
	 *
	 * @var string
	 */
	private const APPROVAL_CHAINS_KEY = 'x-openregister-approval-chains';

	/**
	 * Canonical id of the document app (`docudesk` renamed to `filinq`).
	 *
	 * Never compared against `IAppManager` directly: both ids are in the
	 * field at once, so a literal answers false on half the fleet and the
	 * compile fails claiming the app is absent when it is installed.
	 * {@see FleetAppId} resolves which of the two this instance has.
	 *
	 * @var string
	 */
	private const DOCUDESK_APP_ID = 'filinq';

	/**
	 * Valid `generateDocument` `output` values (matches
	 * {@see \OCA\Buildiq\Service\DocumentGenerationService::OUTPUT_MODES}
	 * — kept as an independent literal rather than a cross-class constant
	 * reference so the compiler never has to construct/inject
	 * `DocumentGenerationService` merely to validate config, mirroring how
	 * `RuleActionDispatcher`'s side-effect action list is documented, not
	 * imported, in {@see mapActionToRuleAction()}).
	 *
	 * @var array<int,string>
	 */
	private const GENERATE_DOCUMENT_OUTPUT_MODES = ['attach', 'download-link', 'notify'];

	/**
	 * Trigger types on which a condition is v1-supported (design.md Decision 2
	 * — the rules backend is the only existing primitive that evaluates FEEL).
	 *
	 * @var array<int,string>
	 */
	private const CONDITION_ALLOWED_TRIGGERS = ['manual'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's task-engine
	 *                                    collaborators at USE time — a hard
	 *                                    constructor type on a cross-app class
	 *                                    is what made buildiq#651 an outage.
	 * @param ObjectServiceInterface $objectService OpenRegister object service (ADR-022 boundary).
	 * @param SchemaMapper $schemaMapper OR schema mapper — mutates schema
	 *                                   `configuration` (`x-openregister-notifications` /
	 *                                   `x-openregister-lifecycle`).
	 * @param IAppManager $appManager Nextcloud app manager — asks whether an
	 *                                optional integration app is installed.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly ObjectServiceInterface $objectService,
		private readonly SchemaMapper $schemaMapper,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Return `$value` when it is an array, otherwise `$default`.
	 *
	 * PHPCS in this codebase disallows inline ternaries; this single helper
	 * replaces the repeated `is_array($x) === true ? $x : $default` idiom
	 * used throughout compile()/apply()/remove()/status().
	 *
	 * @param mixed $value The candidate value.
	 * @param array<mixed> $default The fallback when `$value` is not an array.
	 *
	 * @return array<mixed>
	 */
	private function orArray(mixed $value, array $default = []): array {
		if (is_array($value) === true) {
			return $value;
		}

		return $default;
	}//end orArray()

	/**
	 * Return `$value` when it is a non-empty string, otherwise null.
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return string|null
	 */
	private function orNullString(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end orNullString()

	/**
	 * Return `$value` when it is an array, otherwise null.
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return array<mixed>|null
	 */
	private function orNullArray(mixed $value): ?array {
		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}//end orNullArray()

	/**
	 * Compile an automation to its CompiledPlan (pure — no I/O).
	 *
	 * @param array<string,mixed> $automation The Automation object.
	 *
	 * @return array{notifications:array<int,array<string,mixed>>,lifecycleActions:array<int,array<string,mixed>>,schedules:array<int,array<string,mixed>>,ruleSet:?array<string,mixed>,conditionActionRule:?array<string,mixed>,approvalChain:?array<string,mixed>,hash:string}
	 *
	 * @throws UnsupportedAutomationCombinationException When the matrix (or condition placement) rejects the shape.
	 *
	 * @spec openspec/changes/automation-designer/tasks.md#2.1
	 * @spec openspec/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-existing-declarative-primitives-req-autd-004
	 */
	public function compile(array $automation): array {
		$slug = (string)($automation['slug'] ?? '');
		$trigger = $this->orArray(value: $automation['trigger'] ?? null);
		$triggerType = (string)($trigger['type'] ?? '');
		$condition = $automation['condition'] ?? null;
		$actions = array_values($this->orArray(value: $automation['actions'] ?? null));
		$enabled = (bool)($automation['enabled'] ?? true);

		$this->assertMatrix(triggerType: $triggerType, condition: $condition, actions: $actions);
		$this->assertGenerateDocumentActions(actions: $actions);

		$plan = [
			'notifications' => [],
			'lifecycleActions' => [],
			'schedules' => [],
			'ruleSet' => null,
			'conditionActionRule' => null,
			'approvalChain' => null,
		];

		$dialectTriggers = ['object-created', 'object-updated', 'object-deleted', 'lifecycle-transition'];
		if (in_array($triggerType, $dialectTriggers, true) === true) {
			$this->compileDialectBackend(
				slug: $slug,
				trigger: $trigger,
				triggerType: $triggerType,
				actions: $actions,
				enabled: $enabled,
				plan: $plan
			);
		} elseif ($triggerType === 'schedule') {
			$this->compileSchedulesBackend(slug: $slug, trigger: $trigger, actions: $actions, enabled: $enabled, plan: $plan);
		} elseif ($triggerType === 'manual') {
			$this->compileRulesBackend(
				automation: $automation,
				slug: $slug,
				condition: $condition,
				actions: $actions,
				enabled: $enabled,
				plan: $plan
			);
		}//end if

		$plan['hash'] = $this->hashPlan(plan: $plan);
		return $plan;
	}//end compile()

	/**
	 * Apply a compiled plan: idempotent upsert of every artifact, removing
	 * any prior-provenance artifact no longer present in the new plan.
	 *
	 * @param array<string,mixed> $automation The Automation object (must carry an `id`/`uuid`).
	 * @param array<string,mixed> $plan The CompiledPlan from {@see compile()}.
	 * @param array<string,mixed> $priorProvenance The automation's PREVIOUS `provenance` block (empty on first compile).
	 *
	 * @return array<string,mixed> The new `provenance` block.
	 *
	 * @spec openspec/changes/automation-designer/tasks.md#2.2
	 */
	public function apply(array $automation, array $plan, array $priorProvenance = []): array {
		$slug = (string)($automation['slug'] ?? '');
		$versionUuid = (string)($automation['versionUuid'] ?? '');

		$notificationKeys = $this->applyNotifications(
			planned: $plan['notifications'],
			priorKeys: $this->orArray(value: $priorProvenance['notificationKeys'] ?? null)
		);

		$lifecycleActions = $this->applyLifecycleActions(
			slug: $slug,
			planned: $plan['lifecycleActions'],
			priorActions: $this->orArray(value: $priorProvenance['lifecycleActions'] ?? null)
		);

		$scheduleIds = $this->applySchedules(
			versionUuid: $versionUuid,
			plannedEntries: $plan['schedules'],
			priorScheduleIds: $this->orArray(value: $priorProvenance['scheduleIds'] ?? null)
		);

		$ruleSetSlug = $this->applyRuleSet(
			ruleSet: $plan['ruleSet'],
			conditionActionRule: $plan['conditionActionRule'],
			priorRuleSetSlug: $this->orNullString(value: $priorProvenance['ruleSetSlug'] ?? null)
		);

		$approvalChainName = $this->applyApprovalChain(
			planned: $plan['approvalChain'],
			priorName: $this->orNullString(value: $priorProvenance['approvalChainName'] ?? null),
			fallbackSchemaSlug: (string)($automation['trigger']['schema'] ?? '')
		);

		$provenance = [
			'notificationKeys' => $notificationKeys,
			'lifecycleActions' => $lifecycleActions,
			'scheduleIds' => $scheduleIds,
			'openconnectorObjects' => [],
			'compiledHash' => (string)$plan['hash'],
		];

		// `ruleSetSlug` and `approvalChainName` are declared `"type": "string"`
		// in 40-automations.json even though both descriptions say "or null",
		// and both applyX() helpers legitimately return null — approvalChainName
		// for every automation without an approval action, which is the common
		// case. OpenRegister validates strictly and rejects a null against a
		// `string` property, so emitting the key with a null value made
		// POST /api/automations/{uuid}/compile fail with a 500
		// ("Property 'provenance.approvalChainName' should be type 'string' but
		// is 'null'") for essentially every automation. The dialog then stayed
		// open on "Could not save the automation" even though the automation
		// object itself had already been created.
		//
		// OMIT the key rather than sending null.
		//
		// DO NOT "tidy" this back into `'ruleSetSlug' => $ruleSetSlug` with a
		// null value for readability — that is the obvious later cleanup and it
		// silently re-breaks compile with a 500. This is a fleet-wide
		// OpenRegister trap, not a local quirk: OpenRegister rejects BOTH
		// `null` AND `{}` for a nested typed property. Omitting the key is the
		// only accepted shape. Every reader of these two fields already goes
		// through `?? null` / `?? ''`, so an absent key is what they all expect.
		if ($ruleSetSlug !== null) {
			$provenance['ruleSetSlug'] = $ruleSetSlug;
		}

		if ($approvalChainName !== null) {
			$provenance['approvalChainName'] = $approvalChainName;
		}

		return $provenance;
	}//end apply()

	/**
	 * Remove exactly the provenance-listed artifacts (design.md Decision 4 —
	 * hand-authored non-`aut-` entries are never touched).
	 *
	 * @param array<string,mixed> $automation The Automation object.
	 * @param array<string,mixed> $provenance The automation's `provenance` block.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-designer/tasks.md#2.2
	 * @spec openspec/specs/automation-designer/spec.md#requirement-an-automation-is-managed-as-one-unit-with-provenance-req-autd-005
	 */
	public function remove(array $automation, array $provenance): void {
		$this->applyNotifications(planned: [], priorKeys: $this->orArray(value: $provenance['notificationKeys'] ?? null));
		$this->applyLifecycleActions(
			slug: (string)($automation['slug'] ?? ''),
			planned: [],
			priorActions: $this->orArray(value: $provenance['lifecycleActions'] ?? null)
		);
		$this->applySchedules(
			versionUuid: (string)($automation['versionUuid'] ?? ''),
			plannedEntries: [],
			priorScheduleIds: $this->orArray(value: $provenance['scheduleIds'] ?? null)
		);

		$ruleSetSlug = ($provenance['ruleSetSlug'] ?? null);
		if (is_string($ruleSetSlug) === true && $ruleSetSlug !== '') {
			$this->removeRuleSet(ruleSetSlug: $ruleSetSlug);
		}

		$approvalChainName = ($provenance['approvalChainName'] ?? null);
		if (is_string($approvalChainName) === true && $approvalChainName !== '') {
			$this->removeApprovalChain(
				name: $approvalChainName,
				schemaSlug: (string)($automation['trigger']['schema'] ?? '')
			);
		}

	}//end remove()

	/**
	 * Recompute drift: compare the live artifacts against the last-applied
	 * `provenance.compiledHash` (design.md Decision 4).
	 *
	 * @param array<string,mixed> $automation The Automation object.
	 * @param array<string,mixed> $provenance The automation's `provenance` block.
	 *
	 * @return array{drift:bool,compiledHash:?string,liveHash:?string}
	 *
	 * @spec openspec/changes/automation-designer/tasks.md#2.2
	 * @spec openspec/specs/automation-designer/spec.md#requirement-an-automation-is-managed-as-one-unit-with-provenance-req-autd-005
	 */
	public function status(array $automation, array $provenance): array {
		$expectedHash = (string)($provenance['compiledHash'] ?? '');
		if ($expectedHash === '') {
			return ['drift' => false, 'compiledHash' => null, 'liveHash' => null];
		}

		try {
			$live = [
				'notifications' => $this->fetchLiveNotifications(
					keys: $this->orArray(value: $provenance['notificationKeys'] ?? null)
				),
				'lifecycleActions' => $this->fetchLiveLifecycleActions(
					slug: (string)($automation['slug'] ?? ''),
					marked: $this->orArray(value: $provenance['lifecycleActions'] ?? null)
				),
				'schedules' => $this->fetchLiveSchedules(
					versionUuid: (string)($automation['versionUuid'] ?? ''),
					ids: $this->orArray(value: $provenance['scheduleIds'] ?? null)
				),
				'ruleSet' => $this->fetchLiveRuleSet(ruleSetSlug: ($provenance['ruleSetSlug'] ?? null)),
				'conditionActionRule' => $this->fetchLiveConditionActionRule(ruleSetSlug: ($provenance['ruleSetSlug'] ?? null)),
				'approvalChain' => $this->fetchLiveApprovalChain(
					schemaSlug: (string)($automation['trigger']['schema'] ?? ''),
					name: ($provenance['approvalChainName'] ?? null)
				),
			];
		} catch (Throwable $e) {
			// A fetch failure means live state cannot be confirmed —
			// fail safe by reporting drift so the operator is prompted to
			// recompile rather than trusting a possibly-stale badge.
			$this->logger->warning('Buildiq: AutomationCompilerService::status() live fetch failed: ' . $e->getMessage());
			return ['drift' => true, 'compiledHash' => $expectedHash, 'liveHash' => null];
		}//end try

		$liveHash = $this->hashPlan(plan: $live);

		return [
			'drift' => ($liveHash !== $expectedHash),
			'compiledHash' => $expectedHash,
			'liveHash' => $liveHash,
		];

	}//end status()

	/**
	 * Aggregate state of the automation's most recently initialised approval
	 * chain instantiation (spec REQ-AUTD-007 / task 5.1).
	 *
	 * Reads the compiled `ApprovalChain` (by provenance name + the
	 * automation's own `trigger.schema`) and returns the status of the
	 * most-recently-created `ApprovalStep` on it. v1 compiles exactly one
	 * step per `approval` action (design.md Decision 1), so that step's own
	 * `pending|approved|rejected` status IS the aggregate state.
	 *
	 * @param array<string,mixed> $automation The Automation object.
	 * @param array<string,mixed> $provenance The automation's `provenance` block.
	 *
	 * @return string One of `none|pending|approved|rejected`.
	 *
	 * @spec openspec/changes/automation-approval-steps/tasks.md#5.1
	 * @spec openspec/specs/automation-designer/spec.md#requirement-dry-run-test-panel-evaluates-an-automation-without-side-effects-req-autd-007
	 */
	public function approvalState(array $automation, array $provenance): string {
		$chainName = (string)($provenance['approvalChainName'] ?? '');
		if ($chainName === '') {
			return 'none';
		}

		$chain = $this->resolveApprovalChainForState(
			schemaSlug: (string)($automation['trigger']['schema'] ?? ''),
			chainName: $chainName
		);
		if ($chain === null) {
			return 'none';
		}

		$sequence = $this->newestSequenceFor(
			schemaId: (int)($chain['schemaId'] ?? 0),
			chainName: $chainName
		);
		if ($sequence === null) {
			return 'none';
		}

		// TaskSequence's four statuses collapse onto this aggregate's three
		// decided values. `terminated` deliberately reads as `none`: the sequence
		// was closed without anyone deciding, so reporting it as rejected would
		// invent an outcome nobody chose.
		return match ((string)($sequence->getStatus() ?? '')) {
			'running' => 'pending',
			'completed' => 'approved',
			'rejected' => 'rejected',
			default => 'none',
		};
	}//end approvalState()

	/**
	 * Resolve the compiled `ApprovalChain` entity for {@see approvalState()},
	 * or null when the schema/chain cannot be resolved.
	 *
	 * @param string $schemaSlug The automation's `trigger.schema`.
	 * @param string $chainName The `provenance.approvalChainName`.
	 *
	 * @return array<string, mixed>|null The declared chain, or null.
	 */
	private function resolveApprovalChainForState(string $schemaSlug, string $chainName): ?array {
		$schema = $this->loadSchema(slug: $schemaSlug);
		if ($schema === null) {
			return null;
		}

		$schemaId = $schema->getId();
		if ($schemaId === null) {
			return null;
		}

		$config = ($schema->getConfiguration() ?? []);
		$chains = ($config[self::APPROVAL_CHAINS_KEY] ?? []);
		if (is_array($chains) === false || is_array(($chains[$chainName] ?? null)) === false) {
			return null;
		}

		return ['name' => $chainName, 'schemaId' => (int)$schemaId];

	}//end resolveApprovalChainForState()

	/**
	 * Enforce the v1 matrix fail-closed.
	 *
	 * @param string $triggerType The trigger type.
	 * @param mixed $condition The condition block (or null).
	 * @param array<int,mixed> $actions The action records.
	 *
	 * @return void
	 *
	 * @throws UnsupportedAutomationCombinationException
	 */
	private function assertMatrix(string $triggerType, mixed $condition, array $actions): void {
		$allowedActions = (self::MATRIX[$triggerType] ?? null);
		if ($allowedActions === null) {
			throw new UnsupportedAutomationCombinationException(message: 'Unknown or unsupported trigger type "' . $triggerType . '".');
		}

		foreach ($actions as $action) {
			$type = '';
			if (is_array($action) === true) {
				$type = (string)($action['type'] ?? '');
			}

			if (in_array($type, $allowedActions, true) === false) {
				throw new UnsupportedAutomationCombinationException(
					message: 'Trigger "' . $triggerType . '" + action "' . $type . '" is not yet expressible declaratively.'
				);
			}
		}

		if (is_array($condition) === true && in_array($triggerType, self::CONDITION_ALLOWED_TRIGGERS, true) === false) {
			throw new UnsupportedAutomationCombinationException(
				message: 'A condition is only supported on the "manual" trigger in v1 (trigger "' . $triggerType . '" given).'
			);
		}

	}//end assertMatrix()

	/**
	 * Compile-time validation for every `generateDocument` action
	 * (automation-document-action tasks 1.2/1.3 — no compile-time Docudesk
	 * upsert, config validation only): `templateId` present, `output` a
	 * known non-empty set with `notify` never alone, and Docudesk present on
	 * this instance.
	 *
	 * @param array<int,mixed> $actions The automation's action records (already matrix-checked).
	 *
	 * @return void
	 *
	 * @throws UnsupportedAutomationCombinationException On any invalid `generateDocument` config
	 *                                                   or when Docudesk is absent.
	 *
	 * @spec openspec/changes/automation-document-action/tasks.md#1.2
	 * @spec openspec/changes/automation-document-action/tasks.md#1.3
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless
	 *  resolver over a constant map, with no state to inject and nothing to
	 *  substitute in a test. Injecting it would add a constructor argument
	 *  to every consumer to no end.
	 */
	private function assertGenerateDocumentActions(array $actions): void {
		$generateDocumentActions = array_values(
			array_filter(
				$actions,
				static fn ($action): bool => is_array($action) === true && ($action['type'] ?? '') === 'generateDocument'
			)
		);

		if ($generateDocumentActions === []) {
			return;
		}

		if (FleetAppId::isEnabledForUser(appManager: $this->appManager, canonical: self::DOCUDESK_APP_ID) === false) {
			throw new UnsupportedAutomationCombinationException(
				message: 'The "generateDocument" action requires the "' . self::DOCUDESK_APP_ID . '" app, '
				. 'which is not installed or enabled on this instance.'
			);
		}

		foreach ($generateDocumentActions as $action) {
			$templateId = (string)($action['templateId'] ?? '');
			if ($templateId === '') {
				throw new UnsupportedAutomationCombinationException(
					message: 'A "generateDocument" action is missing a required "templateId".'
				);
			}

			$outputModes = $this->normaliseGenerateDocumentOutput(raw: $action['output'] ?? null);
			if ($outputModes === []) {
				throw new UnsupportedAutomationCombinationException(
					message: 'A "generateDocument" action must set "output" to one or more of "'
					. implode('", "', self::GENERATE_DOCUMENT_OUTPUT_MODES) . '".'
				);
			}

			$hasNotify = in_array('notify', $outputModes, true);
			$hasDeliveryMode = in_array('attach', $outputModes, true) === true || in_array('download-link', $outputModes, true) === true;
			if ($hasNotify === true && $hasDeliveryMode === false) {
				throw new UnsupportedAutomationCombinationException(
					message: 'A "generateDocument" action with "output: notify" must also set "attach" '
					. 'and/or "download-link" — "notify" alone is incomplete.'
				);
			}
		}//end foreach

	}//end assertGenerateDocumentActions()

	/**
	 * Normalise a `generateDocument` action's `output` field to a
	 * deduplicated list of known modes (tolerates the single-string
	 * shorthand shown in design.md's seed data example).
	 *
	 * @param mixed $raw The action's raw `output` value.
	 *
	 * @return array<int,string>
	 */
	private function normaliseGenerateDocumentOutput(mixed $raw): array {
		$modes = [];
		if (is_array($raw) === true) {
			$modes = $raw;
		} elseif (is_string($raw) === true && $raw !== '') {
			$modes = [$raw];
		}

		return array_values(
			array_unique(
				array_filter(
					$modes,
					static fn ($m): bool => is_string($m) === true && in_array($m, self::GENERATE_DOCUMENT_OUTPUT_MODES, true) === true
				)
			)
		);

	}//end normaliseGenerateDocumentOutput()

	/**
	 * Dialect backend: event/lifecycle-transition triggers.
	 *
	 * @param string $slug Automation slug.
	 * @param array<string,mixed> $trigger Trigger block.
	 * @param string $triggerType Trigger type.
	 * @param array<int,mixed> $actions Action records.
	 * @param bool $enabled Automation enabled flag.
	 * @param array<string,mixed> $plan The plan being built (by reference).
	 *
	 * @return void
	 */
	private function compileDialectBackend(string $slug, array $trigger, string $triggerType, array $actions, bool $enabled, array &$plan): void {
		$schema = (string)($trigger['schema'] ?? '');
		$transition = (string)($trigger['transition'] ?? '');
		$marker = 'aut-' . $slug;
		$notifIndex = 0;

		foreach ($actions as $action) {
			$type = (string)($action['type'] ?? '');

			if ($type === 'send-notification') {
				$notifIndex++;
				$dialectTrigger = ['type' => $this->dialectEventType(triggerType: $triggerType)];
				if ($triggerType === 'lifecycle-transition') {
					$dialectTrigger = ['type' => 'transition', 'action' => $transition];
				}

				$plan['notifications'][] = [
					'schema' => $schema,
					'key' => 'aut-' . $slug . '-' . $notifIndex,
					'entry' => [
						'trigger' => $dialectTrigger,
						'enabled' => $enabled,
						'channels' => array_values((array)($action['channels'] ?? ['nc-notification'])),
						'recipients' => array_values((array)($action['recipients'] ?? [])),
						'subject' => (array)($action['subject'] ?? []),
					],
				];
				continue;
			}

			if ($type === 'approval') {
				// One `ApprovalChain` per automation (design.md Decision 2 of
				// automation-approval-steps) — a second `approval` action in
				// the same automation would overwrite this entry, matching
				// the "exactly one step per approval action" v1 scope.
				$plan['approvalChain'] = [
					'name' => $marker,
					'schema' => $schema,
					'assigneeGroup' => (string)($action['assigneeGroup'] ?? ''),
					'enabled' => $enabled,
				];
				continue;
			}

			if ($type === 'generateDocument') {
				// No compile-time plan entry (design.md Decision 2 of
				// automation-document-action — Docudesk's generate route is
				// stateless; already config-validated by
				// {@see assertGenerateDocumentActions()}). The
				// owner-impersonated Docudesk call happens at trigger-fire
				// time in {@see \OCA\Buildiq\Listener\DocumentGenerationListener},
				// reading the action straight off the stored `Automation`
				// object — the compiler has nothing to provision or upsert.
				continue;
			}

			if ($triggerType !== 'lifecycle-transition') {
				// The matrix already blocked any other action type for
				// object-* triggers — defensive no-op.
				continue;
			}

			if ($type === 'object-op') {
				$plan['lifecycleActions'][] = [
					'schema' => $schema,
					'transition' => $transition,
					'marker' => $marker,
					'action' => [
						'type' => 'related-object-upsert',
						'operation' => (string)($action['operation'] ?? 'create'),
						'schema' => (string)($action['schema'] ?? ''),
						'fieldMapping' => (array)($action['fieldMapping'] ?? []),
						'marker' => $marker,
					],
				];
			} elseif ($type === 'webhook') {
				$plan['lifecycleActions'][] = [
					'schema' => $schema,
					'transition' => $transition,
					'marker' => $marker,
					'action' => [
						'type' => 'webhook-dispatch',
						'url' => (string)($action['url'] ?? ''),
						'payloadTemplate' => (array)($action['payloadTemplate'] ?? []),
						'marker' => $marker,
					],
				];
			}//end if
		}//end foreach

	}//end compileDialectBackend()

	/**
	 * Map an object-event trigger type to the notifications dialect's event
	 * name (verified shape: `10-business-rules.json` uses `transition`; ADR-031
	 * documents `created|updated|deleted` as the dialect's event types).
	 *
	 * @param string $triggerType The automation trigger type.
	 *
	 * @return string
	 */
	private function dialectEventType(string $triggerType): string {
		return match ($triggerType) {
			'object-created' => 'created',
			'object-updated' => 'updated',
			'object-deleted' => 'deleted',
			default => $triggerType,
		};

	}//end dialectEventType()

	/**
	 * Schedules backend: `schedule` trigger + `run-synchronization`.
	 *
	 * @param string $slug Automation slug.
	 * @param array<string,mixed> $trigger Trigger block.
	 * @param array<int,mixed> $actions Action records.
	 * @param bool $enabled Automation enabled flag.
	 * @param array<string,mixed> $plan The plan being built (by reference).
	 *
	 * @return void
	 */
	private function compileSchedulesBackend(string $slug, array $trigger, array $actions, bool $enabled, array &$plan): void {
		$index = 0;
		foreach ($actions as $action) {
			if ((string)($action['type'] ?? '') !== 'run-synchronization') {
				continue;
			}

			$index++;
			$entry = [
				'id' => 'aut-' . $slug . '-' . $index,
				'enabled' => $enabled,
				'action' => 'openconnector:synchronization',
				'arguments' => ['synchronizationId' => (string)($action['synchronizationId'] ?? '')],
			];

			$cron = (string)($trigger['cron'] ?? '');
			if ($cron !== '') {
				$entry['cron'] = $cron;
			} else {
				$entry['interval'] = (int)($trigger['interval'] ?? 86400);
			}

			$plan['schedules'][] = $entry;
		}//end foreach

	}//end compileSchedulesBackend()

	/**
	 * Rules backend: `manual` trigger.
	 *
	 * @param array<string,mixed> $automation The full Automation object (for slug/name/applicationSlug/uuid).
	 * @param string $slug Automation slug.
	 * @param mixed $condition Condition block (or null).
	 * @param array<int,mixed> $actions Action records.
	 * @param bool $enabled Automation enabled flag.
	 * @param array<string,mixed> $plan The plan being built (by reference).
	 *
	 * @return void
	 */
	private function compileRulesBackend(array $automation, string $slug, mixed $condition, array $actions, bool $enabled, array &$plan): void {
		$ruleSetSlug = 'aut-' . $this->shortUuid(automation: $automation);
		['condition' => $compiledCondition, 'actions' => $mappedActions] = $this->buildConditionAndActions(condition: $condition, actions: $actions);

		$plan['ruleSet'] = [
			'slug' => $ruleSetSlug,
			'name' => (string)($automation['name'] ?? $slug),
			'version' => '1.0.0',
			'status' => 'active',
			'ruleType' => 'condition-action',
			'ownerApp' => (string)($automation['applicationSlug'] ?? ''),
		];

		$plan['conditionActionRule'] = [
			'ruleSetId' => $ruleSetSlug,
			'name' => (string)($automation['name'] ?? $slug),
			'condition' => $compiledCondition,
			'actions' => $mappedActions,
			'active' => $enabled,
		];

	}//end compileRulesBackend()

	/**
	 * Compile an automation IN-MEMORY to a rules-backend-shaped
	 * ConditionActionRule for the dry-run test panel (design.md Decision 9) —
	 * unlike {@see compile()}, this does NOT enforce the matrix by trigger
	 * type and never mints/persists a RuleSet: every matrix cell's actions
	 * map 1:1 onto the executor's typed action records so the SAME dry-run
	 * panel works for every trigger, without requiring a persisted RuleSet
	 * (event/schedule automations never have one).
	 *
	 * @param array<string,mixed> $automation The Automation object.
	 *
	 * @return array<string,mixed> A synthetic ConditionActionRule-shaped record.
	 *
	 * @spec openspec/changes/automation-designer/tasks.md#3.1
	 * @spec openspec/specs/automation-designer/spec.md#requirement-dry-run-test-panel-evaluates-an-automation-without-side-effects-req-autd-007
	 */
	public function compileDryRunRule(array $automation): array {
		$condition = ($automation['condition'] ?? null);
		$actions = array_values($this->orArray(value: $automation['actions'] ?? null));

		['condition' => $compiledCondition, 'actions' => $mappedActions] = $this->buildConditionAndActions(condition: $condition, actions: $actions);

		return [
			'name' => (string)($automation['name'] ?? ($automation['slug'] ?? '')),
			'condition' => $compiledCondition,
			'actions' => $mappedActions,
			'active' => true,
			'priority' => 0,
			'salience' => 0,
		];

	}//end compileDryRunRule()

	/**
	 * Shared condition→`condition` + actions→`actions` mapping used by both the
	 * rules backend and the dry-run panel.
	 *
	 * @param mixed $condition Condition block (or null).
	 * @param array<int,mixed> $actions Automation action records.
	 *
	 * @return array{condition:string,actions:array<int,array<string,mixed>>}
	 */
	private function buildConditionAndActions(mixed $condition, array $actions): array {
		$compiledCondition = '';
		$mappedActions = [];

		if (is_array($condition) === true) {
			$conditionType = (string)($condition['type'] ?? '');
			if ($conditionType === 'feel') {
				$compiledCondition = (string)($condition['expression'] ?? '');
			} elseif ($conditionType === 'rule-set') {
				$refSlug = (string)($condition['ruleSetSlug'] ?? '');
				if ($refSlug !== '') {
					// No existing primitive gates our own actions on a
					// referenced RuleSet's boolean result (documented v1
					// simplification) — the reference is dispatched
					// fire-and-forget ahead of the mapped actions, which
					// always run unconditionally in this shape.
					$mappedActions[] = ['type' => 'call-rule-set', 'parameters' => ['ruleSetSlug' => $refSlug]];
				}
			}
		}

		foreach ($actions as $action) {
			$mappedActions[] = $this->mapActionToRuleAction(action: $this->orArray(value: $action));
		}

		return ['condition' => $compiledCondition, 'actions' => $mappedActions];
	}//end buildConditionAndActions()

	/**
	 * Map one Automation action record to a ConditionActionRule typed action.
	 *
	 * PUBLIC (not merely an internal compile-time helper): also reused by
	 * {@see \OCA\Buildiq\Listener\ApprovalOutcomeListener} to map an
	 * `approval` action's `onApprove`/`onReject` follow-up action records to
	 * the SAME typed-action shape {@see \OCA\Buildiq\Service\RuleActionDispatcher}
	 * already dispatches — the follow-up fires once per approval outcome
	 * through the identical dispatch mechanism the rules backend uses, per
	 * design.md Decision 3 of automation-approval-steps ("through the same
	 * dialect/notification compilation the automation already uses").
	 *
	 * @param array<string,mixed> $action The Automation action record.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/automation-approval-steps/tasks.md#2.1
	 */
	public function mapActionToRuleAction(array $action): array {
		$type = (string)($action['type'] ?? '');

		return match ($type) {
			'send-notification' => [
				'type' => 'send-notification',
				'parameters' => [
					'subject' => $this->subjectText(action: $action),
					'recipientUid' => (string)($action['recipientUid'] ?? ''),
				],
			],
			'object-op' => [
				'type' => 'object-op',
				'parameters' => [
					'schema' => (string)($action['schema'] ?? ''),
					'operation' => (string)($action['operation'] ?? 'create'),
					'object' => (array)($action['fieldMapping'] ?? []),
					'register' => self::REGISTER_SLUG,
				],
			],
			'webhook' => [
				'type' => 'webhook',
				'parameters' => [
					'url' => (string)($action['url'] ?? ''),
					'payload' => (array)($action['payloadTemplate'] ?? []),
				],
			],
			'approval' => [
				'type' => 'approval',
				'parameters' => [
					'assigneeGroup' => (string)($action['assigneeGroup'] ?? ''),
				],
			],
			'generateDocument' => [
				'type' => 'generateDocument',
				'parameters' => [
					'templateId' => (string)($action['templateId'] ?? ''),
					'output' => $this->normaliseGenerateDocumentOutput(raw: $action['output'] ?? null),
				],
			],
			default => ['type' => $type, 'parameters' => []],
		};// End match.

	}//end mapActionToRuleAction()

	/**
	 * Resolve a send-notification action's subject text: the English
	 * localized subject when `subject` is a `{nl,en}` map, or the raw value
	 * when it is a plain string.
	 *
	 * @param array<string,mixed> $action The Automation action record.
	 *
	 * @return string
	 */
	private function subjectText(array $action): string {
		$subject = ($action['subject'] ?? '');
		if (is_array($subject) === true) {
			return (string)($subject['en'] ?? '');
		}

		return (string)$subject;
	}//end subjectText()

	/**
	 * Derive the first 8 hex characters of the automation's own uuid
	 * (design.md Decision 6 — rule-set slugs are keyed off the automation
	 * object uuid so version clones get distinct rule sets).
	 *
	 * @param array<string,mixed> $automation The Automation object.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the automation has no persisted id/uuid yet.
	 */
	private function shortUuid(array $automation): string {
		$id = (string)($automation['id'] ?? $automation['uuid'] ?? '');
		if ($id === '') {
			throw new RuntimeException('Automation must be persisted (have an id) before it can be compiled.');
		}

		$hex = str_replace('-', '', $id);
		return substr($hex, 0, 8);
	}//end shortUuid()

	/**
	 * Canonical-JSON sha256 hash of the four compiled backends (excludes the
	 * `hash` key itself).
	 *
	 * @param array<string,mixed> $plan The plan (or a live-fetched equivalent).
	 *
	 * @return string `sha256:<hex>`
	 */
	private function hashPlan(array $plan): string {
		$hashable = [
			'notifications' => ($plan['notifications'] ?? []),
			'lifecycleActions' => ($plan['lifecycleActions'] ?? []),
			'schedules' => ($plan['schedules'] ?? []),
			'ruleSet' => ($plan['ruleSet'] ?? null),
			'conditionActionRule' => ($plan['conditionActionRule'] ?? null),
			'approvalChain' => ($plan['approvalChain'] ?? null),
		];

		return 'sha256:' . hash(algo: 'sha256', data: $this->canonicalise(value: $hashable));
	}//end hashPlan()

	/**
	 * Recursively key-sort associative arrays for a byte-stable JSON string;
	 * list arrays keep their order (it is semantically meaningful).
	 *
	 * @param mixed $value The value to canonicalise.
	 *
	 * @return string Canonical JSON.
	 */
	private function canonicalise(mixed $value): string {
		return json_encode($this->canonicaliseValue(value: $value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end canonicalise()

	/**
	 * Recursive helper for {@see canonicalise()}.
	 *
	 * @param mixed $value The value to canonicalise.
	 *
	 * @return mixed
	 */
	private function canonicaliseValue(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$isList = array_is_list($value);
		if ($isList === true) {
			return array_map(fn ($v) => $this->canonicaliseValue(value: $v), $value);
		}

		ksort($value);
		$out = [];
		foreach ($value as $k => $v) {
			$out[$k] = $this->canonicaliseValue(value: $v);
		}

		return $out;
	}//end canonicaliseValue()

	/**
	 * Upsert planned notification entries onto their target schemas, removing
	 * any prior-provenance key no longer planned.
	 *
	 * @param array<int,array<string,mixed>> $planned Planned `{schema,key,entry}` records.
	 * @param array<int,array<string,mixed>> $priorKeys Prior-provenance `{schema,key}` records.
	 *
	 * @return array<int,array<string,mixed>> The new provenance `notificationKeys` list.
	 */
	private function applyNotifications(array $planned, array $priorKeys): array {
		$bySchema = [];
		foreach ($planned as $entry) {
			$bySchema[(string)$entry['schema']][] = $entry;
		}

		$priorBySchema = [];
		foreach ($priorKeys as $entry) {
			$priorBySchema[(string)($entry['schema'] ?? '')][] = (string)($entry['key'] ?? '');
		}

		$result = [];
		$schemas = array_unique(array_merge(array_keys($bySchema), array_keys($priorBySchema)));

		foreach ($schemas as $schemaSlug) {
			if ($schemaSlug === '') {
				continue;
			}

			$schema = $this->loadSchema(slug: $schemaSlug);
			if ($schema === null) {
				continue;
			}

			$config = ($schema->getConfiguration() ?? []);
			$map = $this->orArray(value: $config['x-openregister-notifications'] ?? null);

			$keepKeys = array_map(static fn (array $entry): string => (string)$entry['key'], ($bySchema[$schemaSlug] ?? []));
			foreach (($priorBySchema[$schemaSlug] ?? []) as $oldKey) {
				if (in_array($oldKey, $keepKeys, true) === false) {
					unset($map[$oldKey]);
				}
			}

			foreach (($bySchema[$schemaSlug] ?? []) as $entry) {
				$map[$entry['key']] = $entry['entry'];
				$result[] = ['schema' => $schemaSlug, 'key' => $entry['key']];
			}

			$config['x-openregister-notifications'] = $map;
			$schema->setConfiguration($config);
			$this->schemaMapper->update($schema);
		}//end foreach

		return $result;
	}//end applyNotifications()

	/**
	 * Upsert planned lifecycle actions onto their target schemas/transitions,
	 * stripping every marker-tagged action first (handles a transition change
	 * between compiles) then re-adding the planned ones.
	 *
	 * @param string $slug Automation slug (derives the `aut-<slug>` marker).
	 * @param array<int,array<string,mixed>> $planned Planned `{schema,transition,marker,action}` records.
	 * @param array<int,array<string,mixed>> $priorActions Prior-provenance `{schema,transition,marker}` records.
	 *
	 * @return array<int,array<string,mixed>> The new provenance `lifecycleActions` list.
	 */
	private function applyLifecycleActions(string $slug, array $planned, array $priorActions): array {
		$marker = 'aut-' . $slug;

		$plannedBySchema = [];
		foreach ($planned as $entry) {
			$plannedBySchema[(string)$entry['schema']][] = $entry;
		}

		$schemas = array_unique(
			array_merge(
				array_keys($plannedBySchema),
				array_map(static fn (array $entry): string => (string)($entry['schema'] ?? ''), $priorActions)
			)
		);

		$result = [];
		foreach ($schemas as $schemaSlug) {
			if ($schemaSlug === '') {
				continue;
			}

			$schema = $this->loadSchema(slug: $schemaSlug);
			if ($schema === null) {
				continue;
			}

			$config = ($schema->getConfiguration() ?? []);
			$lifecycle = $this->orNullArray(value: $config['x-openregister-lifecycle'] ?? null);
			if ($lifecycle === null) {
				continue;
			}

			$transitions = $this->orArray(value: $lifecycle['transitions'] ?? null);

			foreach ($transitions as $tName => $t) {
				$actions = $this->orArray(value: $t['actions'] ?? null);
				$transitions[$tName]['actions'] = array_values(
					array_filter(
						$actions,
						static fn ($a): bool => is_array($a) === false || ($a['marker'] ?? null) !== $marker
					)
				);
			}

			foreach (($plannedBySchema[$schemaSlug] ?? []) as $entry) {
				$tName = (string)$entry['transition'];
				if (isset($transitions[$tName]) === false) {
					continue;
				}

				$actions = $this->orArray(value: $transitions[$tName]['actions'] ?? null);
				$actions[] = $entry['action'];
				$transitions[$tName]['actions'] = $actions;
				$result[] = ['schema' => $schemaSlug, 'transition' => $tName, 'marker' => $marker];
			}

			$lifecycle['transitions'] = $transitions;
			$config['x-openregister-lifecycle'] = $lifecycle;
			$schema->setConfiguration($config);
			$this->schemaMapper->update($schema);
		}//end foreach

		return $result;
	}//end applyLifecycleActions()

	/**
	 * Upsert planned schedules entries into the target ApplicationVersion's
	 * manifest, removing any prior-provenance id no longer planned.
	 *
	 * @param string $versionUuid Target ApplicationVersion uuid.
	 * @param array<int,array<string,mixed>> $plannedEntries Planned schedules entries.
	 * @param array<int,string> $priorScheduleIds Prior-provenance schedule ids.
	 *
	 * @return array<int,string> The new provenance `scheduleIds` list.
	 */
	private function applySchedules(string $versionUuid, array $plannedEntries, array $priorScheduleIds): array {
		if ($plannedEntries === [] && $priorScheduleIds === []) {
			return [];
		}

		$version = $this->loadApplicationVersion(uuid: $versionUuid);
		if ($version === null) {
			$this->logger->warning('Buildiq: AutomationCompilerService could not load ApplicationVersion "' . $versionUuid . '" to apply schedules.');
			return [];
		}

		$manifest = $this->orArray(value: $version['manifest'] ?? null);
		$schedules = $this->orArray(value: $manifest['schedules'] ?? null);

		$plannedIds = array_map(static fn (array $e): string => (string)$e['id'], $plannedEntries);

		$schedules = array_values(
			array_filter(
				$schedules,
				static function ($scheduleRow) use ($priorScheduleIds, $plannedIds): bool {
					$id = '';
					if (is_array($scheduleRow) === true) {
						$id = (string)($scheduleRow['id'] ?? '');
					}

					return in_array($id, $priorScheduleIds, true) === false || in_array($id, $plannedIds, true) === true;
				}
			)
		);

		foreach ($plannedEntries as $entry) {
			$idx = null;
			foreach ($schedules as $i => $scheduleRow) {
				$rowId = null;
				if (is_array($scheduleRow) === true) {
					$rowId = ($scheduleRow['id'] ?? null);
				}

				if ($rowId === $entry['id']) {
					$idx = $i;
					break;
				}
			}

			if ($idx !== null) {
				$schedules[$idx] = $entry;
			} else {
				$schedules[] = $entry;
			}
		}

		if ($schedules === []) {
			unset($manifest['schedules']);
		} else {
			$manifest['schedules'] = array_values($schedules);
		}

		$version['manifest'] = $manifest;

		try {
			$this->objectService->saveObject(object: $version, register: self::REGISTER_SLUG, schema: 'applicationVersion', uuid: $versionUuid);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: AutomationCompilerService failed to save schedules onto ApplicationVersion "' . $versionUuid . '": ' . $e->getMessage()
			);
		}

		return $plannedIds;
	}//end applySchedules()

	/**
	 * Upsert the compiled RuleSet + ConditionActionRule (manual trigger), or
	 * remove a prior one when the automation no longer compiles to the rules
	 * backend.
	 *
	 * @param array<string,mixed>|null $ruleSet Planned RuleSet fields, or null.
	 * @param array<string,mixed>|null $conditionActionRule Planned ConditionActionRule fields, or null.
	 * @param string|null $priorRuleSetSlug Prior-provenance RuleSet slug, if any.
	 *
	 * @return string|null The applied RuleSet slug, or null.
	 */
	private function applyRuleSet(?array $ruleSet, ?array $conditionActionRule, ?string $priorRuleSetSlug): ?string {
		if ($ruleSet === null || $conditionActionRule === null) {
			if ($priorRuleSetSlug !== null && $priorRuleSetSlug !== '') {
				$this->removeRuleSet(ruleSetSlug: $priorRuleSetSlug);
			}

			return null;
		}

		$existingRuleSet = $this->findOneObject(schema: RuleEngineService::RULE_SET_SCHEMA, filters: ['slug' => $ruleSet['slug']]);
		try {
			if ($existingRuleSet !== null) {
				$id = (string)($existingRuleSet['id'] ?? $existingRuleSet['uuid'] ?? '');
				$this->objectService->saveObject(
					object: $ruleSet,
					register: self::REGISTER_SLUG,
					schema: RuleEngineService::RULE_SET_SCHEMA,
					uuid: $id
				);
			} else {
				$this->objectService->saveObject(object: $ruleSet, register: self::REGISTER_SLUG, schema: RuleEngineService::RULE_SET_SCHEMA);
			}
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: AutomationCompilerService failed to save RuleSet "' . $ruleSet['slug'] . '": ' . $e->getMessage());
		}

		$existingRule = $this->findOneObject(schema: RuleEngineService::CONDITION_RULE_SCHEMA, filters: ['ruleSetId' => $ruleSet['slug']]);
		try {
			if ($existingRule !== null) {
				$id = (string)($existingRule['id'] ?? $existingRule['uuid'] ?? '');
				$this->objectService->saveObject(
					object: $conditionActionRule,
					register: self::REGISTER_SLUG,
					schema: RuleEngineService::CONDITION_RULE_SCHEMA,
					uuid: $id
				);
			} else {
				$this->objectService->saveObject(
					object: $conditionActionRule,
					register: self::REGISTER_SLUG,
					schema: RuleEngineService::CONDITION_RULE_SCHEMA
				);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: AutomationCompilerService failed to save ConditionActionRule for "' . $ruleSet['slug'] . '": ' . $e->getMessage()
			);
		}//end try

		return (string)$ruleSet['slug'];
	}//end applyRuleSet()

	/**
	 * Delete the RuleSet + its ConditionActionRule by slug.
	 *
	 * @param string $ruleSetSlug The RuleSet slug to remove.
	 *
	 * @return void
	 */
	private function removeRuleSet(string $ruleSetSlug): void {
		$rule = $this->findOneObject(schema: RuleEngineService::CONDITION_RULE_SCHEMA, filters: ['ruleSetId' => $ruleSetSlug]);
		if ($rule !== null) {
			$id = (string)($rule['id'] ?? $rule['uuid'] ?? '');
			if ($id !== '') {
				$this->deleteObjectQuietly(uuid: $id, schema: RuleEngineService::CONDITION_RULE_SCHEMA);
			}
		}

		$ruleSet = $this->findOneObject(schema: RuleEngineService::RULE_SET_SCHEMA, filters: ['slug' => $ruleSetSlug]);
		if ($ruleSet !== null) {
			$id = (string)($ruleSet['id'] ?? $ruleSet['uuid'] ?? '');
			if ($id !== '') {
				$this->deleteObjectQuietly(uuid: $id, schema: RuleEngineService::RULE_SET_SCHEMA);
			}
		}

	}//end removeRuleSet()

	/**
	 * Upsert the compiled `ApprovalChain` (event/lifecycle-transition +
	 * `approval`), or remove a prior one when the automation no longer
	 * compiles an approval action.
	 *
	 * Mirrors {@see applyRuleSet()}'s find-then-write shape, but the chain is
	 * not an object OR a row: since openregister #3302 it is a DECLARATION in
	 * the schema's `configuration`, the same place this service already writes
	 * `x-openregister-notifications` and `x-openregister-lifecycle`. OR compiles
	 * it into a task template on demand (ADR-022 consume-not-rebuild — buildiq
	 * declares, it does not run an approval engine).
	 *
	 * @param array<string,mixed>|null $planned Planned `{name,schema,assigneeGroup,enabled}`, or null.
	 * @param string|null $priorName Prior-provenance `ApprovalChain` name, if any.
	 * @param string $fallbackSchemaSlug The automation's CURRENT `trigger.schema` — used to
	 *                                   resolve `$priorName`'s schema when `$planned` is null
	 *                                   (automation edited to drop the approval action) or when
	 *                                   the name itself changed (slug rename).
	 *
	 * @return string|null The applied `ApprovalChain` name, or null.
	 *
	 * @spec openspec/changes/automation-approval-steps/tasks.md#1.2
	 * @spec openspec/changes/automation-approval-steps/tasks.md#1.4
	 */
	private function applyApprovalChain(?array $planned, ?string $priorName, string $fallbackSchemaSlug): ?string {
		if ($planned === null) {
			if ($priorName !== null && $priorName !== '') {
				$this->removeApprovalChain(name: $priorName, schemaSlug: $fallbackSchemaSlug);
			}

			return null;
		}

		$schemaSlug = (string)($planned['schema'] ?? '');
		$schema = $this->loadSchema(slug: $schemaSlug);
		if ($schema === null) {
			$this->logger->warning(
				'Buildiq: AutomationCompilerService could not load schema "' . $schemaSlug . '" to apply approval chain "' . $planned['name'] . '".'
			);
			return null;
		}

		$schemaId = $schema->getId();
		if ($schemaId === null) {
			return null;
		}

		$payload = [
			'name' => (string)$planned['name'],
			'schemaId' => (int)$schemaId,
			'steps' => [['order' => 1, 'role' => (string)($planned['assigneeGroup'] ?? '')]],
			'enabled' => (bool)($planned['enabled'] ?? true),
		];

		$this->upsertApprovalChain(payload: $payload, schemaId: (int)$schemaId);
		$this->cleanupStaleApprovalChain(priorName: $priorName, currentName: $payload['name'], schemaSlug: $schemaSlug);

		return $payload['name'];
	}//end applyApprovalChain()

	/**
	 * Find-then-create/update the `ApprovalChain` row for one compiled plan.
	 *
	 * @param array<string,mixed> $payload The chain payload (`name`,`schemaId`,`steps`,`enabled`).
	 * @param int $schemaId The owning schema id.
	 *
	 * @return void
	 */
	private function upsertApprovalChain(array $payload, int $schemaId): void {
		$schema = $this->loadSchemaById(schemaId: $schemaId);
		if ($schema === null) {
			return;
		}

		$config = ($schema->getConfiguration() ?? []);
		$chains = ($config[self::APPROVAL_CHAINS_KEY] ?? []);
		if (is_array($chains) === false) {
			$chains = [];
		}

		// The declared shape ApprovalChainAnnotationInstaller::compile() reads:
		// one `approvers` entry per ordered position, each carrying a role. The
		// compiler assigns `order` itself, so it is not written here.
		$approvers = [];
		foreach ((array)($payload['steps'] ?? []) as $step) {
			$role = trim((string)($step['role'] ?? ''));
			if ($role !== '') {
				$approvers[] = ['role' => $role];
			}
		}

		if ($approvers === []) {
			$this->logger->warning(
				'Buildiq: AutomationCompilerService declared no approver role for approval chain "' . $payload['name'] . '".'
			);
			return;
		}

		$chains[(string)$payload['name']] = [
			'approvers' => $approvers,
			'enabled' => (bool)($payload['enabled'] ?? true),
		];
		$config[self::APPROVAL_CHAINS_KEY] = $chains;

		$this->saveSchemaConfiguration(
			schema: $schema,
			config: $config,
			what: 'approval chain "' . $payload['name'] . '"'
		);

	}//end upsertApprovalChain()

	/**
	 * Remove a prior-provenance chain whose name/schema changed since the
	 * last apply (automation slug or trigger.schema was edited) so recompile
	 * never leaves an orphan (design.md Decision 4 "managed as one unit").
	 *
	 * @param string|null $priorName Prior-provenance `ApprovalChain` name, if any.
	 * @param string $currentName The just-applied chain name.
	 * @param string $schemaSlug The schema slug the chain is scoped to.
	 *
	 * @return void
	 */
	private function cleanupStaleApprovalChain(?string $priorName, string $currentName, string $schemaSlug): void {
		if ($priorName === null || $priorName === '' || $priorName === $currentName) {
			return;
		}

		$this->removeApprovalChain(name: $priorName, schemaSlug: $schemaSlug);

	}//end cleanupStaleApprovalChain()

	/**
	 * Delete an `ApprovalChain` by schema + name. Best-effort — logs but
	 * never throws (mirrors {@see deleteObjectQuietly()}).
	 *
	 * @param string $name The `ApprovalChain` name (`aut-<slug>`).
	 * @param string $schemaSlug The schema slug the chain is scoped to.
	 *
	 * @return void
	 */
	private function removeApprovalChain(string $name, string $schemaSlug): void {
		$schema = $this->loadSchema(slug: $schemaSlug);
		if ($schema === null) {
			return;
		}

		$schemaId = $schema->getId();
		if ($schemaId === null) {
			return;
		}

		$config = ($schema->getConfiguration() ?? []);
		$chains = ($config[self::APPROVAL_CHAINS_KEY] ?? []);
		if (is_array($chains) === false || array_key_exists($name, $chains) === false) {
			return;
		}

		unset($chains[$name]);
		if ($chains === []) {
			unset($config[self::APPROVAL_CHAINS_KEY]);
		} else {
			$config[self::APPROVAL_CHAINS_KEY] = $chains;
		}

		$this->saveSchemaConfiguration(
			schema: $schema,
			config: $config,
			what: 'the removal of approval chain "' . $name . '"'
		);

	}//end removeApprovalChain()

	/**
	 * Best-effort object delete — logs but never throws.
	 *
	 * @param string $uuid The object uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return void
	 */
	private function deleteObjectQuietly(string $uuid, string $schema): void {
		try {
			$this->objectService->deleteObject(uuid: $uuid, register: self::REGISTER_SLUG, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: AutomationCompilerService failed to delete ' . $schema . ' "' . $uuid . '": ' . $e->getMessage());
		}

	}//end deleteObjectQuietly()

	/**
	 * Fetch the live notification entries for a set of provenance keys.
	 *
	 * @param array<int,array<string,mixed>> $keys `{schema,key}` records.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchLiveNotifications(array $keys): array {
		$result = [];
		foreach ($keys as $k) {
			$schemaSlug = (string)($k['schema'] ?? '');
			$key = (string)($k['key'] ?? '');
			if ($schemaSlug === '' || $key === '') {
				continue;
			}

			$schema = $this->loadSchema(slug: $schemaSlug);
			if ($schema === null) {
				continue;
			}

			$config = ($schema->getConfiguration() ?? []);
			$map = $this->orArray(value: $config['x-openregister-notifications'] ?? null);

			$result[] = ['schema' => $schemaSlug, 'key' => $key, 'entry' => ($map[$key] ?? null)];
		}

		return $result;
	}//end fetchLiveNotifications()

	/**
	 * Fetch the live marker-tagged lifecycle actions.
	 *
	 * @param string $slug Automation slug.
	 * @param array<int,array<string,mixed>> $marked `{schema,transition}` records.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchLiveLifecycleActions(string $slug, array $marked): array {
		$marker = 'aut-' . $slug;
		$result = [];
		foreach ($marked as $m) {
			$schemaSlug = (string)($m['schema'] ?? '');
			$tName = (string)($m['transition'] ?? '');
			if ($schemaSlug === '' || $tName === '') {
				continue;
			}

			$schema = $this->loadSchema(slug: $schemaSlug);
			if ($schema === null) {
				continue;
			}

			$config = ($schema->getConfiguration() ?? []);
			$lifecycle = $this->orArray(value: $config['x-openregister-lifecycle'] ?? null);
			$transitions = $this->orArray(value: $lifecycle['transitions'] ?? null);
			$actions = $this->orArray(value: $transitions[$tName]['actions'] ?? null);

			foreach ($actions as $a) {
				if (is_array($a) === true && ($a['marker'] ?? null) === $marker) {
					$result[] = ['schema' => $schemaSlug, 'transition' => $tName, 'marker' => $marker, 'action' => $a];
				}
			}
		}//end foreach

		return $result;
	}//end fetchLiveLifecycleActions()

	/**
	 * Fetch the live schedules entries for a set of provenance ids.
	 *
	 * @param string $versionUuid Target ApplicationVersion uuid.
	 * @param array<int,string> $ids Provenance schedule ids.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetchLiveSchedules(string $versionUuid, array $ids): array {
		if ($ids === []) {
			return [];
		}

		$version = $this->loadApplicationVersion(uuid: $versionUuid);
		if ($version === null) {
			return [];
		}

		$manifest = $this->orArray(value: $version['manifest'] ?? null);
		$schedules = $this->orArray(value: $manifest['schedules'] ?? null);

		$result = [];
		foreach ($schedules as $s) {
			$id = null;
			if (is_array($s) === true) {
				$id = ($s['id'] ?? null);
			}

			if (is_string($id) === true && in_array($id, $ids, true) === true) {
				$result[] = $s;
			}
		}

		return $result;
	}//end fetchLiveSchedules()

	/**
	 * Fetch the live RuleSet's comparable fields.
	 *
	 * @param mixed $ruleSetSlug Provenance RuleSet slug, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchLiveRuleSet(mixed $ruleSetSlug): ?array {
		if (is_string($ruleSetSlug) === false || $ruleSetSlug === '') {
			return null;
		}

		$ruleSet = $this->findOneObject(schema: RuleEngineService::RULE_SET_SCHEMA, filters: ['slug' => $ruleSetSlug]);
		if ($ruleSet === null) {
			return null;
		}

		return [
			'slug' => (string)($ruleSet['slug'] ?? ''),
			'name' => (string)($ruleSet['name'] ?? ''),
			'version' => (string)($ruleSet['version'] ?? ''),
			'status' => (string)($ruleSet['status'] ?? ''),
			'ruleType' => (string)($ruleSet['ruleType'] ?? ''),
			'ownerApp' => (string)($ruleSet['ownerApp'] ?? ''),
		];

	}//end fetchLiveRuleSet()

	/**
	 * Fetch the live ConditionActionRule's comparable fields.
	 *
	 * @param mixed $ruleSetSlug Provenance RuleSet slug, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchLiveConditionActionRule(mixed $ruleSetSlug): ?array {
		if (is_string($ruleSetSlug) === false || $ruleSetSlug === '') {
			return null;
		}

		$rule = $this->findOneObject(schema: RuleEngineService::CONDITION_RULE_SCHEMA, filters: ['ruleSetId' => $ruleSetSlug]);
		if ($rule === null) {
			return null;
		}

		return [
			'ruleSetId' => (string)($rule['ruleSetId'] ?? ''),
			'name' => (string)($rule['name'] ?? ''),
			'condition' => (string)($rule['condition'] ?? ''),
			'actions' => (array)($rule['actions'] ?? []),
			'active' => (bool)($rule['active'] ?? true),
		];

	}//end fetchLiveConditionActionRule()

	/**
	 * Fetch the live `ApprovalChain`'s comparable fields (drift detection).
	 *
	 * @param string $schemaSlug The schema slug the chain is scoped to.
	 * @param mixed $name Provenance `ApprovalChain` name, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetchLiveApprovalChain(string $schemaSlug, mixed $name): ?array {
		if (is_string($name) === false || $name === '') {
			return null;
		}

		$schema = $this->loadSchema(slug: $schemaSlug);
		if ($schema === null) {
			return null;
		}

		$schemaId = $schema->getId();
		if ($schemaId === null) {
			return null;
		}

		$config = ($schema->getConfiguration() ?? []);
		$chains = ($config[self::APPROVAL_CHAINS_KEY] ?? []);
		$spec = null;
		if (is_array($chains) === true) {
			$spec = ($chains[$name] ?? null);
		}

		if (is_array($spec) === false) {
			return null;
		}

		// Reported in the retired row's shape, so every caller and every
		// drift-comparison keeps working: `approvers` is the declared form of
		// what used to be the chain's `steps` column.
		$steps = [];
		$order = 1;
		foreach ((array)($spec['approvers'] ?? []) as $approver) {
			$role = trim((string)($approver['role'] ?? ''));
			if ($role !== '') {
				$steps[] = ['order' => $order, 'role' => $role];
				$order++;
			}
		}

		return [
			'name' => $name,
			'steps' => $steps,
			'enabled' => (bool)($spec['enabled'] ?? true),
		];

	}//end fetchLiveApprovalChain()

	/**
	 * The newest approval sequence opened from a chain's compiled template.
	 *
	 * Resolved through the container behind class_exists rather than
	 * constructor-injected: these are OpenRegister's classes, and a hard
	 * constructor type on a cross-app class is what turned #3302's deletions
	 * into an instance-wide outage (buildiq#651). Reached this way, an instance
	 * without the task engine degrades to "no run recorded".
	 *
	 * The template id is a pure function of schema + chain key, so the same
	 * declaration always resolves to the same template without storing anything.
	 *
	 * @param int    $schemaId  The schema the chain is declared on.
	 * @param string $chainName The chain key.
	 *
	 * @return object|null The newest sequence, or null when none has run.
	 */
	private function newestSequenceFor(int $schemaId, string $chainName): ?object {
		$installer = 'OCA\\OpenRegister\\Service\\ApprovalChainAnnotationInstaller';
		$mapper = 'OCA\\OpenRegister\\Db\\TaskSequenceMapper';
		if ($schemaId === 0 || class_exists($installer) === false || class_exists($mapper) === false) {
			return null;
		}

		try {
			// Narrowed through locals rather than chained off get(): the
			// container returns `object`, so a chained call is unverifiable and
			// phpstan says so. assert() rather than a docblock, matching
			// AutomationApprovalTriggerListener — phpcs forbids an inline doc
			// block in a method body, and assert costs nothing in production.
			$compiler = $this->container->get($installer);
			assert(is_object($compiler) && method_exists($compiler, 'templateIdFor'));
			// Positional, not named: phpstan narrows a container `get()` result to
			// stdClass, and named arguments on a method it cannot resolve are an
			// error rather than a warning.
			$templateId = (string)$compiler->templateIdFor($schemaId, $chainName);

			$sequences = $this->container->get($mapper);
			assert(is_object($sequences) && method_exists($sequences, 'findNewestForTemplate'));
			$found = $sequences->findNewestForTemplate($templateId);

			if (is_object($found) === false) {
				return null;
			}

			return $found;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: AutomationCompilerService could not read the approval state of "' . $chainName . '": ' . $e->getMessage()
			);

			return null;
		}

	}//end newestSequenceFor()

	/**
	 * Load a schema by its numeric id.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return Schema|null The schema, or null when it cannot be loaded.
	 */
	private function loadSchemaById(int $schemaId): ?Schema {
		try {
			return $this->schemaMapper->find($schemaId, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: AutomationCompilerService could not load schema id ' . $schemaId . ': ' . $e->getMessage()
			);
			return null;
		}

	}//end loadSchemaById()

	/**
	 * Persist a schema's configuration, logging rather than throwing.
	 *
	 * A compile runs inside a write, so a failure here must not propagate: the
	 * automation is then simply not armed, instead of the user's save failing.
	 *
	 * @param Schema               $schema The schema to save.
	 * @param array<string, mixed> $config The configuration to write.
	 * @param string               $what   What is being written, for the log line.
	 *
	 * @return void
	 */
	private function saveSchemaConfiguration(Schema $schema, array $config, string $what): void {
		try {
			$schema->setConfiguration($config);
			$this->schemaMapper->update($schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: AutomationCompilerService failed to write ' . $what . ': ' . $e->getMessage()
			);
		}

	}//end saveSchemaConfiguration()

	/**
	 * Load a schema entity by slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return Schema|null
	 */
	private function loadSchema(string $slug): ?Schema {
		try {
			return $this->schemaMapper->find($slug, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: AutomationCompilerService could not load schema "' . $slug . '": ' . $e->getMessage());
			return null;
		}

	}//end loadSchema()

	/**
	 * Load an ApplicationVersion by uuid.
	 *
	 * @param string $uuid The ApplicationVersion uuid.
	 *
	 * @return array<string,mixed>|null
	 */
	private function loadApplicationVersion(string $uuid): ?array {
		if ($uuid === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(id: $uuid, register: self::REGISTER_SLUG, schema: 'applicationVersion');
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: AutomationCompilerService could not load ApplicationVersion "' . $uuid . '": ' . $e->getMessage());
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->normalise(object: $entity);
	}//end loadApplicationVersion()

	/**
	 * Find a single object by schema + filters in the shared register.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOneObject(string $schema, array $filters): ?array {
		try {
			$results = $this->objectService->findAll(
				config: ['filters' => array_merge(['register' => self::REGISTER_SLUG, 'schema' => $schema], $filters), 'limit' => 1]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq: AutomationCompilerService findOneObject failed for schema "' . $schema . '": ' . $e->getMessage());
			return null;
		}

		if ($results === []) {
			return null;
		}

		return $this->normalise(object: $results[0]);
	}//end findOneObject()

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

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normalise()
}//end class
