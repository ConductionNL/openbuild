<?php

/**
 * Buildiq AppChannelApplier tests
 *
 * These cover the properties that make applying safe rather than merely working:
 * a colliding connector is never overwritten, an absent optional app degrades
 * with a reason instead of vanishing, one failing item does not abort the rest,
 * and a missing credential is surfaced.
 *
 * The collision test asserts `failIfExists: true` reaches OpenRegister, because
 * that argument IS the never-overwrite guarantee. Remove it and this test fails —
 * which is the mutation check the change's tasks require.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AgentChannelProvisioner;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\AppRepoParser;
use OCA\Buildiq\Service\ChannelApplyReport;
use OCA\Buildiq\Service\ConnectorRegisterAvailability;
use OCA\Buildiq\Service\ContainerLocator;
use OCA\Buildiq\Service\DataRegisterProvisioner;
use OCA\Buildiq\Service\FlowChannelProvisioner;
use OCA\Buildiq\Service\SkillChannelDelegate;
use OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the v2 channel applier.
 */
class AppChannelApplierTest extends TestCase {

	/**
	 * The nil UUID — an obvious placeholder, never mistakable for a real id.
	 *
	 * @var string
	 */
	private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

	/**
	 * OR object read/write double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Register mapper double.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * Schema mapper double.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * App manager double (optional-dependency detection).
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Optional cross-app service locator double.
	 *
	 * @var ContainerLocator&MockObject
	 */
	private ContainerLocator&MockObject $locator;

	/**
	 * Flow-creation double — the sanctioned single entry point for flows.
	 *
	 * @var FlowService&MockObject
	 */
	private FlowService&MockObject $flowService;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->appManager = $this->createMock(IAppManager::class);
		// FleetAppId::resolve() asks isInstalled() before isEnabledForUser(),
		// so the id resolves to 'integriq' and the per-test isEnabledForUser
		// stub stays the thing under test.
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->locator = $this->createMock(ContainerLocator::class);
		$this->flowService = $this->createMock(FlowService::class);

	}//end setUp()

	/**
	 * Build the applier under test.
	 *
	 * @return AppChannelApplier
	 */
	private function applier(): AppChannelApplier {
		return new AppChannelApplier(
			$this->objectService,
			// A MIGRATED instance, which is what every existing test in this file
			// assumes without ever having said so. `ConnectorRegisterResolutionTest`
			// is where the migrated and unmigrated cases are told apart.
			new ConnectorRegisterAvailability(
				new FakeSlugResolver(['integriq']),
				$this->appManager,
				$this->createMock(LoggerInterface::class)
			),
			// A REAL provisioner over mocked mappers: its declareChannel call is
			// what keeps the dataRegisters channel present in every report, so a
			// mock here would quietly remove an assertion this file depends on.
			new DataRegisterProvisioner(
				$this->registerMapper,
				$this->schemaMapper,
				$this->createMock(LoggerInterface::class)
			),
			// A REAL delegate over the mocked app-manager and locator: the skills
			// channel's degradation and count-adoption behaviour is exactly what
			// several tests in this file assert, so a mock would gut them.
			new SkillChannelDelegate(
				$this->appManager,
				$this->locator,
				$this->createMock(LoggerInterface::class)
			),
			// A REAL provisioner over a mocked FlowService and the shared
			// mocked objectService: the flows channel's declared-count and
			// degradation behaviour is asserted below, same reasoning as the
			// two providers/delegates above.
			new FlowChannelProvisioner(
				$this->flowService,
				$this->objectService,
				$this->createMock(LoggerInterface::class)
			),
			// A REAL provisioner over the shared mocked objectService: the
			// agents channel's tagging and skip-if-exists behaviour is
			// asserted below, same reasoning as the two providers above.
			new AgentChannelProvisioner(
				$this->objectService,
				$this->createMock(LoggerInterface::class)
			),
			$this->createMock(LoggerInterface::class),
		);

	}//end applier()

	/**
	 * Build a REAL Flow entity with the given uuid.
	 *
	 * `getUuid()`/`setUuid()` resolve through `Entity::__call()` (magic
	 * accessors, no declared method PHPUnit can configure a mock expectation
	 * against — `FlowAndAgentExportBundlerTest` uses the same real-entity
	 * approach for the identical reason), so this stands in for what
	 * `FlowService::save()` would hand back rather than mocking it.
	 *
	 * @param string $uuid The uuid `FlowService::save()` "minted".
	 *
	 * @return Flow
	 */
	private function mockedFlow(string $uuid): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);

		return $flow;
	}//end mockedFlow()

	/**
	 * Build a mocked ObjectEntityInterface wrapping a plain payload.
	 *
	 * @param array<string,mixed> $payload The object payload `getObject()` returns.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function mockedEntity(array $payload): ObjectEntityInterface&MockObject {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($payload);

		return $entity;
	}//end mockedEntity()

	/**
	 * A template declaring one connector of the given kind.
	 *
	 * @param string $kind The connector kind.
	 * @param string $uuid The connector uuid.
	 *
	 * @return array<string,mixed>
	 */
	private function templateWithConnector(string $kind, string $uuid): array {
		return [
			'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
			'channels' => [
				'connectors' => [$kind => ['example' => ['id' => $uuid, 'name' => 'Example']]],
			],
		];
	}//end templateWithConnector()

	/**
	 * The applier reads the channels AppRepoParser actually produces.
	 *
	 * This is the load-bearing test of the file. The channels are nested under
	 * `channels`, and an applier that read them from the top level would find
	 * nothing, report `declared: 0` for everything, and still return success —
	 * the precise do-nothing-and-claim-victory failure this class exists to end.
	 *
	 * Hand-written fixtures cannot catch that: write them in the same shape the
	 * implementation assumes and the two agree with each other while both
	 * disagree with the real producer. So this test drives the REAL parser and
	 * feeds its output straight in, which is the only version of this assertion
	 * that can fail when the shapes drift apart.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-every-install-path-applies-the-v2-channels
	 */
	public function testAppliesTheChannelShapeTheParserActuallyProduces(): void {
		$files = [
			'openbuild-app.json' => json_encode(
				[
					'formatVersion' => '2.0',
					'appType' => 'virtual',
					'slug' => 'example-app',
					'version' => '1.0.0',
				]
			),
			'manifest.json' => json_encode(['version' => '1.0.0', 'pages' => []]),
			'connectors/source/example.json' => json_encode(['id' => self::NIL_UUID, 'name' => 'Example']),
			'data-registers/example.json' => json_encode(['slug' => 'example', 'title' => 'Example', 'schemas' => []]),
		];

		$template = (new AppRepoParser())->parse(
			files: $files,
			repo: ['owner' => 'ConductionNL', 'name' => 'example-app']
		);

		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->objectService->method('findAll')->willReturn([]);
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('absent'));

		$report = $this->applier()->apply(template: $template);

		// The whole point: NON-ZERO declared counts straight off the real parser.
		self::assertSame(1, $report['channels']['connectors']['declared']);
		self::assertSame(1, $report['channels']['dataRegisters']['declared']);
		self::assertSame(1, $report['channels']['connectors']['created']);

	}//end testAppliesTheChannelShapeTheParserActuallyProduces()

	/**
	 * A v1 template installs unchanged and reports zero declared everywhere.
	 *
	 * @return void
	 */
	public function testV1TemplateAppliesNothingAndBalances(): void {
		$this->objectService->expects(self::never())->method('saveObject');

		$report = $this->applier()->apply(template: ['manifest' => []]);

		foreach (['dataRegisters', 'connectors', 'automations', 'skills'] as $channel) {
			self::assertSame(0, $report['channels'][$channel]['declared']);
		}

	}//end testV1TemplateAppliesNothingAndBalances()

	/**
	 * A connector is written at its PUBLISHED uuid, and with failIfExists set.
	 *
	 * The `failIfExists: true` assertion is deliberate: that argument is the
	 * never-overwrite guarantee, so removing it must turn this test red.
	 *
	 * @return void
	 */
	public function testConnectorIsWrittenAtItsPublishedUuidAndNeverOverwrites(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$this->objectService->expects(self::once())
			->method('saveObject')
			// Positional, because that is how the mock reports a named-argument
			// call. The list must name EVERY parameter of
			// ObjectServiceInterface::saveObject — the contract carries a
			// `$_validation` parameter between `$silent` and `$uploadedFiles`
			// that the pre-contract signature did not, and a list one short
			// silently shifts `failIfExists` onto `$currentUser`, so the
			// expectation never matches and the never-overwrite guarantee below
			// stops being asserted.
			->with(
				self::anything(), // object
				self::anything(), // extend
				// The RESOLVED slug, not a literal. This assertion used to read
				// 'openconnector' and passed for as long as the code pinned the
				// same word, which is what an assertion that copies the
				// implementation buys you. The applier is now built with a
				// migrated instance, so this is the one that reddens if the
				// resolution is dropped.
				'integriq',       // register
				'source',         // schema
				self::NIL_UUID,   // uuid
				false,            // _rbac
				false,            // _multitenancy
				false,            // silent
				true,             // _validation (contract default)
				null,             // uploadedFiles
				null,             // currentUser
				true              // failIfExists — the never-overwrite guarantee
			);

		$report = $this->applier()->apply(
			template: $this->templateWithConnector(kind: 'source', uuid: self::NIL_UUID)
		);

		self::assertSame(1, $report['channels']['connectors']['created']);

	}//end testConnectorIsWrittenAtItsPublishedUuidAndNeverOverwrites()

	/**
	 * A colliding uuid is reported as skipped, and the run still succeeds.
	 *
	 * @return void
	 */
	public function testCollidingConnectorIsSkippedNotOverwritten(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		// The TYPED exception OpenRegister actually raises for an insert-only
		// conflict. Asserting on this type is what stops an unrelated error from
		// being misread as a benign collision.
		$this->objectService->method('saveObject')
			->willThrowException(new ObjectExistsException('taken'));

		$report = $this->applier()->apply(
			template: $this->templateWithConnector(kind: 'source', uuid: self::NIL_UUID)
		);

		self::assertSame(0, $report['channels']['connectors']['created']);
		self::assertSame(1, $report['channels']['connectors']['skipped']);
		self::assertSame(
			ChannelApplyReport::REASON_EXISTS,
			$report['channels']['connectors']['items'][0]['reason']
		);

	}//end testCollidingConnectorIsSkippedNotOverwritten()

	/**
	 * A genuine failure is recorded as failed, not silently swallowed and not
	 * mistaken for a collision.
	 *
	 * @return void
	 */
	public function testGenuineFailureIsRecordedAsFailed(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->objectService->method('saveObject')
			->willThrowException(new RuntimeException('database is on fire'));

		$report = $this->applier()->apply(
			template: $this->templateWithConnector(kind: 'source', uuid: self::NIL_UUID)
		);

		self::assertSame(1, $report['channels']['connectors']['failed']);
		self::assertSame('failed', $report['channels']['connectors']['items'][0]['outcome']);

	}//end testGenuineFailureIsRecordedAsFailed()

	/**
	 * Connectors degrade when openconnector is absent — declared count preserved.
	 *
	 * @return void
	 */
	public function testConnectorsDegradeWhenOpenConnectorIsAbsent(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->objectService->expects(self::never())->method('saveObject');

		$report = $this->applier()->apply(
			template: $this->templateWithConnector(kind: 'source', uuid: self::NIL_UUID)
		);

		self::assertSame(1, $report['channels']['connectors']['declared']);
		self::assertSame(1, $report['channels']['connectors']['skipped']);
		self::assertSame('openconnector-unavailable', $report['channels']['connectors']['reason']);

	}//end testConnectorsDegradeWhenOpenConnectorIsAbsent()

	/**
	 * The connectors channel applies on an instance that runs integriq.
	 *
	 * THE DEFECT THIS PINS. The guard used to read
	 * `isEnabledForUser('openconnector')`. Integriq is that app renamed, and
	 * an instance on any current release registers only `integriq`, so the
	 * guard answered false and every declared connector was skipped. Nothing
	 * errored: the report said `openconnector-unavailable`, which reads as
	 * "the admin has not installed it" rather than "we asked for a name
	 * nothing answers to".
	 *
	 * @return void
	 */
	public function testConnectorsApplyOnAnInstanceRunningIntegriq(): void {
		$this->appManager = $this->appManagerWithOnly(installed: 'integriq');
		$this->objectService->expects(self::once())->method('saveObject');

		$report = $this->applier()->apply(
			template: $this->templateWithConnector(kind: 'source', uuid: self::NIL_UUID)
		);

		self::assertSame(1, $report['channels']['connectors']['declared']);
		self::assertSame(0, $report['channels']['connectors']['skipped']);
		// `reason` is always present and null by default, so the discriminating
		// assertion is that it is NOT the degradation code.
		self::assertNotSame(
			'openconnector-unavailable',
			$report['channels']['connectors']['reason']
		);

	}//end testConnectorsApplyOnAnInstanceRunningIntegriq()

	/**
	 * The connectors channel still applies on a pre-rename instance.
	 *
	 * The mirror of the case above, and the reason the fix is a resolver
	 * rather than a swapped literal: an instance pinned to the published
	 * `openconnector` release registers only that id, so hardcoding `integriq`
	 * would move the identical silent skip onto it.
	 *
	 * @return void
	 */
	public function testConnectorsApplyOnAPreRenameInstance(): void {
		$this->appManager = $this->appManagerWithOnly(installed: 'openconnector');
		$this->objectService->expects(self::once())->method('saveObject');

		$report = $this->applier()->apply(
			template: $this->templateWithConnector(kind: 'source', uuid: self::NIL_UUID)
		);

		self::assertSame(1, $report['channels']['connectors']['declared']);
		self::assertSame(0, $report['channels']['connectors']['skipped']);
		// `reason` is always present and null by default, so the discriminating
		// assertion is that it is NOT the degradation code.
		self::assertNotSame(
			'openconnector-unavailable',
			$report['channels']['connectors']['reason']
		);

	}//end testConnectorsApplyOnAPreRenameInstance()

	/**
	 * An app manager that knows exactly one installed app id.
	 *
	 * A fresh mock rather than a reconfiguration of `$this->appManager`:
	 * `setUp()` already answers `isInstalled` unconditionally, and PHPUnit
	 * keeps the first stub for a method.
	 *
	 * @param string $installed The one app id this fake instance registered.
	 *
	 * @return IAppManager&MockObject The stubbed app manager.
	 */
	private function appManagerWithOnly(string $installed): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $id): bool => $id === $installed
		);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			static fn (string $id): bool => $id === $installed
		);

		return $appManager;

	}//end appManagerWithOnly()

	/**
	 * Skills degrade when hermiq is absent, keeping the declared count so the
	 * caller can see that 2 skills were declared and none installed.
	 *
	 * @return void
	 */
	public function testAnIdempotentSourceReportingOnlyUnchangedIsNotTreatedAsUnaccountedFor(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		// hermiq's installer is idempotent: a re-install of a bundle already
		// present reports everything as `unchanged`, with `installed` at zero.
		// Reading only `installed` accounts for 0 of 2 declared and reports the
		// whole channel as not-accounted-for — a loud failure on a good run.
		$installer = new class {
			public function installFromRepo(
				string $owner,
				string $repo,
				?string $ref = null,
				?string $actingUserId = null,
				?string $credentialId = null,
			): array {
				return [
					'installed' => 0,
					'updated' => 0,
					'unchanged' => 2,
					'skipped' => 0,
					'failed' => 0,
					'truncated' => false,
				];
			}
		};
		$this->locator->method('get')->willReturn($installer);

		$report = $this->applier()->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => [
					'skills' => [
						'alpha' => ['SKILL.md' => '# alpha'],
						'beta' => ['SKILL.md' => '# beta'],
					],
				],
			]
		);

		$skills = $report['channels']['skills'];
		self::assertSame(2, $skills['declared']);
		self::assertSame(2, $skills['created'], 'unchanged items are present as intended');
		self::assertSame(0, $skills['skipped']);
		self::assertNull($skills['reason'], 'a clean idempotent re-run must not be flagged');
		self::assertSame(2, $skills['sourceCounts']['unchanged']);
		self::assertSame(0, $skills['sourceCounts']['installed']);

	}//end testAnIdempotentSourceReportingOnlyUnchangedIsNotTreatedAsUnaccountedFor()

	/**
	 * The core failure mode this file exists to close: a credential scoped
	 * only to `buildiq` (the obvious, natural scope — the only one any
	 * part of the shop UI hints is needed) attempting an install of a repo
	 * that declares a skills channel. hermiq's bundle installer performs an
	 * INDEPENDENT GitHub fetch under its OWN app identity ("hermiq"), so that
	 * credential is denied by the broker for that one call — but ONLY that
	 * call, since search/fetch on the buildiq side use buildiq's own app
	 * identity and work fine with the exact same credential.
	 *
	 * Before this fix, that denial was only discoverable as the generic
	 * `hermiq-install-failed` after an attempted (and failing) call. This
	 * test asserts the applier now detects the gap BEFOREHAND from the
	 * credential's own `allowedApps` and never even attempts the hermiq
	 * call — proven by asserting the locator that would resolve hermiq's
	 * installer is never invoked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/surface-hermiq-credential-scope-requirement/specs/app-channel-application/spec.md#requirement-skills-are-delegated-to-hermiq-by-repository-coordinates
	 */
	public function testSkillsAreSkippedWithASpecificReasonWhenTheCredentialLacksHermiqScope(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->objectService->method('find')->willReturn(
			$this->mockedEntity(['allowedApps' => ['openbuild']])
		);
		// The whole point: hermiq's installer is never even resolved, let
		// alone called, once the credential is already known to lack scope.
		$this->locator->expects(self::never())->method('get');

		$report = $this->applier()->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => [
					'skills' => [
						'alpha' => ['SKILL.md' => '# alpha'],
						'beta' => ['SKILL.md' => '# beta'],
					],
				],
			],
			credentialId: 'a-github-credential-uuid'
		);

		$skills = $report['channels']['skills'];
		self::assertSame(2, $skills['declared']);
		self::assertSame(2, $skills['skipped']);
		self::assertSame('credential-missing-hermiq-scope', $skills['reason']);

		self::assertCount(1, $report['warnings']);
		self::assertSame('credential-missing-hermiq-scope', $report['warnings'][0]['code']);
		self::assertSame('skills', $report['warnings'][0]['channel']);
		self::assertStringContainsString('hermiq', $report['warnings'][0]['message']);

	}//end testSkillsAreSkippedWithASpecificReasonWhenTheCredentialLacksHermiqScope()

	/**
	 * Regression guard: a credential that DOES carry hermiq's scope is
	 * delegated to exactly as before — the proactive check must never block
	 * a call that would in fact have been admitted.
	 *
	 * @return void
	 */
	public function testSkillsAreDelegatedWhenTheCredentialDoesCarryHermiqScope(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->objectService->method('find')->willReturn(
			$this->mockedEntity(['allowedApps' => ['openbuild', 'hermiq']])
		);

		$installer = new class {
			public function installFromRepo(
				string $owner,
				string $repo,
				?string $ref = null,
				?string $actingUserId = null,
				?string $credentialId = null,
			): array {
				return [
					'installed' => 2,
					'updated' => 0,
					'unchanged' => 0,
					'skipped' => 0,
					'failed' => 0,
					'truncated' => false,
				];
			}
		};
		$this->locator->expects(self::once())->method('get')->willReturn($installer);

		$report = $this->applier()->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => [
					'skills' => [
						'alpha' => ['SKILL.md' => '# alpha'],
						'beta' => ['SKILL.md' => '# beta'],
					],
				],
			],
			credentialId: 'a-github-credential-uuid'
		);

		self::assertSame(2, $report['channels']['skills']['created']);
		self::assertSame([], $report['warnings']);

	}//end testSkillsAreDelegatedWhenTheCredentialDoesCarryHermiqScope()

	/**
	 * An inconclusive credential-scope lookup (credential not found, or the
	 * lookup itself throws) must NOT be treated as a scope gap — same
	 * "absence claim manufactured by a failing lookup is worse than no
	 * claim" reasoning as {@see testInconclusiveCredentialLookupIsNotReportedAsMissing()}.
	 * Behaviour falls through to the delegate exactly as it would with no
	 * check at all.
	 *
	 * @return void
	 */
	public function testInconclusiveScopeLookupFallsThroughToTheDelegate(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->objectService->method('find')->willThrowException(new RuntimeException('broker unavailable'));

		$installer = new class {
			public function installFromRepo(
				string $owner,
				string $repo,
				?string $ref = null,
				?string $actingUserId = null,
				?string $credentialId = null,
			): array {
				return [
					'installed' => 2,
					'updated' => 0,
					'unchanged' => 0,
					'skipped' => 0,
					'failed' => 0,
					'truncated' => false,
				];
			}
		};
		$this->locator->expects(self::once())->method('get')->willReturn($installer);

		$report = $this->applier()->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => [
					'skills' => [
						'alpha' => ['SKILL.md' => '# alpha'],
						'beta' => ['SKILL.md' => '# beta'],
					],
				],
			],
			credentialId: 'a-github-credential-uuid'
		);

		self::assertSame(2, $report['channels']['skills']['created']);
		self::assertSame([], $report['warnings']);

	}//end testInconclusiveScopeLookupFallsThroughToTheDelegate()

	public function testSkillsDegradeWhenHermiqIsAbsent(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->locator->method('get')->willReturn(null);

		$report = $this->applier()->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => [
					'skills' => [
						'alpha' => ['SKILL.md' => '# alpha'],
						'beta' => ['SKILL.md' => '# beta'],
					],
				],
			]
		);

		self::assertSame(2, $report['channels']['skills']['declared']);
		self::assertSame(2, $report['channels']['skills']['skipped']);
		self::assertSame('hermiq-unavailable', $report['channels']['skills']['reason']);

	}//end testSkillsDegradeWhenHermiqIsAbsent()

	/**
	 * An unresolvable credentialRef is surfaced, so "installed" is not confused
	 * with "runnable".
	 *
	 * @return void
	 */
	public function testUnresolvableCredentialIsReported(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->objectService->method('findAll')->willReturn([]);

		$report = $this->applier()->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => [
					'connectors' => [
						'source' => [
							'example' => [
								'id' => self::NIL_UUID,
								'configuration' => [
									'authentication' => [
										'credentialRef' => ['credentialName' => 'PLACEHOLDER_CREDENTIAL'],
									],
								],
							],
						],
					],
				],
			]
		);

		self::assertArrayHasKey('PLACEHOLDER_CREDENTIAL', $report['needsCredentials']);
		self::assertSame(['source/example'], $report['needsCredentials']['PLACEHOLDER_CREDENTIAL']);

	}//end testUnresolvableCredentialIsReported()

	/**
	 * An inconclusive credential lookup must NOT be reported as "missing" — an
	 * absence claim manufactured by a failing lookup is worse than no claim.
	 *
	 * @return void
	 */
	public function testInconclusiveCredentialLookupIsNotReportedAsMissing(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->objectService->method('findAll')
			->willThrowException(new RuntimeException('broker unavailable'));

		$report = $this->applier()->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => [
					'connectors' => [
						'source' => [
							'example' => [
								'id' => self::NIL_UUID,
								'credentialRef' => ['credentialName' => 'PLACEHOLDER_CREDENTIAL'],
							],
						],
					],
				],
			]
		);

		self::assertSame([], $report['needsCredentials']);

	}//end testInconclusiveCredentialLookupIsNotReportedAsMissing()

	/**
	 * A template with declared flows/agents but no local application context
	 * degrades both channels with a stated reason instead of guessing or
	 * throwing — there is nothing to rebind flows onto, and nothing to tag an
	 * agent's `applicationSlug` with.
	 *
	 * @return void
	 */
	public function testFlowsAndAgentsDegradeWithoutLocalApplicationContext(): void {
		$this->flowService->expects(self::never())->method('save');
		$this->objectService->expects(self::never())->method('saveObject');

		$report = $this->applier()->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => [
					'flows' => ['11111111-1111-4111-8111-111111111111' => ['name' => 'Sequencer']],
					'agents' => ['22222222-2222-4222-8222-222222222222' => ['name' => 'Reviewer']],
				],
			]
		);

		self::assertSame(1, $report['channels']['flows']['declared']);
		self::assertSame(1, $report['channels']['flows']['skipped']);
		self::assertSame('no-local-application-context', $report['channels']['flows']['reason']);

		self::assertSame(1, $report['channels']['agents']['declared']);
		self::assertSame(1, $report['channels']['agents']['skipped']);
		self::assertSame('no-local-application-context', $report['channels']['agents']['reason']);

	}//end testFlowsAndAgentsDegradeWithoutLocalApplicationContext()

	/**
	 * A published flow is created through `FlowService::save()` — the
	 * sanctioned single entry point, never a raw insert — and the newly minted
	 * local uuid is rebound onto the local application's `flows[]`, carrying
	 * the published uuid forward as `sourceUuid`.
	 *
	 * @return void
	 */
	public function testAFlowIsCreatedAndReboundOntoTheLocalApplication(): void {
		$applicationUuid = '33333333-3333-4333-8333-333333333333';
		$sourceUuid = '11111111-1111-4111-8111-111111111111';
		$mintedUuid = '44444444-4444-4444-8444-444444444444';

		$this->objectService->method('find')->willReturn($this->mockedEntity(['flows' => []]));
		$this->flowService->expects(self::once())->method('save')
			// Both parameters of FlowService::save(array $data, ?string $uuid = null)
			// named explicitly — a create call passes no uuid, and asserting that
			// is the point: seeding a caller-chosen uuid would ask save() to
			// UPDATE, which goes through find() and fails for a flow that does
			// not exist yet on this instance.
			->with(self::callback(static fn (array $data): bool => ($data['name'] ?? null) === 'Sequencer'), null)
			->willReturn($this->mockedFlow(uuid: $mintedUuid));

		$this->objectService->expects(self::once())->method('saveObject')
			->with(
				self::callback(
					static function (array $object) use ($mintedUuid, $sourceUuid): bool {
						$bindings = ($object['flows'] ?? []);
						return count($bindings) === 1
							&& $bindings[0]['flow'] === $mintedUuid
							&& $bindings[0]['sourceUuid'] === $sourceUuid;
					}
				),
				self::anything(),
				'buildiq',
				'built-app',
				$applicationUuid,
				false,
				false,
				false,
				true,
				null,
				null,
				false
			);

		$report = $this->applier()->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => ['flows' => [$sourceUuid => ['name' => 'Sequencer']]],
			],
			applicationUuid: $applicationUuid,
			applicationSlug: 'hydra-console'
		);

		self::assertSame(1, $report['channels']['flows']['created']);

	}//end testAFlowIsCreatedAndReboundOntoTheLocalApplication()

	/**
	 * Re-applying the same published repository is idempotent: a binding whose
	 * `sourceUuid` already matches is skipped, and `FlowService::save()` is
	 * never called a second time for it.
	 *
	 * @return void
	 */
	public function testARepeatApplyOfTheSamePublishedFlowIsSkipped(): void {
		$applicationUuid = '33333333-3333-4333-8333-333333333333';
		$sourceUuid = '11111111-1111-4111-8111-111111111111';

		$this->objectService->method('find')->willReturn(
			$this->mockedEntity(['flows' => [['flow' => 'already-local', 'sourceUuid' => $sourceUuid]]])
		);
		$this->flowService->expects(self::never())->method('save');
		$this->objectService->expects(self::never())->method('saveObject');

		$report = $this->applier()->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => ['flows' => [$sourceUuid => ['name' => 'Sequencer']]],
			],
			applicationUuid: $applicationUuid,
			applicationSlug: 'hydra-console'
		);

		self::assertSame(0, $report['channels']['flows']['created']);
		self::assertSame(1, $report['channels']['flows']['skipped']);
		self::assertSame(
			ChannelApplyReport::REASON_EXISTS,
			$report['channels']['flows']['items'][0]['reason']
		);

	}//end testARepeatApplyOfTheSamePublishedFlowIsSkipped()

	/**
	 * A published agent is tagged with the LOCAL application's own slug, never
	 * the slug published in the blob — the same class of bug as carrying a
	 * hybrid app's locked identity fields across a boundary they were not
	 * authored for.
	 *
	 * @return void
	 */
	public function testAgentIsTaggedWithTheLocalApplicationSlugNotTheSourceSlug(): void {
		$uuid = self::NIL_UUID;

		$this->objectService->expects(self::once())->method('saveObject')
			->with(
				self::callback(static fn (array $object): bool => ($object['applicationSlug'] ?? null) === 'local-app'),
				self::anything(),
				'buildiq',
				'buildAgent',
				$uuid,
				false,
				false,
				false,
				true,
				null,
				null,
				true
			);

		$report = $this->applier()->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => [
					'agents' => [$uuid => ['name' => 'Reviewer', 'applicationSlug' => 'source-instance-app']],
				],
			],
			applicationUuid: '33333333-3333-4333-8333-333333333333',
			applicationSlug: 'local-app'
		);

		self::assertSame(1, $report['channels']['agents']['created']);

	}//end testAgentIsTaggedWithTheLocalApplicationSlugNotTheSourceSlug()

	/**
	 * A colliding agent uuid is skipped, never overwritten — same guarantee as
	 * connectors.
	 *
	 * @return void
	 */
	public function testCollidingAgentIsSkippedNotOverwritten(): void {
		$this->objectService->method('saveObject')->willThrowException(new ObjectExistsException('taken'));

		$report = $this->applier()->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => ['agents' => [self::NIL_UUID => ['name' => 'Reviewer']]],
			],
			applicationUuid: '33333333-3333-4333-8333-333333333333',
			applicationSlug: 'local-app'
		);

		self::assertSame(0, $report['channels']['agents']['created']);
		self::assertSame(1, $report['channels']['agents']['skipped']);
		self::assertSame(
			ChannelApplyReport::REASON_EXISTS,
			$report['channels']['agents']['items'][0]['reason']
		);

	}//end testCollidingAgentIsSkippedNotOverwritten()
}//end class
