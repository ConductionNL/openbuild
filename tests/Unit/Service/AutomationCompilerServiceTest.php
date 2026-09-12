<?php

/**
 * Unit tests for AutomationCompilerService.
 *
 * Covers REQ-AUTD-004 (one test per matrix ✅ cell, determinism, idempotent
 * recompile), REQ-AUTD-003 (unsupported cells throw naming the combination)
 * and REQ-AUTD-005 (delete removes only provenance-listed artifacts; drift
 * hash mismatch is detected).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-designer/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see AutomationCompilerService}.
 */
final class AutomationCompilerServiceTest extends TestCase {
	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var ApprovalChainAnnotationInstaller&MockObject
	 */
	private ApprovalChainAnnotationInstaller&MockObject $annotationInstaller;

	/**
	 * @var TaskSequenceMapper&MockObject
	 */
	private TaskSequenceMapper&MockObject $sequenceMapper;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * The service under test.
	 *
	 * @var AutomationCompilerService
	 */
	private AutomationCompilerService $compiler;

	/**
	 * Wire the compiler with mocked OR boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->annotationInstaller = $this->createMock(ApprovalChainAnnotationInstaller::class);
		$this->sequenceMapper = $this->createMock(TaskSequenceMapper::class);
		$this->appManager = $this->createMock(IAppManager::class);
		// FleetAppId::resolve() asks isInstalled() first; without this the id
		// never resolves and every enabled check answers false.
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		// The task-engine collaborators arrive through the container at USE
		// time, so the mock answers per class name.
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id): object {
				return match ($id) {
					'OCA\\OpenRegister\\Service\\ApprovalChainAnnotationInstaller' => $this->annotationInstaller,
					'OCA\\OpenRegister\\Db\\TaskSequenceMapper' => $this->sequenceMapper,
					default => $this->objectService,
				};
			}
		);

		$this->compiler = new AutomationCompilerService(
			$this->container,
			$this->objectService,
			$this->schemaMapper,
			$this->appManager,
			new NullLogger(),
		);

	}//end setUp()

	/**
	 * Build a mock Schema entity carrying the given configuration, capturing
	 * every `setConfiguration()` call into `$captured` (last write wins).
	 *
	 * @param array<string,mixed> $initialConfig Initial `getConfiguration()` return.
	 * @param array<string,mixed> &$captured Reference populated on each setConfiguration() call.
	 *
	 * @return Schema&MockObject
	 */
	private function schemaWithConfig(array $initialConfig, array &$captured): Schema&MockObject {
		$schema = $this->createMock(Schema::class);
		$current = $initialConfig;
		$schema->method('getConfiguration')->willReturnCallback(static function () use (&$current) {
			return $current;
		});
		$schema->method('setConfiguration')->willReturnCallback(static function ($config) use (&$current, &$captured) {
			$current = $config;
			$captured = $config;
		});

		return $schema;
	}//end schemaWithConfig()

	/**
	 * REQ-AUTD-004 scenario 1: event notification compiles to the
	 * notifications dialect.
	 *
	 * @return void
	 */
	public function testObjectCreatedPlusNotificationCompilesToDialectEntry(): void {
		$automation = [
			'id' => 'auto-1',
			'slug' => 'notify-caseworkers',
			'name' => 'Notify case workers',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'condition' => null,
			'actions' => [
				[
					'type' => 'send-notification',
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'object-acl', 'permission' => 'manage']],
					'subject' => ['en' => 'New permit', 'nl' => 'Nieuwe vergunning'],
				],
			],
		];

		$plan = $this->compiler->compile($automation);

		$this->assertSame(
			[
				[
					'schema' => 'permit',
					'key' => 'aut-notify-caseworkers-1',
					'entry' => [
						'trigger' => ['type' => 'created'],
						'enabled' => true,
						'channels' => ['nc-notification'],
						'recipients' => [['kind' => 'object-acl', 'permission' => 'manage']],
						'subject' => ['en' => 'New permit', 'nl' => 'Nieuwe vergunning'],
					],
				],
			],
			$plan['notifications']
		);
		$this->assertSame([], $plan['lifecycleActions']);
		$this->assertSame([], $plan['schedules']);
		$this->assertNull($plan['ruleSet']);
		$this->assertStringStartsWith('sha256:', $plan['hash']);

	}//end testObjectCreatedPlusNotificationCompilesToDialectEntry()

	/**
	 * REQ-AUTD-004 scenario 2: scheduled sync compiles to a schedules entry.
	 *
	 * @return void
	 */
	public function testSchedulePlusRunSynchronizationCompilesToScheduleEntry(): void {
		$automation = [
			'id' => 'auto-2',
			'slug' => 'nightly-sync',
			'name' => 'Nightly sync',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'schedule', 'interval' => 86400],
			'condition' => null,
			'actions' => [['type' => 'run-synchronization', 'synchronizationId' => 'sync-1']],
		];

		$plan = $this->compiler->compile($automation);

		$this->assertSame(
			[
				[
					'id' => 'aut-nightly-sync-1',
					'enabled' => true,
					'action' => 'openconnector:synchronization',
					'arguments' => ['synchronizationId' => 'sync-1'],
					'interval' => 86400,
				],
			],
			$plan['schedules']
		);

	}//end testSchedulePlusRunSynchronizationCompilesToScheduleEntry()

	/**
	 * REQ-AUTD-004 scenario 3: manual automation compiles to a namespaced
	 * rule set + one ConditionActionRule.
	 *
	 * @return void
	 */
	public function testManualPlusConditionPlusObjectOpCompilesToRuleSet(): void {
		$automation = [
			'id' => '11112222-3333-4444-5555-666677778888',
			'slug' => 'flag-large-claims',
			'name' => 'Flag large claims',
			'applicationSlug' => 'claims',
			'versionUuid' => 'version-3',
			'enabled' => true,
			'trigger' => ['type' => 'manual'],
			'condition' => ['type' => 'feel', 'expression' => 'payload.amount > 1000'],
			'actions' => [
				['type' => 'object-op', 'operation' => 'create', 'schema' => 'flag', 'fieldMapping' => ['reason' => 'large-claim']],
			],
		];

		$plan = $this->compiler->compile($automation);

		$this->assertSame(
			[
				'slug' => 'aut-11112222',
				'name' => 'Flag large claims',
				'version' => '1.0.0',
				'status' => 'active',
				'ruleType' => 'condition-action',
				'ownerApp' => 'claims',
			],
			$plan['ruleSet']
		);

		$this->assertSame(
			[
				'ruleSetId' => 'aut-11112222',
				'name' => 'Flag large claims',
				'condition' => 'payload.amount > 1000',
				'actions' => [
					[
						'type' => 'object-op',
						'parameters' => [
							'schema' => 'flag',
							'operation' => 'create',
							'object' => ['reason' => 'large-claim'],
							'register' => 'buildiq',
						],
					],
				],
				'active' => true,
			],
			$plan['conditionActionRule']
		);

	}//end testManualPlusConditionPlusObjectOpCompilesToRuleSet()

	/**
	 * Lifecycle-transition matrix cell: object-op/webhook compile to typed
	 * lifecycle actions tagged with the `aut-<slug>` marker.
	 *
	 * @return void
	 */
	public function testLifecycleTransitionPlusObjectOpCompilesToLifecycleAction(): void {
		$automation = [
			'id' => 'auto-4',
			'slug' => 'archive-related',
			'name' => 'Archive related',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'lifecycle-transition', 'schema' => 'permit', 'transition' => 'activate'],
			'condition' => null,
			'actions' => [
				['type' => 'object-op', 'operation' => 'update', 'schema' => 'audit-log', 'fieldMapping' => ['event' => 'activated']],
			],
		];

		$plan = $this->compiler->compile($automation);

		$this->assertSame(
			[
				[
					'schema' => 'permit',
					'transition' => 'activate',
					'marker' => 'aut-archive-related',
					'action' => [
						'type' => 'related-object-upsert',
						'operation' => 'update',
						'schema' => 'audit-log',
						'fieldMapping' => ['event' => 'activated'],
						'marker' => 'aut-archive-related',
					],
				],
			],
			$plan['lifecycleActions']
		);

	}//end testLifecycleTransitionPlusObjectOpCompilesToLifecycleAction()

	/**
	 * Compilation is deterministic: identical input compiles to an identical
	 * plan (including hash) across repeated calls.
	 *
	 * @return void
	 */
	public function testCompilationIsDeterministic(): void {
		$automation = [
			'id' => 'auto-1',
			'slug' => 'notify-caseworkers',
			'name' => 'Notify case workers',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'condition' => null,
			'actions' => [['type' => 'send-notification', 'subject' => ['en' => 'x']]],
		];

		$planA = $this->compiler->compile($automation);
		$planB = $this->compiler->compile($automation);

		$this->assertSame($planA, $planB);

	}//end testCompilationIsDeterministic()

	/**
	 * REQ-AUTD-003: unsupported action for an event trigger is blocked with
	 * the combination named.
	 *
	 * @return void
	 */
	public function testObjectCreatedPlusWebhookIsBlocked(): void {
		$automation = [
			'id' => 'auto-x',
			'slug' => 'bad',
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'actions' => [['type' => 'webhook', 'url' => 'https://example.test']],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessageMatches('/object-created.*webhook/');
		$this->compiler->compile($automation);

	}//end testObjectCreatedPlusWebhookIsBlocked()

	/**
	 * REQ-AUTD-003: a condition on a schedule trigger is blocked.
	 *
	 * @return void
	 */
	public function testConditionOnScheduleTriggerIsBlocked(): void {
		$automation = [
			'id' => 'auto-x',
			'slug' => 'bad',
			'trigger' => ['type' => 'schedule', 'interval' => 3600],
			'condition' => ['type' => 'feel', 'expression' => 'true'],
			'actions' => [['type' => 'run-synchronization', 'synchronizationId' => 's']],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->compiler->compile($automation);

	}//end testConditionOnScheduleTriggerIsBlocked()

	/**
	 * automation-approval-steps REQ-AUTD-003: approval is blocked on
	 * `manual` and `schedule` triggers (D1 — only event/lifecycle-transition
	 * triggers bind to a concrete object instance).
	 *
	 * @return void
	 */
	public function testApprovalActionIsBlockedOnManualAndScheduleTriggers(): void {
		$manual = [
			'id' => 'auto-x',
			'slug' => 'bad',
			'trigger' => ['type' => 'manual'],
			'actions' => [['type' => 'approval', 'assigneeGroup' => 'reviewers']],
		];

		try {
			$this->compiler->compile($manual);
			$this->fail('Expected UnsupportedAutomationCombinationException for manual + approval.');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertStringContainsString('approval', $e->getMessage());
		}

		$schedule = [
			'id' => 'auto-y',
			'slug' => 'bad2',
			'trigger' => ['type' => 'schedule', 'interval' => 3600],
			'actions' => [['type' => 'approval', 'assigneeGroup' => 'reviewers']],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->compiler->compile($schedule);

	}//end testApprovalActionIsBlockedOnManualAndScheduleTriggers()

	/**
	 * automation-approval-steps REQ-AUTD-004: an approval action on
	 * `object-created` compiles to a planned `ApprovalChain` (one step,
	 * `role` = the assignee group), named `aut-<slug>`.
	 *
	 * @return void
	 */
	public function testObjectCreatedPlusApprovalCompilesToApprovalChainPlan(): void {
		$automation = [
			'id' => 'auto-5',
			'slug' => 'route-permit-application-for-approval',
			'name' => 'Route permit application for approval',
			'applicationSlug' => 'vergunning-app',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'],
			'condition' => null,
			'actions' => [
				['type' => 'approval', 'assigneeGroup' => 'permit-reviewers'],
			],
		];

		$plan = $this->compiler->compile($automation);

		$this->assertSame(
			[
				'name' => 'aut-route-permit-application-for-approval',
				'schema' => 'permit-application',
				'assigneeGroup' => 'permit-reviewers',
				'enabled' => true,
			],
			$plan['approvalChain']
		);
		$this->assertSame([], $plan['notifications']);

	}//end testObjectCreatedPlusApprovalCompilesToApprovalChainPlan()

	/**
	 * automation-approval-steps REQ-AUTD-004: `lifecycle-transition` +
	 * `approval` is also supported (not just plain object events).
	 *
	 * @return void
	 */
	public function testLifecycleTransitionPlusApprovalCompilesToApprovalChainPlan(): void {
		$automation = [
			'id' => 'auto-6',
			'slug' => 'activate-needs-approval',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'lifecycle-transition', 'schema' => 'permit', 'transition' => 'activate'],
			'condition' => null,
			'actions' => [
				['type' => 'approval', 'assigneeGroup' => 'ops'],
			],
		];

		$plan = $this->compiler->compile($automation);

		$this->assertSame(
			['name' => 'aut-activate-needs-approval', 'schema' => 'permit', 'assigneeGroup' => 'ops', 'enabled' => true],
			$plan['approvalChain']
		);

	}//end testLifecycleTransitionPlusApprovalCompilesToApprovalChainPlan()

	/**
	 * automation-approval-steps task 1.2 acceptance: recompiling an
	 * unchanged automation produces a byte-identical `ApprovalChain` upsert
	 * (idempotent) — the mapper is asked to upsert with the SAME payload
	 * both times, and `provenance.approvalChainName` is stable.
	 *
	 * @return void
	 */
	public function testApplyIsIdempotentForApprovalChain(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(42);
		$this->schemaMapper->method('find')->willReturn($schema);

		// The chain is no longer a row: compiling writes the declaration onto the
		// schema's `x-openregister-approval-chains` configuration (openregister
		// #3302), so idempotence is now "the same configuration is written twice".
		$schema->method('getConfiguration')->willReturn([]);

		$captured = [];
		$schema->method('setConfiguration')
			->willReturnCallback(function (array $config) use (&$captured): void {
				$captured[] = ($config['x-openregister-approval-chains'] ?? []);
			});
		$this->schemaMapper->expects($this->exactly(2))->method('update');

		$automation = [
			'id' => 'auto-5',
			'slug' => 'route-permit-application-for-approval',
			'applicationSlug' => 'vergunning-app',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'],
			'condition' => null,
			'actions' => [['type' => 'approval', 'assigneeGroup' => 'permit-reviewers']],
		];

		$plan = $this->compiler->compile($automation);
		$provenanceA = $this->compiler->apply($automation, $plan, []);
		$provenanceB = $this->compiler->apply($automation, $plan, $provenanceA);

		$this->assertSame('aut-route-permit-application-for-approval', $provenanceA['approvalChainName']);
		$this->assertSame($provenanceA['approvalChainName'], $provenanceB['approvalChainName']);
		$this->assertSame($captured[0], $captured[1]);

	}//end testApplyIsIdempotentForApprovalChain()

	/**
	 * automation-approval-steps REQ-AUTD-007: `approvalState()` returns
	 * `pending` for the most-recently-created step on the compiled chain.
	 *
	 * @return void
	 */
	public function testApprovalStateReportsTheNewestSequenceStatus(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(42);
		$schema->method('getConfiguration')->willReturn([
			'x-openregister-approval-chains' => [
				'aut-route-permit-application-for-approval' => [
					'approvers' => [['role' => 'permit-reviewers']],
				],
			],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);

		// Restored by openregister#3360, which added the template-wide finder
		// this aggregate needs — it reports a template's last run, so it has no
		// anchor object to ask about and the per-anchor finders could not serve it.
		$this->annotationInstaller->method('templateIdFor')->willReturn('tpl-permit-7');
		$sequence = new TaskSequence();
		$sequence->setStatus('running');
		$this->sequenceMapper->method('findNewestForTemplate')->with('tpl-permit-7')->willReturn($sequence);

		$automation = ['trigger' => ['type' => 'object-created', 'schema' => 'permit-application']];
		$provenance = ['approvalChainName' => 'aut-route-permit-application-for-approval'];

		self::assertSame('pending', $this->compiler->approvalState($automation, $provenance));

	}//end testApprovalStateReportsTheNewestSequenceStatus()

	/**
	 * A terminated sequence reads as `none`, not `rejected`.
	 *
	 * Terminated means the sequence was closed without anyone deciding. Folding
	 * it onto `rejected` would report an outcome nobody chose, on a panel whose
	 * whole job is to say what happened.
	 *
	 * @return void
	 */
	public function testApprovalStateReportsATerminatedSequenceAsNone(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(42);
		$schema->method('getConfiguration')->willReturn([
			'x-openregister-approval-chains' => [
				'aut-x' => ['approvers' => [['role' => 'reviewers']]],
			],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->annotationInstaller->method('templateIdFor')->willReturn('tpl-x');

		$sequence = new TaskSequence();
		$sequence->setStatus('terminated');
		$this->sequenceMapper->method('findNewestForTemplate')->willReturn($sequence);

		self::assertSame('none', $this->compiler->approvalState(
			['trigger' => ['type' => 'object-created', 'schema' => 'permit-application']],
			['approvalChainName' => 'aut-x']
		));

	}//end testApprovalStateReportsATerminatedSequenceAsNone()

	/**
	 * `approvalState()` returns `none` when no chain was ever compiled.
	 *
	 * @return void
	 */
	public function testApprovalStateReturnsNoneWhenNeverCompiled(): void {
		$this->assertSame('none', $this->compiler->approvalState(['trigger' => ['type' => 'object-created']], []));

	}//end testApprovalStateReturnsNoneWhenNeverCompiled()

	/**
	 * `mapActionToRuleAction()` maps an `approval` action to a typed
	 * `approval` rule action (used by `compileDryRunRule()` AND by
	 * `ApprovalOutcomeListener` to map on-approve/on-reject follow-ups).
	 *
	 * @return void
	 */
	public function testMapActionToRuleActionMapsApproval(): void {
		$mapped = $this->compiler->mapActionToRuleAction(['type' => 'approval', 'assigneeGroup' => 'reviewers']);

		$this->assertSame(
			['type' => 'approval', 'parameters' => ['assigneeGroup' => 'reviewers']],
			$mapped
		);

	}//end testMapActionToRuleActionMapsApproval()

	/**
	 * Documented deviation: manual + run-synchronization is blocked (no
	 * verified OpenConnector "run now" primitive exists — see class docblock).
	 *
	 * @return void
	 */
	public function testManualPlusRunSynchronizationIsBlocked(): void {
		$automation = [
			'id' => 'auto-x',
			'slug' => 'bad',
			'trigger' => ['type' => 'manual'],
			'actions' => [['type' => 'run-synchronization', 'synchronizationId' => 's']],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->compiler->compile($automation);

	}//end testManualPlusRunSynchronizationIsBlocked()

	/**
	 * Delete removes exactly the provenance-listed notification key; a
	 * hand-authored (non-`aut-`) key on the same schema survives.
	 *
	 * @return void
	 */
	public function testRemoveDeletesOnlyProvenanceListedNotificationKey(): void {
		$captured = [];
		$schema = $this->schemaWithConfig(
			[
				'x-openregister-notifications' => [
					'aut-notify-caseworkers-1' => ['trigger' => ['type' => 'created']],
					'hand-authored-alert' => ['trigger' => ['type' => 'created']],
				],
			],
			$captured
		);

		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->expects($this->once())->method('update');

		$automation = ['slug' => 'notify-caseworkers', 'versionUuid' => 'version-1'];
		$provenance = [
			'notificationKeys' => [['schema' => 'permit', 'key' => 'aut-notify-caseworkers-1']],
			'lifecycleActions' => [],
			'scheduleIds' => [],
			'ruleSetSlug' => null,
		];

		$this->compiler->remove($automation, $provenance);

		$this->assertArrayNotHasKey('aut-notify-caseworkers-1', $captured['x-openregister-notifications']);
		$this->assertArrayHasKey('hand-authored-alert', $captured['x-openregister-notifications']);

	}//end testRemoveDeletesOnlyProvenanceListedNotificationKey()

	/**
	 * Drift: a live artifact hash that no longer matches the stamped
	 * `provenance.compiledHash` is reported as drift.
	 *
	 * @return void
	 */
	public function testStatusDetectsDriftOnHashMismatch(): void {
		$captured = [];
		$schema = $this->schemaWithConfig(
			[
				'x-openregister-notifications' => [
					'aut-notify-caseworkers-1' => ['trigger' => ['type' => 'created'], 'enabled' => false],
				],
			],
			$captured
		);

		$this->schemaMapper->method('find')->willReturn($schema);

		$automation = ['slug' => 'notify-caseworkers', 'versionUuid' => 'version-1'];
		$provenance = [
			'notificationKeys' => [['schema' => 'permit', 'key' => 'aut-notify-caseworkers-1']],
			'lifecycleActions' => [],
			'scheduleIds' => [],
			'ruleSetSlug' => null,
			// A hash that does not match the live (hand-edited) artifact above.
			'compiledHash' => 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
		];

		$status = $this->compiler->status($automation, $provenance);

		$this->assertTrue($status['drift']);

	}//end testStatusDetectsDriftOnHashMismatch()

	/**
	 * No drift when nothing was ever compiled (empty provenance).
	 *
	 * @return void
	 */
	public function testStatusNoDriftWhenNeverCompiled(): void {
		$status = $this->compiler->status(['slug' => 'x'], []);
		$this->assertFalse($status['drift']);

	}//end testStatusNoDriftWhenNeverCompiled()

	/**
	 * Idempotent recompile: applying the SAME plan twice against the SAME
	 * prior provenance produces the same resulting provenance (upsert, not
	 * duplicate).
	 *
	 * @return void
	 */
	public function testApplyIsIdempotentOnUnchangedPlan(): void {
		$captured = [];
		$schema = $this->schemaWithConfig([], $captured);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->method('update')->willReturn($schema);

		$automation = [
			'id' => 'auto-1',
			'slug' => 'notify-caseworkers',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'condition' => null,
			'actions' => [['type' => 'send-notification', 'subject' => ['en' => 'x']]],
		];

		$plan = $this->compiler->compile($automation);

		$provenanceA = $this->compiler->apply($automation, $plan, []);
		$provenanceB = $this->compiler->apply($automation, $plan, $provenanceA);

		$this->assertSame($provenanceA['notificationKeys'], $provenanceB['notificationKeys']);
		$this->assertSame($provenanceA['compiledHash'], $provenanceB['compiledHash']);
		$this->assertCount(1, $captured['x-openregister-notifications']);

	}//end testApplyIsIdempotentOnUnchangedPlan()

	/**
	 * automation-document-action REQ-AUTD-004: `generateDocument` is
	 * supported on every event/lifecycle-transition trigger and compiles
	 * (no exception, empty plan side effects — no compile-time upsert).
	 *
	 * @return void
	 */
	public function testGenerateDocumentIsSupportedOnEventAndLifecycleTransitionTriggers(): void {
		foreach (['object-created', 'object-updated', 'object-deleted'] as $triggerType) {
			$automation = [
				'id' => 'auto-' . $triggerType,
				'slug' => 'gen-' . $triggerType,
				'trigger' => ['type' => $triggerType, 'schema' => 'permit'],
				'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
			];

			$plan = $this->compiler->compile($automation);
			$this->assertNull($plan['approvalChain']);
			$this->assertSame([], $plan['lifecycleActions']);
		}

		$lifecycle = [
			'id' => 'auto-lifecycle',
			'slug' => 'gen-lifecycle',
			'trigger' => ['type' => 'lifecycle-transition', 'schema' => 'permit', 'transition' => 'approve'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		$plan = $this->compiler->compile($lifecycle);
		$this->assertSame([], $plan['lifecycleActions']);

	}//end testGenerateDocumentIsSupportedOnEventAndLifecycleTransitionTriggers()

	/**
	 * automation-document-action REQ-AUTD-003: `generateDocument` is
	 * blocked fail-closed on `manual` and `schedule` triggers.
	 *
	 * @return void
	 */
	public function testGenerateDocumentIsBlockedOnManualAndScheduleTriggers(): void {
		$manual = [
			'id' => 'auto-x',
			'slug' => 'bad',
			'trigger' => ['type' => 'manual'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		try {
			$this->compiler->compile($manual);
			$this->fail('Expected UnsupportedAutomationCombinationException for manual + generateDocument.');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertStringContainsString('generateDocument', $e->getMessage());
		}

		$schedule = [
			'id' => 'auto-y',
			'slug' => 'bad2',
			'trigger' => ['type' => 'schedule', 'interval' => 3600],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->compiler->compile($schedule);

	}//end testGenerateDocumentIsBlockedOnManualAndScheduleTriggers()

	/**
	 * automation-document-action task 1.2: a `generateDocument` action
	 * missing `templateId` is rejected at compile time.
	 *
	 * @return void
	 */
	public function testGenerateDocumentMissingTemplateIdIsRejected(): void {
		$automation = [
			'id' => 'auto-1',
			'slug' => 'gen-missing-template',
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'actions' => [['type' => 'generateDocument', 'output' => ['attach']]],
		];

		try {
			$this->compiler->compile($automation);
			$this->fail('Expected UnsupportedAutomationCombinationException for a missing templateId.');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertStringContainsString('templateId', $e->getMessage());
		}

	}//end testGenerateDocumentMissingTemplateIdIsRejected()

	/**
	 * automation-document-action task 1.2 / design.md Decision 3:
	 * `notify`-only (no `attach`/`download-link`) is rejected as incomplete.
	 *
	 * @return void
	 */
	public function testGenerateDocumentNotifyOnlyIsRejected(): void {
		$automation = [
			'id' => 'auto-1',
			'slug' => 'gen-notify-only',
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['notify']]],
		];

		try {
			$this->compiler->compile($automation);
			$this->fail('Expected UnsupportedAutomationCombinationException for notify-only output.');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertStringContainsString('notify', $e->getMessage());
		}

	}//end testGenerateDocumentNotifyOnlyIsRejected()

	/**
	 * automation-document-action task 1.2: an unknown/empty `output` is
	 * rejected.
	 *
	 * @return void
	 */
	public function testGenerateDocumentUnknownOutputIsRejected(): void {
		$automation = [
			'id' => 'auto-1',
			'slug' => 'gen-bad-output',
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['bogus']]],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->compiler->compile($automation);

	}//end testGenerateDocumentUnknownOutputIsRejected()

	/**
	 * automation-document-action task 1.3 / D2 of design.md: missing
	 * Docudesk fails the COMPILE, not the runtime.
	 *
	 * @return void
	 */
	public function testGenerateDocumentMissingDocudeskFailsCompile(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$compiler = new AutomationCompilerService(
			$this->container,
			$this->objectService,
			$this->schemaMapper,
			$this->appManager,
			new NullLogger(),
		);

		$automation = [
			'id' => 'auto-1',
			'slug' => 'gen-no-docudesk',
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		try {
			$compiler->compile($automation);
			$this->fail('Expected UnsupportedAutomationCombinationException naming the missing docudesk dependency.');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertStringContainsString('filinq', $e->getMessage());
		}

	}//end testGenerateDocumentMissingDocudeskFailsCompile()

	/**
	 * `mapActionToRuleAction()` maps a `generateDocument` action to a typed
	 * rule action (dry-run panel traceability).
	 *
	 * @return void
	 */
	public function testMapActionToRuleActionMapsGenerateDocument(): void {
		$mapped = $this->compiler->mapActionToRuleAction(
			['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach', 'notify']]
		);

		$this->assertSame(
			['type' => 'generateDocument', 'parameters' => ['templateId' => 'tpl-1', 'output' => ['attach', 'notify']]],
			$mapped
		);

	}//end testMapActionToRuleActionMapsGenerateDocument()
}//end class
