<?php

/**
 * Unit tests for VirtualAppCredentialRegistrar.
 *
 * Covers the Buildiq-side credential-broker onboarding trigger for a
 * published virtual app: manifest `credentials[]` gating, the `buildiq-{slug}`
 * app-id, guarded (non-rotating) broker app-key registration, delegated
 * per-app Doriath registration, and never-throw degradation when either
 * OpenRegister service is absent or errors.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Credential
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

namespace OCA\Buildiq\Tests\Unit\Service\Credential;

use OCA\Buildiq\Service\Credential\VirtualAppCredentialRegistrar;
use OCA\Buildiq\Service\ManifestResolverService;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Buildiq\Service\Credential\VirtualAppCredentialRegistrar
 */
class VirtualAppCredentialRegistrarTest extends TestCase {
	/**
	 * @var ManifestResolverService&MockObject
	 */
	private $manifestResolver;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Recording fake for OpenRegister's broker app-key service.
	 *
	 * @var object
	 */
	private $tokenFake;

	/**
	 * Recording fake for OpenRegister's per-app Doriath registrar.
	 *
	 * @var object
	 */
	private $registrarFake;

	/**
	 * Set up common fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->manifestResolver = $this->createMock(ManifestResolverService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->tokenFake = new class {
			/** @var bool */
			public $registered = false;
			/** @var array<int,string> */
			public $registerAppCalls = [];
			/** @var bool */
			public $throwOnRegister = false;

			public function isRegistered(string $appId): bool {
				return $this->registered;
			}

			public function registerApp(string $appId): string {
				if ($this->throwOnRegister === true) {
					throw new \RuntimeException('boom');
				}

				$this->registerAppCalls[] = $appId;
				return 'secret';
			}
		};

		$this->registrarFake = new class {
			/** @var array<int,array{0:string,1:?string}> */
			public $calls = [];
			/** @var bool */
			public $throwOnRegister = false;

			public function registerApplication(string $appId, ?string $description = null): void {
				if ($this->throwOnRegister === true) {
					throw new \RuntimeException('boom');
				}

				$this->calls[] = [$appId, $description];
			}
		};
	}//end setUp()

	/**
	 * Build a registrar whose service resolution returns the in-test fakes.
	 *
	 * @param bool $tokenAvailable Whether the broker app-key service resolves.
	 * @param bool $registrarAvailable Whether the Doriath registrar resolves.
	 *
	 * @return VirtualAppCredentialRegistrar
	 */
	private function makeRegistrar(bool $tokenAvailable = true, bool $registrarAvailable = true): VirtualAppCredentialRegistrar {
		$tokenFake = $this->tokenFake;
		$registrarFake = $this->registrarFake;

		$container = $this->createMock(ContainerInterface::class);

		return new class($this->manifestResolver, $this->logger, $container, $tokenFake, $registrarFake, $tokenAvailable, $registrarAvailable) extends VirtualAppCredentialRegistrar {
			public function __construct(
				ManifestResolverService $manifestResolver,
				LoggerInterface $logger,
				ContainerInterface $container,
				private $tokenFake,
				private $registrarFake,
				private bool $tokenAvailable,
				private bool $registrarAvailable,
			) {
				parent::__construct($manifestResolver, $logger, $container);
			}

			protected function resolveService(string $fqcn): ?object {
				if (str_contains($fqcn, 'CredentialAppTokenService') === true) {
					return $this->tokenAvailable ? $this->tokenFake : null;
				}

				if (str_contains($fqcn, 'DoriathApplicationRegistrar') === true) {
					return $this->registrarAvailable ? $this->registrarFake : null;
				}

				return null;
			}
		};
	}//end makeRegistrar()

	/**
	 * A manifest declaring credentials[] onboards the app under `buildiq-{slug}`.
	 *
	 * @return void
	 */
	public function testDeclaredCredentialsOnboardsBothPaths(): void {
		$this->manifestResolver->method('resolve')->willReturn(
			['credentials' => [['provider' => 'github']], 'name' => 'Spectr']
		);

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: $this->createMock(IUser::class));

		$this->assertSame(['openbuild-spectr'], $this->tokenFake->registerAppCalls);
		$this->assertSame([['openbuild-spectr', 'Spectr']], $this->registrarFake->calls);
	}//end testDeclaredCredentialsOnboardsBothPaths()

	/**
	 * A manifest without credentials[] is a no-op on both paths.
	 *
	 * @return void
	 */
	public function testNoCredentialsIsNoOp(): void {
		$this->manifestResolver->method('resolve')->willReturn(['name' => 'Spectr']);

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: null);

		$this->assertSame([], $this->tokenFake->registerAppCalls);
		$this->assertSame([], $this->registrarFake->calls);
	}//end testNoCredentialsIsNoOp()

	/**
	 * An empty credentials[] array is treated as "no credentials".
	 *
	 * @return void
	 */
	public function testEmptyCredentialsArrayIsNoOp(): void {
		$this->manifestResolver->method('resolve')->willReturn(['credentials' => []]);

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: null);

		$this->assertSame([], $this->tokenFake->registerAppCalls);
		$this->assertSame([], $this->registrarFake->calls);
	}//end testEmptyCredentialsArrayIsNoOp()

	/**
	 * A null (unresolvable) manifest never throws and onboards nothing.
	 *
	 * @return void
	 */
	public function testNullManifestIsNoOp(): void {
		$this->manifestResolver->method('resolve')->willReturn(null);

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: null);

		$this->assertSame([], $this->tokenFake->registerAppCalls);
		$this->assertSame([], $this->registrarFake->calls);
	}//end testNullManifestIsNoOp()

	/**
	 * An already-registered broker key is NEVER rotated by a re-publish, but the
	 * (idempotent) Doriath registration still runs.
	 *
	 * @return void
	 */
	public function testExistingBrokerKeyIsNotRotated(): void {
		$this->tokenFake->registered = true;
		$this->manifestResolver->method('resolve')->willReturn(['credentials' => [['provider' => 'github']]]);

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: null);

		$this->assertSame([], $this->tokenFake->registerAppCalls, 'existing signing secret must not be rotated');
		$this->assertSame([['openbuild-spectr', null]], $this->registrarFake->calls);
	}//end testExistingBrokerKeyIsNotRotated()

	/**
	 * Absent OpenRegister services degrade silently (never-throw) and onboard nothing.
	 *
	 * @return void
	 */
	public function testAbsentServicesDegrade(): void {
		$this->manifestResolver->method('resolve')->willReturn(['credentials' => [['provider' => 'github']]]);

		$this->makeRegistrar(tokenAvailable: false, registrarAvailable: false)
			->onPublish(slug: 'spectr', caller: null);

		$this->assertSame([], $this->tokenFake->registerAppCalls);
		$this->assertSame([], $this->registrarFake->calls);
	}//end testAbsentServicesDegrade()

	/**
	 * A failure in one onboarding path never throws and never blocks the other.
	 *
	 * @return void
	 */
	public function testOnePathThrowingDoesNotBlockTheOther(): void {
		$this->tokenFake->throwOnRegister = true;
		$this->manifestResolver->method('resolve')->willReturn(['credentials' => [['provider' => 'github']]]);

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: null);

		// Broker path threw and was swallowed; Doriath path still ran.
		$this->assertSame([['openbuild-spectr', null]], $this->registrarFake->calls);
	}//end testOnePathThrowingDoesNotBlockTheOther()

	/**
	 * An unsafe slug (would form an invalid app id) is skipped, not registered.
	 *
	 * @return void
	 */
	public function testUnsafeSlugIsSkipped(): void {
		$this->manifestResolver->method('resolve')->willReturn(['credentials' => [['provider' => 'github']]]);

		$this->makeRegistrar()->onPublish(slug: 'Bad Slug!', caller: null);

		$this->assertSame([], $this->tokenFake->registerAppCalls);
		$this->assertSame([], $this->registrarFake->calls);
	}//end testUnsafeSlugIsSkipped()
}//end class
