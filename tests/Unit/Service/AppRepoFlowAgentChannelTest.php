<?php

/**
 * Unit tests for the app-repo-format-flow-agent-export flows/agents channels.
 *
 * The export side is a REUSE test more than a behaviour test: it drives the
 * REAL `FlowAndAgentExportBundler` (mocked only at its own FlowMapper/
 * ObjectService collaborators) so a drift between what that class actually
 * writes and what `AppRepoSerializer` assumes it writes fails here rather than
 * producing a published repository whose `flows/`/`agents/` directories are
 * silently empty. Hand-written fixtures shaped like the bundler's output
 * cannot catch that — see `AppChannelApplierTest`'s own note on the same
 * failure mode for the parser.
 *
 * The import side proves the channels round-trip through `AppRepoParser`
 * unchanged in shape (UUID-keyed, unlike every slug-keyed channel), and that a
 * crafted filename never reaches the parsed payload.
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
 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/github-app-repo-format/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppRepoParser;
use OCA\Buildiq\Service\AppRepoSerializer;
use OCA\Buildiq\Service\FlowAgentChannelCollector;
use OCA\Buildiq\Service\FlowAndAgentExportBundler;
use OCA\Buildiq\Service\TemplateRepoSerializer;
use OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Flows/agents channel contract for app-repo-format-flow-agent-export.
 *
 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/github-app-repo-format/spec.md
 */
class AppRepoFlowAgentChannelTest extends TestCase {

	/**
	 * Build a Flow entity with a given uuid/name.
	 *
	 * @param string $uuid The uuid.
	 * @param string $name The name.
	 *
	 * @return Flow
	 */
	private function flow(string $uuid, string $name): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setName($name);
		$flow->setNodes([['id' => 'start', 'type' => 'openregister.trigger-schedule']]);
		$flow->setEdges([]);

		return $flow;
	}//end flow()

	/**
	 * A serializer wired with a REAL FlowAndAgentExportBundler over mocked
	 * FlowMapper/ObjectService — the reuse under test.
	 *
	 * @param FlowMapper $flowMapper Mocked flow resolver.
	 * @param ObjectService $objectService Mocked agent resolver.
	 *
	 * @return AppRepoSerializer
	 */
	private function serializerWithBundler(FlowMapper $flowMapper, ObjectService $objectService): AppRepoSerializer {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willThrowException(new RuntimeException('no register'));
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		return new AppRepoSerializer(
			$registerMapper,
			$schemaMapper,
			$logger,
			new TemplateRepoSerializer($schemaMapper, $logger),
			new FakeSlugResolver(['integriq']),
			null,
			new FlowAgentChannelCollector(
				new FlowAndAgentExportBundler($flowMapper, $objectService, $this->createMock(IAppManager::class), $logger),
				$logger
			)
		);
	}//end serializerWithBundler()

	/**
	 * A minimal application + version pair.
	 *
	 * @param array<string,mixed> $extra Extra Application fields.
	 *
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}
	 */
	private function app(array $extra = []): array {
		$application = array_merge(
			[
				'slug' => 'hydra-console',
				'name' => 'Hydra Console',
				'description' => 'A demo',
				'appType' => 'virtual',
			],
			$extra
		);

		$version = [
			'semver' => '1.0.0',
			'manifest' => ['version' => '1.0.0', 'pages' => []],
		];

		return [$application, $version];
	}//end app()

	/**
	 * A bound flow, resolved through the REAL bundler, is emitted at
	 * `flows/<uuid>.json` — not the bundler's own `lib/Settings/flows/`
	 * scratch-tree path, proving the adapter actually rewrites the convention.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-app-s-bound-flows-and-agents
	 */
	public function testABoundFlowIsEmittedAtItsChannelPath(): void {
		$uuid = '6b14a1fd-0cab-40c0-a3e7-7fea3be29bdc';

		$flowMapper = $this->createMock(FlowMapper::class);
		$flowMapper->method('findByUuid')->willReturn($this->flow(uuid: $uuid, name: 'Hydra sequencer'));
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		[$application, $version] = $this->app(['flows' => [['flow' => $uuid]]]);

		$files = $this->serializerWithBundler(flowMapper: $flowMapper, objectService: $objectService)
			->serialize(application: $application, version: $version);

		self::assertArrayHasKey('flows/' . $uuid . '.json', $files);
		$decoded = json_decode($files['flows/' . $uuid . '.json'], true);
		self::assertSame($uuid, $decoded['uuid']);
		self::assertSame('Hydra sequencer', $decoded['name']);

		$descriptor = json_decode($files['openbuild-app.json'], true);
		self::assertSame(1, $descriptor['channels']['flows']['declared']);
		self::assertSame(1, $descriptor['channels']['flows']['exported']);
		self::assertSame(0, $descriptor['channels']['flows']['skipped']);

	}//end testABoundFlowIsEmittedAtItsChannelPath()

	/**
	 * Agents pointing at the application (by `applicationSlug`, no binding) are
	 * emitted at `agents/<uuid>.json` and never carry the `@self` OR envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-app-s-bound-flows-and-agents
	 */
	public function testAnAgentIsEmittedAtItsChannelPathWithoutTheOrEnvelope(): void {
		$flowMapper = $this->createMock(FlowMapper::class);
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn(
			[['@self' => ['id' => 'a1a1a1a1-a1a1-4a1a-8a1a-a1a1a1a1a1a1'], 'name' => 'Code reviewer', 'applicationSlug' => 'hydra-console']]
		);

		[$application, $version] = $this->app();

		$files = $this->serializerWithBundler(flowMapper: $flowMapper, objectService: $objectService)
			->serialize(application: $application, version: $version);

		self::assertArrayHasKey('agents/a1a1a1a1-a1a1-4a1a-8a1a-a1a1a1a1a1a1.json', $files);
		$decoded = json_decode($files['agents/a1a1a1a1-a1a1-4a1a-8a1a-a1a1a1a1a1a1.json'], true);
		self::assertArrayNotHasKey('@self', $decoded);
		self::assertSame('Code reviewer', $decoded['name']);

		$descriptor = json_decode($files['openbuild-app.json'], true);
		self::assertSame(1, $descriptor['channels']['agents']);

	}//end testAnAgentIsEmittedAtItsChannelPathWithoutTheOrEnvelope()

	/**
	 * A dangling flow binding is reported in the descriptor's `skipped` count,
	 * not silently dropped — the export-side half of "an unresolvable binding
	 * MUST be reported, not silently dropped".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-app-s-bound-flows-and-agents
	 */
	public function testADanglingFlowBindingIsCountedAsSkippedInTheDescriptor(): void {
		$flowMapper = $this->createMock(FlowMapper::class);
		$flowMapper->method('findByUuid')->willThrowException(new RuntimeException('not found'));
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		[$application, $version] = $this->app(['flows' => [['flow' => '00000000-0000-0000-0000-000000000000']]]);

		$files = $this->serializerWithBundler(flowMapper: $flowMapper, objectService: $objectService)
			->serialize(application: $application, version: $version);

		$descriptor = json_decode($files['openbuild-app.json'], true);
		self::assertSame(1, $descriptor['channels']['flows']['declared']);
		self::assertSame(0, $descriptor['channels']['flows']['exported']);
		self::assertSame(1, $descriptor['channels']['flows']['skipped']);

	}//end testADanglingFlowBindingIsCountedAsSkippedInTheDescriptor()

	/**
	 * Without a bundler injected (the pre-existing construction shape), the
	 * flows/agents channels degrade to empty exactly like the other v2
	 * channels degrade without an ObjectService — never a fatal.
	 *
	 * @return void
	 */
	public function testChannelsDegradeToEmptyWithoutABundlerInjected(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willThrowException(new RuntimeException('no register'));
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$serializer = new AppRepoSerializer(
			$registerMapper,
			$schemaMapper,
			$logger,
			new TemplateRepoSerializer($schemaMapper, $logger),
			new FakeSlugResolver(['integriq'])
		);

		[$application, $version] = $this->app(['flows' => [['flow' => '00000000-0000-0000-0000-000000000000']]]);

		$files = $serializer->serialize(application: $application, version: $version);

		foreach (array_keys($files) as $path) {
			self::assertStringStartsNotWith('flows/', $path);
			self::assertStringStartsNotWith('agents/', $path);
		}

		$descriptor = json_decode($files['openbuild-app.json'], true);
		self::assertSame(0, $descriptor['channels']['flows']['exported']);
		self::assertSame(0, $descriptor['channels']['agents']);

	}//end testChannelsDegradeToEmptyWithoutABundlerInjected()

	/**
	 * The flows/agents channels round-trip through `AppRepoParser`, UUID-keyed
	 * rather than slug-keyed (flows and agents have no slug).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/github-app-repo-format/spec.md#requirement-flow-and-agent-channel-entries-are-uuid-keyed-never-slug-keyed
	 */
	public function testFlowsAndAgentsChannelsRoundTripThroughTheParser(): void {
		$flowUuid = '6b14a1fd-0cab-40c0-a3e7-7fea3be29bdc';
		$agentUuid = '22222222-2222-4222-8222-222222222222';

		$files = [
			'openbuild-app.json' => json_encode(
				[
					'formatVersion' => '2.0',
					'slug' => 'round-trip',
					'name' => 'Round Trip',
					'description' => 'Channels survive',
					'category' => 'general',
					'appType' => 'virtual',
					'version' => '1.0.0',
				]
			),
			'manifest.json' => json_encode(['version' => '1.0.0', 'pages' => []]),
			'flows/' . $flowUuid . '.json' => json_encode(['uuid' => $flowUuid, 'name' => 'Sequencer']),
			'agents/' . $agentUuid . '.json' => json_encode(['name' => 'Reviewer']),
			// Hostile entries: not a UUID, and a traversal attempt.
			'flows/not-a-uuid.json' => json_encode(['uuid' => 'not-a-uuid']),
			'agents/../../evil.json' => json_encode(['name' => 'evil']),
		];

		$parsed = (new AppRepoParser())->parse(files: $files);

		self::assertArrayHasKey($flowUuid, $parsed['channels']['flows']);
		self::assertSame('Sequencer', $parsed['channels']['flows'][$flowUuid]['name']);
		self::assertArrayHasKey($agentUuid, $parsed['channels']['agents']);
		self::assertSame('Reviewer', $parsed['channels']['agents'][$agentUuid]['name']);

		self::assertArrayNotHasKey('not-a-uuid', $parsed['channels']['flows']);
		self::assertCount(1, $parsed['channels']['agents'], 'the traversal entry must be dropped');

	}//end testFlowsAndAgentsChannelsRoundTripThroughTheParser()
}//end class
