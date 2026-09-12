<?php

/**
 * The connectors channel reads and writes the slug this instance actually carries.
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
 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AgentChannelProvisioner;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\ConnectorRegisterAvailability;
use OCA\Buildiq\Service\ContainerLocator;
use OCA\Buildiq\Service\DataRegisterProvisioner;
use OCA\Buildiq\Service\FlowChannelProvisioner;
use OCA\Buildiq\Service\SkillChannelDelegate;
use OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Migrated and unmigrated instances, told apart.
 *
 * ## Why the migrated case is the only one that matters
 *
 * Reinstating the pinned literal reddens the migrated-instance test below and
 * the static guard in `RegisterSlugPinTest`. It does NOT redden the
 * unmigrated-instance test, because on an unmigrated instance the pinned literal
 * happens to be the right answer. That is why this defect survived: every test
 * anyone had written was, in effect, the unmigrated case.
 *
 * Watched failing, not assumed. With `register: $registerSlug` reverted to
 * `register: self::CONNECTOR_REGISTER` and the constant put back to
 * `'openconnector'`, three assertions reddened and they were the right three:
 *
 *  - `testAMigratedInstanceIsWrittenWithItsNewSlug` — expected `integriq`, got
 *    `openconnector`.
 *  - `AppChannelApplierTest::testConnectorIsWrittenAtItsPublishedUuidAndNeverOverwrites`
 *    — the same mismatch at the never-overwrite assertion.
 *  - `RegisterSlugPinTest` — naming the file, the line and the canonical slug.
 *
 * `testAnUnmigratedInstanceIsWrittenWithItsOldSlug` stayed green under that
 * mutation, exactly as it should.
 *
 * ## Why the app-id check is not enough
 *
 * `applyConnectors()` already asked `FleetAppId::isEnabledForUser(canonical:
 * 'integriq')` before any of this work, and that check was passing on the very
 * instances the write was failing on. The app id and the register slug are moved
 * by two different repair steps and either can run first, so an instance can
 * answer `integriq` to `IAppManager` while its register row still reads
 * `openconnector`. `testTheAppBeingEnabledDoesNotDecideTheRegisterSlug` holds
 * that distinction in place.
 */
class ConnectorRegisterResolutionTest extends TestCase {

	/**
	 * A fixed uuid for the published connector under test.
	 *
	 * @var string
	 */
	private const CONNECTOR_UUID = '00000000-0000-0000-0000-000000000000';

	/**
	 * The register slug written, captured from the ObjectService double.
	 *
	 * @var string|null
	 */
	private ?string $writtenRegister = null;

	/**
	 * How many writes reached the ObjectService.
	 *
	 * @var int
	 */
	private int $writeCount = 0;

	/**
	 * An instance that has run Integriq's rename writes with the new slug.
	 *
	 * @return void
	 */
	public function testAMigratedInstanceIsWrittenWithItsNewSlug(): void {
		$report = $this->applyOneConnector(presentSlugs: ['integriq']);

		$this->assertSame('integriq', $this->writtenRegister);
		$this->assertSame(1, $report['channels']['connectors']['created']);
	}//end testAMigratedInstanceIsWrittenWithItsNewSlug()

	/**
	 * An instance that has not run it writes with the old slug.
	 *
	 * This case passes both before and after the fix. It is here to prove that
	 * the resolution did not simply swap one literal for another, which would
	 * have moved the breakage to the other half of the estate rather than
	 * removing it.
	 *
	 * @return void
	 */
	public function testAnUnmigratedInstanceIsWrittenWithItsOldSlug(): void {
		$report = $this->applyOneConnector(presentSlugs: ['openconnector']);

		$this->assertSame('openconnector', $this->writtenRegister);
		$this->assertSame(1, $report['channels']['connectors']['created']);
	}//end testAnUnmigratedInstanceIsWrittenWithItsOldSlug()

	/**
	 * An instance carrying the register under NEITHER slug writes nothing, and says so.
	 *
	 * The absence has to be visible. Before the resolution this path wrote into
	 * `openconnector` regardless, and OpenRegister answered whatever it answers
	 * for a register that is not there — never an outcome the report could
	 * distinguish from a successful apply of nothing.
	 *
	 * @return void
	 */
	public function testAnInstanceWithoutTheRegisterSkipsTheChannelWithAReason(): void {
		$report = $this->applyOneConnector(presentSlugs: []);

		$this->assertSame(0, $this->writeCount, 'Nothing may be written when the register is absent.');
		$this->assertSame(0, $report['channels']['connectors']['created']);
		$this->assertSame(
			'connector-register-absent',
			($report['channels']['connectors']['reason'] ?? null),
			'The report must name the absent register, not report an empty success.'
		);
	}//end testAnInstanceWithoutTheRegisterSkipsTheChannelWithAReason()

	/**
	 * The app being enabled does not decide which slug the register carries.
	 *
	 * `IAppManager` says yes to `integriq` throughout this file — the double is
	 * configured that way in every case above, including the two where the
	 * register carries the old slug or is absent entirely. If the app-id answer
	 * were being used to pick the slug, the unmigrated case would have written
	 * `integriq` and the absent case would have written at all.
	 *
	 * @return void
	 */
	public function testTheAppBeingEnabledDoesNotDecideTheRegisterSlug(): void {
		$this->applyOneConnector(presentSlugs: ['openconnector']);
		$enabledInstanceWroteOldSlug = $this->writtenRegister;

		$this->writtenRegister = null;
		$this->writeCount = 0;

		$this->applyOneConnector(presentSlugs: []);

		$this->assertSame(
			'openconnector',
			$enabledInstanceWroteOldSlug,
			'An enabled app with an unmigrated register must still be written with the old slug.'
		);
		$this->assertNull(
			$this->writtenRegister,
			'An enabled app with no register at all must not be written with any slug.'
		);
	}//end testTheAppBeingEnabledDoesNotDecideTheRegisterSlug()

	/**
	 * Apply one published connector against an instance carrying the given slugs.
	 *
	 * @param list<string> $presentSlugs The register slugs this instance carries.
	 *
	 * @return array<string, mixed> The apply report.
	 */
	private function applyOneConnector(array $presentSlugs): array {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (...$args) {
				$this->writeCount++;
				// Positional: named arguments reach a mock callback in
				// declaration order, and `register` is the third parameter of
				// ObjectServiceInterface::saveObject.
				$this->writtenRegister = ($args[2] ?? null);
				// A real ObjectEntityInterface, because the contract's return
				// type is not nullable and a null makes the applier record the
				// item as FAILED — which reads as the register being wrong when
				// it is only the double being wrong.
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('isEnabledForUser')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);

		$applier = new AppChannelApplier(
			$objectService,
			new ConnectorRegisterAvailability(
				new FakeSlugResolver($presentSlugs),
				$appManager,
				$logger
			),
			new DataRegisterProvisioner(
				$this->createMock(RegisterMapper::class),
				$this->createMock(SchemaMapper::class),
				$logger
			),
			new SkillChannelDelegate($appManager, $this->createMock(ContainerLocator::class), $logger),
			new FlowChannelProvisioner($this->createMock(FlowService::class), $objectService, $logger),
			new AgentChannelProvisioner($objectService, $logger),
			$logger,
		);

		return $applier->apply(
			template: [
				'templateOrigin' => ['repo' => 'ConductionNL/example-app'],
				'channels' => [
					'connectors' => [
						'source' => ['example' => ['id' => self::CONNECTOR_UUID, 'name' => 'Example']],
					],
				],
			]
		);
	}//end applyOneConnector()
}//end class
