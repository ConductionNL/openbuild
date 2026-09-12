<?php

/**
 * Unit tests for AppRepoSerializer::serializeTemplate (template → repo file map).
 *
 * Covers github-app-sync's template publish: a seeded application-template
 * serialises to the SAME canonical repo layout as a published Application
 * version and round-trips cleanly back through the strict AppRepoParser, so a
 * published template repo is installable. Also covers inline-schema and
 * id-reference companion resolution and byte-stable (deterministic) emission.
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
 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppRepoParser;
use OCA\Buildiq\Service\AppRepoSerializer;
use OCA\Buildiq\Service\TemplateRepoSerializer;
use OCA\Buildiq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AppRepoSerializer::serializeTemplate.
 */
class AppRepoSerializerTemplateTest extends TestCase {
	/**
	 * Mock schema mapper (id-reference resolution).
	 *
	 * @var SchemaMapper
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * The serializer under test.
	 *
	 * @var AppRepoSerializer
	 */
	private AppRepoSerializer $serializer;

	/**
	 * Set up the serializer with mocked OR mappers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->serializer = new AppRepoSerializer(
			$registerMapper,
			$this->schemaMapper,
			$logger,
			new TemplateRepoSerializer($this->schemaMapper, $logger),
			new FakeSlugResolver(['integriq'])
		);
	}//end setUp()

	/**
	 * A seeded fixture template serialises to the canonical layout and
	 * round-trips cleanly through the strict AppRepoParser.
	 *
	 * @return void
	 */
	public function testSeededFixtureRoundTripsThroughParser(): void {
		$template = $this->loadFixture(slug: 'incident-reporter');

		$files = $this->serializer->serializeTemplate(template: $template);

		// Canonical layout: descriptor + manifest + one schema + README.
		$this->assertArrayHasKey('openbuild-app.json', $files);
		$this->assertArrayHasKey('manifest.json', $files);
		$this->assertArrayHasKey('schemas/incident.json', $files);
		$this->assertArrayHasKey('README.md', $files);

		// Byte-stable: every JSON file ends with a trailing newline.
		$this->assertStringEndsWith("\n", $files['openbuild-app.json']);

		// The descriptor stamps appType=virtual and preserves useCase/version.
		$descriptor = json_decode($files['openbuild-app.json'], true);
		$this->assertSame('virtual', $descriptor['appType']);
		$this->assertSame('incident-reporter', $descriptor['slug']);
		$this->assertSame('1.0.0', $descriptor['version']);
		$this->assertSame('Field-work incident reporting', $descriptor['useCase']);

		// Round-trip: the strict parser accepts the emitted repo and yields a
		// template-shaped array matching the source.
		$parser = new AppRepoParser();
		$parsed = $parser->parse(files: $files, repo: ['owner' => 'acme', 'name' => 'openbuild-incident-reporter']);

		$this->assertSame('incident-reporter', $parsed['slug']);
		$this->assertSame('Incident Reporter', $parsed['title']);
		$this->assertSame('field-work', $parsed['category']);
		$this->assertSame('1.0.0', $parsed['version']);
		$this->assertSame('Field-work incident reporting', $parsed['useCase']);
		$this->assertIsArray($parsed['manifest']);
		$this->assertArrayHasKey('pages', $parsed['manifest']);
		$this->assertCount(1, $parsed['companionSchemas']);
		$this->assertSame('incident', $parsed['companionSchemas'][0]['slug']);
	}//end testSeededFixtureRoundTripsThroughParser()

	/**
	 * Serialisation is deterministic — re-serialising the same template yields
	 * a byte-identical file map (stable diffs for the blob-by-blob tree push).
	 *
	 * @return void
	 */
	public function testSerialisationIsDeterministic(): void {
		$template = $this->loadFixture(slug: 'permit-tracker');

		$first = $this->serializer->serializeTemplate(template: $template);
		$second = $this->serializer->serializeTemplate(template: $template);

		$this->assertSame($first, $second);
	}//end testSerialisationIsDeterministic()

	/**
	 * An id-reference companion is resolved through SchemaMapper into the same
	 * canonical schema blob shape as an inline companion.
	 *
	 * @return void
	 */
	public function testIdReferenceCompanionResolvesViaSchemaMapper(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn('widget');
		$schema->method('getTitle')->willReturn('Widget');
		$schema->method('getDescription')->willReturn('A widget');
		$schema->method('getVersion')->willReturn('2.0.0');
		$schema->method('getRequired')->willReturn(['name']);
		$schema->method('getProperties')->willReturn(['name' => ['type' => 'string']]);

		$this->schemaMapper->method('find')->with(42)->willReturn($schema);

		$template = [
			'slug' => 'widget-app',
			'title' => 'Widget App',
			'description' => 'Manages widgets.',
			'category' => 'internal-operations',
			'version' => '1.0.0',
			'manifest' => ['version' => '1.0.0', 'pages' => []],
			'companionSchemas' => [42],
		];

		$files = $this->serializer->serializeTemplate(template: $template);

		$this->assertArrayHasKey('schemas/widget.json', $files);
		$blob = json_decode($files['schemas/widget.json'], true);
		$this->assertSame('widget', $blob['slug']);
		$this->assertSame('2.0.0', $blob['version']);
		$this->assertSame('object', $blob['type']);
		$this->assertArrayHasKey('name', $blob['properties']);

		// The emitted schema file survives a parser round-trip.
		$parser = new AppRepoParser();
		$parsed = $parser->parse(files: $files);
		$this->assertCount(1, $parsed['companionSchemas']);
		$this->assertSame('widget', $parsed['companionSchemas'][0]['slug']);
	}//end testIdReferenceCompanionResolvesViaSchemaMapper()

	/**
	 * Load and decode a seeded template fixture from lib/Settings/templates.
	 *
	 * @param string $slug The template slug (fixture filename).
	 *
	 * @return array<string,mixed>
	 */
	private function loadFixture(string $slug): array {
		$path = __DIR__ . '/../../../lib/Settings/templates/' . $slug . '.json';
		$raw = file_get_contents($path);
		$this->assertIsString($raw, 'fixture readable: ' . $path);

		$data = json_decode($raw, true);
		$this->assertIsArray($data, 'fixture is valid JSON: ' . $path);

		return $data;
	}//end loadFixture()
}//end class
