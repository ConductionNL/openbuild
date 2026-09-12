<?php

/**
 * Buildiq GitHubCatalogService unit tests
 *
 * Regression coverage for a defect found live during
 * app-repo-format-flow-agent-export's round-trip verification: the flows/
 * and agents/ channels are written by `AppRepoSerializer` and understood by
 * `AppRepoParser`, but `fetchChannelFiles()`'s own fetch-side prefix
 * allowlist was never extended to match, so `github/pull` (and the shop
 * install path, which shares this same method) silently never downloaded
 * either channel from GitHub — the parser reported `declared: 0` for both
 * even though the files existed in the published repository. Verified live
 * against a real disposable GitHub repo before AND after the fix; this test
 * pins the fetch-side contract so it cannot regress silently again the way
 * it did once already (the class's own docblock on `fetchRepoFiles()`
 * documents the FIRST time this exact class of bug shipped, for
 * data-registers/connectors/automations/skills).
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

use OCA\Buildiq\Service\GitHubCatalogService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for {@see GitHubCatalogService::fetchRepoFiles()} — the v2 channel fetch allowlist.
 */
final class GitHubCatalogServiceTest extends TestCase {

	/**
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $clientService;

	/**
	 * Build the service under test, with caching disabled (no distributed cache
	 * backend available in the unit environment).
	 *
	 * @return GitHubCatalogService
	 */
	private function makeService(): GitHubCatalogService {
		$this->clientService = $this->createMock(IClientService::class);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		return new GitHubCatalogService(
			clientService: $this->clientService,
			cacheFactory: $cacheFactory,
			logger: new NullLogger(),
			container: $this->createMock(ContainerInterface::class)
		);
	}//end makeService()

	/**
	 * A 200 JSON response, the shape every `anonymousGet()` caller expects back.
	 *
	 * @param array<string,mixed>|array<int,mixed> $decoded The JSON body, pre-decode.
	 * @param int $status HTTP status.
	 *
	 * @return IResponse&MockObject
	 */
	private function jsonResponse(array $decoded, int $status = 200): IResponse&MockObject {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn((string)json_encode($decoded));

		return $response;
	}//end jsonResponse()

	/**
	 * A GitHub Contents-API response body: base64 content + encoding.
	 *
	 * @param string $raw The raw (pre-encode) file contents.
	 *
	 * @return array<string,mixed>
	 */
	private function contentsBody(string $raw): array {
		return ['content' => base64_encode($raw), 'encoding' => 'base64'];
	}//end contentsBody()

	/**
	 * The core regression: `fetchRepoFiles()` must return the flows/ and
	 * agents/ entries a repository actually carries — not just the four
	 * older v2 channels — and must still exclude a path outside every known
	 * channel prefix.
	 *
	 * @return void
	 */
	public function testFetchRepoFilesIncludesFlowsAndAgentsChannels(): void {
		$service = $this->makeService();

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) {
			if (str_contains($url, '/git/trees/') === true) {
				return $this->jsonResponse([
					'tree' => [
						['type' => 'blob', 'path' => 'flows/11111111-1111-1111-1111-111111111111.json'],
						['type' => 'blob', 'path' => 'agents/22222222-2222-2222-2222-222222222222.json'],
						['type' => 'blob', 'path' => 'data-registers/reg.json'],
						['type' => 'blob', 'path' => 'connectors/source/conn.json'],
						['type' => 'blob', 'path' => 'automations/auto.json'],
						['type' => 'blob', 'path' => 'skills/pkg/skill.json'],
						// Outside every known channel prefix — must NOT be fetched.
						['type' => 'blob', 'path' => 'unrelated/should-not-fetch.json'],
						// A directory entry sharing a channel prefix — must be
						// ignored (`type` is `tree`, not `blob`).
						['type' => 'tree', 'path' => 'flows'],
					],
				]);
			}

			if (str_contains($url, '/contents/flows/') === true) {
				return $this->jsonResponse($this->contentsBody('{"uuid":"11111111-1111-1111-1111-111111111111","name":"F"}'));
			}

			if (str_contains($url, '/contents/agents/') === true) {
				return $this->jsonResponse($this->contentsBody('{"applicationSlug":"src","name":"A"}'));
			}

			if (str_contains($url, '/contents/data-registers/') === true
				|| str_contains($url, '/contents/connectors/') === true
				|| str_contains($url, '/contents/automations/') === true
				|| str_contains($url, '/contents/skills/') === true
			) {
				return $this->jsonResponse($this->contentsBody('{}'));
			}

			// openbuild-app.json, manifest.json, contents/schemas — not under
			// test here; answer 404 so the caller degrades to "not found"
			// without needing every root file mocked.
			return $this->jsonResponse(['message' => 'Not Found'], 404);
		});

		$this->clientService->method('newClient')->willReturn($client);

		$files = $service->fetchRepoFiles(
			owner: 'example-owner',
			repo: 'example-repo',
			ref: null,
			actingUserId: null,
			credentialId: null
		);

		$this->assertArrayHasKey('flows/11111111-1111-1111-1111-111111111111.json', $files, 'flows/ channel was not fetched — the exact defect this test guards against.');
		$this->assertArrayHasKey('agents/22222222-2222-2222-2222-222222222222.json', $files, 'agents/ channel was not fetched — the exact defect this test guards against.');
		$this->assertArrayHasKey('data-registers/reg.json', $files);
		$this->assertArrayHasKey('connectors/source/conn.json', $files);
		$this->assertArrayHasKey('automations/auto.json', $files);
		$this->assertArrayHasKey('skills/pkg/skill.json', $files);
		$this->assertArrayNotHasKey('unrelated/should-not-fetch.json', $files);
	}//end testFetchRepoFilesIncludesFlowsAndAgentsChannels()

	/**
	 * A 200 whose body is not the documented search shape is a LOOKUP
	 * FAILURE, not an empty result set.
	 *
	 * Regression: the decode used to fall through to `OUTCOME_OK` with zero
	 * cards whenever `items` was missing or the body would not parse, so a
	 * proxy error page or a truncated response was reported as a successful
	 * search that simply matched nothing. The App store then rendered "No
	 * GitHub apps match your search" and — because `githubUnavailable` was
	 * false — showed neither cards nor its unavailable hint. E2E
	 * REQ-OBTC-006 caught the resulting dead state on buildiq
	 * `development`, 2026-08-23.
	 *
	 * @return void
	 */
	public function testSearchReportsUnreachableWhenTheBodyIsNotTheSearchShape(): void {
		$service = $this->makeService();

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('<html><body>502 Bad Gateway</body></html>');

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);

		$result = $service->search(query: null, actingUserId: 'alice', credentialId: null);

		$this->assertSame(
			GitHubCatalogService::OUTCOME_UNREACHABLE,
			$result['outcome'],
			'a 200 with an unparseable body must not be reported as a successful search — that is the exact defect this test guards against.'
		);
		$this->assertSame([], $result['cards']);
	}//end testSearchReportsUnreachableWhenTheBodyIsNotTheSearchShape()

	/**
	 * The other side of the same line: a well-formed response that genuinely
	 * matched nothing is still `OUTCOME_OK`.
	 *
	 * Without this, the fix above could be "achieved" by calling every empty
	 * result unreachable, which would replace one lie with another.
	 *
	 * @return void
	 */
	public function testSearchReportsOkForAGenuinelyEmptyResultSet(): void {
		$service = $this->makeService();

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->jsonResponse(['items' => []]));
		$this->clientService->method('newClient')->willReturn($client);

		$result = $service->search(query: null, actingUserId: 'alice', credentialId: null);

		$this->assertSame(
			GitHubCatalogService::OUTCOME_OK,
			$result['outcome'],
			'an empty but well-formed result set is a real answer, not a failure.'
		);
		$this->assertSame([], $result['cards']);
	}//end testSearchReportsOkForAGenuinelyEmptyResultSet()

	/**
	 * A repo carrying only the LEGACY discovery topic must still be found.
	 *
	 * The app-id rename (#334) moved the discovery topic to `buildiq-app`
	 * while every published app repo still carried `openbuild-app`. Measured
	 * 2026-08-24: `topic:buildiq-app` matched 0 repositories,
	 * `topic:openbuild-app` matched 5. The store therefore searched for a
	 * topic nothing answered to, got a real empty result, and rendered "no
	 * apps match your search" — which is why e2e REQ-OBTC-006 went red the
	 * moment the rename landed.
	 *
	 * A GitHub topic lives on repositories we do not own, so it only moves
	 * when THOSE repos re-tag. Both topics are accepted until they do.
	 *
	 * @return void
	 */
	public function testSearchFindsARepoCarryingOnlyTheLegacyTopic(): void {
		$service = $this->makeService();

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) {
			// The canonical topic matches nothing, exactly as measured.
			if (str_contains(rawurldecode($url), 'topic:buildiq-app') === true) {
				return $this->jsonResponse(['items' => []]);
			}

			return $this->jsonResponse([
				'items' => [
					[
						'full_name' => 'example-owner/legacy-tagged-app',
						'name' => 'legacy-tagged-app',
						'owner' => ['login' => 'example-owner'],
						'description' => 'Tagged with the pre-rename topic only.',
						'html_url' => 'https://github.com/example-owner/legacy-tagged-app',
						'default_branch' => 'main',
						'stargazers_count' => 3,
						'topics' => ['openbuild-app'],
					],
				],
			]);
		});
		$this->clientService->method('newClient')->willReturn($client);

		$result = $service->search(query: null, actingUserId: 'alice', credentialId: null);

		$this->assertSame(GitHubCatalogService::OUTCOME_OK, $result['outcome']);
		$this->assertNotSame(
			[],
			$result['cards'],
			'a repo tagged with only the legacy topic must still be discoverable — dropping the legacy topic empties the store, which is the exact defect this test guards against.'
		);
	}//end testSearchFindsARepoCarryingOnlyTheLegacyTopic()

	/**
	 * The same repo surfacing under BOTH topics is returned once.
	 *
	 * Two requests are merged, so without de-duplication a repo mid-rename
	 * (carrying old and new topic) would render twice in the store.
	 *
	 * @return void
	 */
	public function testSearchDeduplicatesARepoMatchingBothTopics(): void {
		$service = $this->makeService();

		$item = [
			'full_name' => 'example-owner/dual-tagged-app',
			'name' => 'dual-tagged-app',
			'owner' => ['login' => 'example-owner'],
			'description' => 'Carries both topics during the rename.',
			'html_url' => 'https://github.com/example-owner/dual-tagged-app',
			'default_branch' => 'main',
			'stargazers_count' => 1,
			'topics' => ['buildiq-app', 'openbuild-app'],
		];

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($this->jsonResponse(['items' => [$item]]));
		$this->clientService->method('newClient')->willReturn($client);

		$result = $service->search(query: null, actingUserId: 'alice', credentialId: null);

		$this->assertSame(GitHubCatalogService::OUTCOME_OK, $result['outcome']);
		$this->assertCount(
			1,
			$result['cards'],
			'a repo matching both discovery topics must be returned once, not once per topic.'
		);
	}//end testSearchDeduplicatesARepoMatchingBothTopics()
}//end class
