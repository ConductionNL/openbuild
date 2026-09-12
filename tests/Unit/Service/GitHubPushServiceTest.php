<?php

/**
 * Buildiq GitHubPushService unit tests
 *
 * This file used to lock the OPPOSITE contract: that the PAT was a method-scoped
 * parameter, not stored on `$this`, and absent from log lines. That is good hygiene
 * around a secret the app should never have had. The app no longer has it — every
 * GitHub call goes through OpenRegister's credential broker, which injects the token
 * server-side — so the tests now pin the absence of the PAT surface itself, plus the
 * fail-closed guards.
 *
 * There is deliberately no happy-path test here. `push()` resolves the broker through
 * the injected container; the container mock below resolves nothing, so the service
 * fails closed — which is exactly the behaviour asserted below. The wire surface is covered where it is
 * actually exercisable: against the live broker on the dev instance.
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
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\GitHubPushService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for {@see GitHubPushService} — the no-token contract + fail-closed guards.
 */
final class GitHubPushServiceTest extends TestCase {
	/**
	 * A scratch tree with one file, cleaned up by the caller.
	 *
	 * @return string Absolute path to the tree.
	 */
	private function makeTree(): string {
		$treeDir = sys_get_temp_dir() . '/buildiq-test-tree-' . uniqid();
		mkdir($treeDir, 0o755, true);
		file_put_contents($treeDir . '/README.md', '# hello');

		return $treeDir;
	}//end makeTree()

	/**
	 * Remove a scratch tree.
	 *
	 * @param string $treeDir The tree to remove.
	 *
	 * @return void
	 */
	private function removeTree(string $treeDir): void {
		if (is_file($treeDir . '/README.md') === true) {
			unlink($treeDir . '/README.md');
		}

		if (is_dir($treeDir) === true) {
			rmdir($treeDir);
		}
	}//end removeTree()

	/**
	 * Build the service around a container that resolves nothing.
	 *
	 * @return GitHubPushService
	 */
	private function makeService(): GitHubPushService {
		return new GitHubPushService(new NullLogger(), $this->createMock(ContainerInterface::class));
	}//end makeService()

	/**
	 * The core regression: NO method on this service may take a PAT.
	 *
	 * A `$pat` parameter reappearing anywhere means the app has taken custody of the
	 * user's token again, which is the whole thing this change removes. Asserted over
	 * every method — public and private — so it cannot creep back in via a helper.
	 *
	 * @return void
	 */
	public function testNoMethodAcceptsAToken(): void {
		$reflection = new \ReflectionClass(GitHubPushService::class);

		foreach ($reflection->getMethods() as $method) {
			foreach ($method->getParameters() as $parameter) {
				$name = strtolower($parameter->getName());
				self::assertNotSame(
					'pat',
					$name,
					$method->getName() . '() must NOT take a PAT — GitHub auth belongs to the broker'
				);
				self::assertStringNotContainsString(
					'token',
					$name,
					$method->getName() . '() must NOT take a token — GitHub auth belongs to the broker'
				);
			}
		}
	}//end testNoMethodAcceptsAToken()

	/**
	 * push() names a credential, not a secret.
	 *
	 * @return void
	 */
	public function testPushTakesACredentialIdAndAnActingUser(): void {
		$reflection = new \ReflectionMethod(GitHubPushService::class, 'push');
		$names = array_map(static fn ($p) => $p->getName(), $reflection->getParameters());

		self::assertContains('credentialId', $names, 'push() must take a broker credential UUID');
		self::assertContains(
			'actingUserId',
			$names,
			'push() must take the credential owner — the background job has no session for the broker to read'
		);
	}//end testPushTakesACredentialIdAndAnActingUser()

	/**
	 * The service holds no HTTP client: it makes no outbound call of its own, so
	 * there is no request it could attach an Authorization header to.
	 *
	 * @return void
	 */
	public function testServiceHoldsNoHttpClient(): void {
		$reflection = new \ReflectionClass(GitHubPushService::class);

		foreach ($reflection->getConstructor()->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			self::assertStringNotContainsString(
				'IClientService',
				$type,
				'GitHubPushService must not hold an HTTP client — every call goes through the broker'
			);
		}
	}//end testServiceHoldsNoHttpClient()

	/**
	 * Fail closed when the broker cannot serve the call: no fallback, no push.
	 *
	 * OpenRegister IS on the unit-test autoloader, so `isBrokerAvailable()` is true
	 * here and `push()` gets as far as the first broker call — which the injected
	 * container mock cannot resolve. It must throw rather than degrade
	 * to any token-bearing path. Whether the broker is missing, denies the call, or is
	 * simply unreachable, the outcome has to be the same: no repository is created.
	 *
	 * @return void
	 */
	public function testPushFailsClosedWhenTheBrokerCannotServeTheCall(): void {
		$treeDir = $this->makeTree();
		$service = $this->makeService();

		try {
			$service->push(
				jobUuid: 'job-123',
				treeDir: $treeDir,
				credentialId: 'cred-uuid',
				org: 'acme',
				repo: 'app',
				visibility: 'public'
			);
			self::fail('push() must throw when the broker cannot serve the call.');
		} catch (RuntimeException $e) {
			// Regression for the swallowed-error-detail bug: the injected
			// container mock resolves no live broker, so `brokerCall()` never
			// reaches a genuine
			// 2xx/non-2xx response and falls through to its `HTTP 0` failure
			// shape (verified against the real CI PHPUnit environment, not
			// assumed) — either way, the thrown message must now carry that
			// diagnostic detail, not just the bare "GitHub create-repo failed."
			// string. Assert the detail is present without pinning to which
			// internal branch produced it — that's an implementation detail,
			// not the requirement this test exists to guard.
			self::assertNotSame(
				'GitHub create-repo failed.',
				$e->getMessage(),
				'The exception message must include diagnostic detail, not just the bare fixed string'
			);
			self::assertStringContainsString(
				'GitHub create-repo failed.',
				$e->getMessage(),
				'The original context must still be present'
			);
		} finally {
			$this->removeTree($treeDir);
		}
	}//end testPushFailsClosedWhenTheBrokerCannotServeTheCall()

	/**
	 * The broker-availability check is a real `class_exists` on OpenRegister's broker,
	 * so an instance without OpenRegister cannot reach the push path at all.
	 *
	 * @return void
	 */
	public function testBrokerAvailabilityIsCheckedAgainstOpenRegister(): void {
		$service = $this->makeService();

		self::assertTrue(
			$service->isBrokerAvailable(),
			'OpenRegister is on the test autoloader, so the broker must resolve'
		);
	}//end testBrokerAvailabilityIsCheckedAgainstOpenRegister()

	/**
	 * An empty credential is refused too — a GitHub export cannot proceed
	 * unauthenticated.
	 *
	 * @return void
	 */
	public function testPushRefusesAnEmptyCredential(): void {
		$treeDir = $this->makeTree();
		$service = $this->makeService();

		$this->expectException(RuntimeException::class);

		try {
			$service->push(
				jobUuid: 'job-123',
				treeDir: $treeDir,
				credentialId: '',
				org: 'acme',
				repo: 'app'
			);
		} finally {
			$this->removeTree($treeDir);
		}
	}//end testPushRefusesAnEmptyCredential()

	/**
	 * Regression for the swallowed-error-detail bug: a non-2xx broker response's
	 * status and (scrubbed) body must be assembled into the failure detail that
	 * flows into `postJson()`/`createRepo()`'s thrown message — not discarded.
	 *
	 * Exercised directly against the pulled-out assembly method, decoupled from
	 * the container lookup.
	 *
	 * @return void
	 */
	public function testFailureDetailFromStatusIncludesStatusAndBody(): void {
		$service = $this->makeService();
		$method = new \ReflectionMethod(GitHubPushService::class, 'failureDetailFromStatus');
		$method->setAccessible(true);

		$detail = $method->invoke($service, 422, '{"message":"Validation Failed","errors":[{"code":"custom"}]}');

		self::assertStringContainsString('HTTP 422', $detail, 'The upstream status must be present');
		self::assertStringContainsString('Validation Failed', $detail, 'The upstream body detail must be present');
	}//end testFailureDetailFromStatusIncludesStatusAndBody()

	/**
	 * An empty upstream body must not leave a dangling `: ` in the detail.
	 *
	 * @return void
	 */
	public function testFailureDetailFromStatusOmitsBodySuffixWhenBodyIsEmpty(): void {
		$service = $this->makeService();
		$method = new \ReflectionMethod(GitHubPushService::class, 'failureDetailFromStatus');
		$method->setAccessible(true);

		$detail = $method->invoke($service, 404, '');

		self::assertSame('HTTP 404', $detail);
	}//end testFailureDetailFromStatusOmitsBodySuffixWhenBodyIsEmpty()

	/**
	 * A GitHub error body larger than the cap must be truncated, so an
	 * oversized upstream body cannot bloat `errorMessage`.
	 *
	 * @return void
	 */
	public function testFailureDetailFromStatusTruncatesAnOversizedBody(): void {
		$service = $this->makeService();
		$method = new \ReflectionMethod(GitHubPushService::class, 'failureDetailFromStatus');
		$method->setAccessible(true);

		$detail = $method->invoke($service, 500, str_repeat('x', 1000));

		self::assertLessThan(1000, strlen($detail), 'The detail must be capped, not carry the full 1000-char body');
		self::assertStringEndsWith('…', $detail, 'A truncated body must be marked with an ellipsis');
	}//end testFailureDetailFromStatusTruncatesAnOversizedBody()

	/**
	 * `scrub()` is the redaction the whole fix leans on: verify it actually
	 * strips a GitHub PAT-shaped token out of a failure-detail string before
	 * that string could reach `errorMessage` or a log line.
	 *
	 * @return void
	 */
	public function testScrubRedactsAGitHubPatShapedToken(): void {
		$service = $this->makeService();
		$method = new \ReflectionMethod(GitHubPushService::class, 'scrub');
		$method->setAccessible(true);

		$leaky = 'upstream rejected credential ghp_' . str_repeat('a', 36);
		$scrubbed = $method->invoke($service, $leaky);

		self::assertStringNotContainsString('ghp_', $scrubbed, 'A PAT-shaped token must never survive scrub()');
		self::assertStringContainsString('[redacted-token]', $scrubbed);
	}//end testScrubRedactsAGitHubPatShapedToken()

	/**
	 * `failureSuffix()` is what `postJson()`/`createRepo()` append to their
	 * generic message — pin its exact formatting (a leading `' — '`, nothing
	 * when there is no captured detail).
	 *
	 * @return void
	 */
	public function testFailureSuffixFormatsTheCapturedDetail(): void {
		$service = $this->makeService();

		$detailProperty = new \ReflectionProperty(GitHubPushService::class, 'lastFailureDetail');
		$detailProperty->setAccessible(true);

		$suffixMethod = new \ReflectionMethod(GitHubPushService::class, 'failureSuffix');
		$suffixMethod->setAccessible(true);

		self::assertSame('', $suffixMethod->invoke($service), 'No captured detail must mean no suffix');

		$detailProperty->setValue($service, 'HTTP 403: Resource not accessible by personal access token');
		self::assertSame(
			' — HTTP 403: Resource not accessible by personal access token',
			$suffixMethod->invoke($service)
		);
	}//end testFailureSuffixFormatsTheCapturedDetail()
}//end class
