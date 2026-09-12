<?php

/**
 * Unit test for the fleet app-id resolver.
 *
 * The point of every case here is that BOTH spellings are wrong on their own.
 * Naming the retired id makes a migrated instance answer false; naming the new
 * one makes an instance still on the published release answer false. Neither
 * throws — `isEnabledForUser()` simply returns false and the integration goes
 * quiet — so a test that only pins one direction cannot tell a working
 * resolver from a hardcoded literal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\Buildiq\Support\FleetAppId;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers id resolution across the fleet rename.
 *
 * @spec exclude Infrastructure utility with no feature requirement of its own.
 */
class FleetAppIdTest extends TestCase {

	/**
	 * An app manager whose only installed apps are the ones named.
	 *
	 * @param list<string> $installed Ids this fake instance registered.
	 *
	 * @return IAppManager The stubbed app manager.
	 */
	private function appManager(array $installed): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $id): bool => in_array($id, $installed, true)
		);
		$appManager->method('isEnabledForUser')->willReturnCallback(
			static fn (string $id): bool => in_array($id, $installed, true)
		);

		return $appManager;

	}//end appManager()

	/**
	 * A migrated instance resolves to the current id.
	 *
	 * @return void
	 */
	public function testResolvesTheCurrentId(): void {
		self::assertSame(
			'integriq',
			FleetAppId::resolve(appManager: $this->appManager(['integriq']), canonical: 'integriq')
		);

	}//end testResolvesTheCurrentId()

	/**
	 * An instance still on the published release resolves to the retired id.
	 *
	 * This is the case a hard swap to the new literal breaks, and it breaks it
	 * silently: the caller reads false as "the app is not installed".
	 *
	 * @return void
	 */
	public function testResolvesThePreRenameId(): void {
		self::assertSame(
			'openconnector',
			FleetAppId::resolve(appManager: $this->appManager(['openconnector']), canonical: 'integriq')
		);

	}//end testResolvesThePreRenameId()

	/**
	 * The newest id wins when an instance somehow carries both.
	 *
	 * @return void
	 */
	public function testPrefersTheNewestId(): void {
		self::assertSame(
			'dossiq',
			FleetAppId::resolve(
				appManager: $this->appManager(['procest', 'dossiq']),
				canonical: 'dossiq'
			)
		);

	}//end testPrefersTheNewestId()

	/**
	 * An app that is genuinely absent resolves to null, under either spelling.
	 *
	 * @return void
	 */
	public function testAbsentAppResolvesToNull(): void {
		self::assertNull(
			FleetAppId::resolve(appManager: $this->appManager(['files']), canonical: 'thematiq')
		);
		self::assertFalse(
			FleetAppId::isEnabledForUser(appManager: $this->appManager(['files']), canonical: 'thematiq')
		);

	}//end testAbsentAppResolvesToNull()

	/**
	 * An id with no rename on record is looked up as itself.
	 *
	 * @return void
	 */
	public function testUnmappedIdIsUsedVerbatim(): void {
		self::assertSame(
			'openregister',
			FleetAppId::resolve(appManager: $this->appManager(['openregister']), canonical: 'openregister')
		);

	}//end testUnmappedIdIsUsedVerbatim()

	/**
	 * The enabled check runs against the id the instance actually registered.
	 *
	 * @return void
	 */
	public function testEnabledCheckUsesTheResolvedId(): void {
		self::assertTrue(
			FleetAppId::isEnabledForUser(
				appManager: $this->appManager(['openconnector']),
				canonical: 'integriq'
			)
		);

	}//end testEnabledCheckUsesTheResolvedId()

	/**
	 * A throwing app manager is a miss for that candidate, not an abort.
	 *
	 * @return void
	 */
	public function testAThrowingCandidateDoesNotStopTheSearch(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static function (string $id): bool {
				if ($id === 'integriq') {
					throw new RuntimeException('app manager blew up');
				}

				return $id === 'openconnector';
			}
		);

		self::assertSame(
			'openconnector',
			FleetAppId::resolve(appManager: $appManager, canonical: 'integriq')
		);

	}//end testAThrowingCandidateDoesNotStopTheSearch()

	/**
	 * Every renamed app lists its current id first and keeps the retired one.
	 *
	 * Order IS the contract: `resolve()` returns the first installed entry, so
	 * a list that lost its newest-first order would prefer the retired id on a
	 * fully migrated instance, and a list that dropped the old entry would
	 * re-break every instance that has not migrated.
	 *
	 * @return void
	 */
	public function testEveryCandidateListIsNewestFirstAndKeepsTheOldId(): void {
		foreach (FleetAppId::CANDIDATES as $canonical => $candidates) {
			self::assertSame($canonical, $candidates[0], $canonical . ' must list its current id first');
			self::assertGreaterThan(1, count($candidates), $canonical . ' must keep its retired id');
		}

	}//end testEveryCandidateListIsNewestFirstAndKeepsTheOldId()

}//end class
