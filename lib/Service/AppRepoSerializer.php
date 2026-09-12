<?php

/**
 * Buildiq AppRepoSerializer
 *
 * Turns a local Application object + a chosen ApplicationVersion object into the
 * ordered set of GitHub app-repo files (github-app-repo-format REQ-GARF-006): the
 * `openbuild-app.json` descriptor, `manifest.json` (the version's manifest blob
 * verbatim), one `schemas/<slug>.json` per companion schema of the app's per-app
 * register, and an optional `README.md`. Pure transformation — it reads the
 * companion schemas from the app's per-app OpenRegister register but performs NO
 * network I/O and NO OpenRegister writes.
 *
 * Every emitted JSON file is canonicalised (recursively sorted keys, stable
 * indentation, trailing newline) and files are emitted in a deterministic order
 * (descriptor, manifest, `schemas/*` sorted by slug, then README) so that
 * re-serialising an unchanged app yields a byte-identical file set — stable diffs
 * for the blob-by-blob tree push in github-app-sync.
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
 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serialises an Application + ApplicationVersion into the canonical repo layout.
 *
 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
 */
class AppRepoSerializer {
	/**
	 * The layout version stamped on every emitted descriptor.
	 *
	 * 2.0 (app-repo-format-v2) adds the data-registers, connectors, automations
	 * and skills channels. `AppRepoParser` still accepts 1.0 unchanged.
	 */
	public const FORMAT_VERSION = '2.0';

	/**
	 * The OpenRegister register Integriq's configuration objects live in.
	 *
	 * Integriq was re-platformed onto OpenRegister — it has no `lib/Db` and
	 * no `openconnector_*` tables — so its Sources/Mappings/Synchronizations/Jobs
	 * are ordinary OR objects. Reading them here is therefore an OR read, NOT a
	 * cross-app PHP dependency (ADR-022).
	 *
	 * The CANONICAL slug, which is not what the read uses: Integriq's per-instance
	 * repair step renames this register from `openconnector`, so both names are
	 * live across the estate. Resolve it through
	 * {@see RegisterSlugResolverInterface} and read with the answer, because
	 * reading with a slug this instance does not carry returns zero rows, not an
	 * error.
	 *
	 * @var string
	 */
	private const CONNECTOR_REGISTER = 'integriq';

	/**
	 * The connector kinds an application may declare. `endpoint` and `rule` are
	 * deliberately excluded: an endpoint is instance-facing surface, and a rule
	 * belongs to the automations channel.
	 *
	 * @var array<int,string>
	 */
	private const CONNECTOR_KINDS = ['source', 'mapping', 'synchronization', 'job'];

	/**
	 * Maximum entries collected per channel, so one application cannot declare
	 * the whole instance into its repository.
	 *
	 * @var int
	 */
	private const MAX_CHANNEL_ENTRIES = 256;

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Resolves the app's per-app register by slug.
	 * @param SchemaMapper $schemaMapper Resolves companion schema definitions by id.
	 * @param LoggerInterface $logger PSR logger (server-side diagnostics only).
	 * @param TemplateRepoSerializer $templateSerializer Serialises a seeded template into the same repo layout.
	 * @param RegisterSlugResolverInterface $slugResolver Which slug the connector register answers to on THIS
	 *                                                    instance. Required, not nullable: the only fallback a
	 *                                                    null would leave is the literal it replaces.
	 * @param ObjectServiceInterface|null $objectService Reads connector + automation objects (app-repo-format-v2).
	 *                                                   Nullable so the v1 construction shape still works and the
	 *                                                   new channels simply collect nothing when it is absent.
	 * @param FlowAgentChannelCollector|null $flowAgentCollector Resolves an application's bound flows
	 *                                                           and the agents that point at it, adapted into the
	 *                                                           `path => contents` map convention this class uses
	 *                                                           everywhere else. Nullable so the v1 construction shape
	 *                                                           and every existing test still build without it,
	 *                                                           degrading the flows and agents channels to empty exactly
	 *                                                           like `objectService` absent degrades connectors/automations.
	 * @param AppRepoPayloadSafety $payloadSafety Path-safety and secret-redaction primitives, stateless
	 *                                            so a default instance is safe to construct here — every
	 *                                            existing caller and test keeps building this class
	 *                                            without naming it.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
		private readonly TemplateRepoSerializer $templateSerializer,
		private readonly RegisterSlugResolverInterface $slugResolver,
		private readonly ?ObjectServiceInterface $objectService = null,
		private readonly ?FlowAgentChannelCollector $flowAgentCollector = null,
		private readonly AppRepoPayloadSafety $payloadSafety = new AppRepoPayloadSafety(),
	) {
	}//end __construct()

	/**
	 * Serialise an Application + chosen ApplicationVersion into a repo file map.
	 *
	 * @param array<string,mixed> $application The Application object (jsonSerialize shape).
	 * @param array<string,mixed> $version The chosen ApplicationVersion object.
	 *
	 * @return array<string,string> Ordered `path => contents` map (canonical JSON + README).
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function serialize(array $application, array $version): array {
		$slug = (string)($application['slug'] ?? '');
		$manifest = [];
		if (is_array($version['manifest'] ?? null) === true) {
			$manifest = $version['manifest'];
		}

		// App-repo-format-v2 channels. Every collector is TOTAL — a missing or
		// unreadable source yields no entries and a debug log, never an
		// exception, so serialisation never becomes the thing that blocks a
		// publish. The counter-measure against that silently producing an
		// empty artefact is the descriptor's channel counts, below.
		$channels = $this->collectChannels(application: $application, slug: $slug);

		$files = [];
		$files['openbuild-app.json'] = $this->encode(
			data: $this->buildDescriptor(
				application: $application,
				version: $version,
				manifest: $manifest,
				channels: $this->descriptorChannelCounts(channels: $channels)
			)
		);
		$files['manifest.json'] = $this->encode(data: $manifest);

		$this->appendChannelFiles(files: $files, channels: $channels);

		$readme = $this->buildReadme(application: $application);
		if ($readme !== null) {
			$files['README.md'] = $readme;
		}

		return $files;
	}//end serialize()

	/**
	 * Run every channel collector once, so `serialize()` only orchestrates.
	 *
	 * @param array<string,mixed> $application The Application object.
	 * @param string $slug The Application slug.
	 *
	 * @return array<string,mixed> Every collector's raw output, keyed by channel.
	 */
	private function collectChannels(array $application, string $slug): array {
		$companions = $this->collectCompanionSchemas(slug: $slug);
		ksort($companions);

		$dataRegisters = $this->collectDataRegisters(application: $application);
		$connectors = $this->collectConnectors(application: $application);
		$automations = $this->collectAutomations(slug: $slug);
		$flowsAndAgents = $this->collectFlowsAndAgents(application: $application);
		ksort($dataRegisters);
		ksort($automations);

		return [
			'companions' => $companions,
			'dataRegisters' => $dataRegisters,
			'connectors' => $connectors,
			'automations' => $automations,
			'flowsAndAgents' => $flowsAndAgents,
		];
	}//end collectChannels()

	/**
	 * The per-channel entry counts the descriptor records — see
	 * `buildDescriptor()`'s own note on why an empty channel must stay visible.
	 *
	 * @param array<string,mixed> $channels {@see collectChannels()}'s output.
	 *
	 * @return array<string,mixed> The descriptor `channels` block.
	 */
	private function descriptorChannelCounts(array $channels): array {
		$connectors = $channels['connectors'];
		$flowsAndAgents = $channels['flowsAndAgents'];

		return [
			'schemas' => count($channels['companions']),
			'dataRegisters' => count($channels['dataRegisters']),
			'connectors' => [
				'declared' => $connectors['declaredCount'],
				'resolved' => $connectors['resolvedCount'],
				'stripped' => $connectors['strippedCount'],
				'missing' => $connectors['missingCount'],
			],
			'automations' => count($channels['automations']),
			'flows' => [
				'declared' => $flowsAndAgents['declaredFlows'],
				'exported' => count($flowsAndAgents['flows']),
				'skipped' => count($flowsAndAgents['skipped']),
			],
			'agents' => count($flowsAndAgents['agents']),
		];
	}//end descriptorChannelCounts()

	/**
	 * Write every channel's entries into the file map, in the deterministic
	 * order the class docblock promises (a stable diff for the tree push).
	 *
	 * @param array<string,string> $files IN/OUT: the file map.
	 * @param array<string,mixed> $channels {@see collectChannels()}'s output.
	 *
	 * @return void
	 */
	private function appendChannelFiles(array &$files, array $channels): void {
		foreach ($channels['companions'] as $schemaSlug => $blob) {
			$files['schemas/' . $schemaSlug . '.json'] = $this->encode(data: $blob);
		}

		foreach ($channels['dataRegisters'] as $registerSlug => $blob) {
			$files['data-registers/' . $registerSlug . '.json'] = $this->encode(data: $blob);
		}

		$connectorFiles = $channels['connectors']['files'];
		ksort($connectorFiles);
		foreach ($connectorFiles as $path => $blob) {
			$files['connectors/' . $path] = $this->encode(data: $blob);
		}

		foreach ($channels['automations'] as $automationSlug => $blob) {
			$files['automations/' . $automationSlug . '.json'] = $this->encode(data: $blob);
		}

		$flowFiles = $channels['flowsAndAgents']['flows'];
		ksort($flowFiles);
		foreach ($flowFiles as $flowUuid => $blob) {
			$files['flows/' . $flowUuid . '.json'] = $this->encode(data: $blob);
		}

		$agentFiles = $channels['flowsAndAgents']['agents'];
		ksort($agentFiles);
		foreach ($agentFiles as $agentUuid => $blob) {
			$files['agents/' . $agentUuid . '.json'] = $this->encode(data: $blob);
		}
	}//end appendChannelFiles()

	/**
	 * Collect the shared data registers this application binds, as schema
	 * definitions only — never objects (the locked scope is full config
	 * fidelity, no data).
	 *
	 * @param array<string,mixed> $application The Application object.
	 *
	 * @return array<string,array<string,mixed>> Register blobs keyed by register slug.
	 *
	 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-app-s-whole-configuration
	 */
	private function collectDataRegisters(array $application): array {
		$bindings = ($application['dataRegisters'] ?? []);
		if (is_array($bindings) === false) {
			return [];
		}

		$out = [];
		foreach ($bindings as $binding) {
			if (is_array($binding) === false || count($out) >= self::MAX_CHANNEL_ENTRIES) {
				continue;
			}

			$registerSlug = (string)($binding['register'] ?? '');
			if ($this->payloadSafety->isSafeSlug(slug: $registerSlug) === false) {
				$this->logger->warning(
					'Buildiq AppRepoSerializer: rejected unsafe data-register slug.',
					['slug' => $registerSlug]
				);
				continue;
			}

			try {
				$register = $this->registerMapper->find($registerSlug, _multitenancy: false);
			} catch (Throwable $e) {
				$this->logger->debug(
					'Buildiq AppRepoSerializer: bound data register "' . $registerSlug . '" not resolvable: ' . $e->getMessage()
				);
				continue;
			}

			$schemas = [];
			foreach ((array)$register->getSchemas() as $schemaId) {
				try {
					$schema = $this->schemaMapper->find($schemaId, _multitenancy: false);
				} catch (Throwable $e) {
					continue;
				}

				$schemaSlug = $schema->getSlug();
				if ($schemaSlug === '') {
					continue;
				}

				$schemaVersion = (string)$schema->getVersion();
				if ($schemaVersion === '') {
					$schemaVersion = '0.1.0';
				}

				$schemas[$schemaSlug] = [
					'slug' => $schemaSlug,
					'title' => (string)$schema->getTitle(),
					'description' => (string)$schema->getDescription(),
					'version' => $schemaVersion,
					'type' => 'object',
					'required' => array_values((array)$schema->getRequired()),
					'properties' => (array)$schema->getProperties(),
				];
			}//end foreach

			ksort($schemas);

			$out[$registerSlug] = [
				'slug' => $registerSlug,
				'title' => (string)$register->getTitle(),
				'label' => (string)($binding['label'] ?? ''),
				'schemas' => $schemas,
			];
		}//end foreach

		return $out;
	}//end collectDataRegisters()

	/**
	 * Collect the OpenConnector configuration this application EXPLICITLY declares.
	 *
	 * Declared entries are exported as declared. The objects a declared entry
	 * DIRECTLY references (a synchronization's source, mapping and target) are
	 * additionally resolved — ONE level only, never a transitive graph walk —
	 * because a synchronization without its source installs into something that
	 * cannot run. Declared and resolved counts are reported separately so
	 * "explicit" stays honest.
	 *
	 * @param array<string,mixed> $application The Application object.
	 *
	 * @return array{files:array<string,array<string,mixed>>,declaredCount:int,resolvedCount:int,strippedCount:int,missingCount:int}
	 *
	 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-connectors-are-declared-explicitly-never-inferred
	 */
	private function collectConnectors(array $application): array {
		$empty = ['files' => [], 'declaredCount' => 0, 'resolvedCount' => 0, 'strippedCount' => 0, 'missingCount' => 0];
		$bindings = ($application['connectors'] ?? []);
		if (is_array($bindings) === false || $this->objectService === null) {
			return $empty;
		}

		$files = [];
		$declared = 0;
		$resolved = 0;
		$stripped = 0;
		$missing = 0;
		$seen = [];

		foreach ($bindings as $binding) {
			if (is_array($binding) === false || count($files) >= self::MAX_CHANNEL_ENTRIES) {
				continue;
			}

			$kind = (string)($binding['kind'] ?? '');
			$uuid = (string)($binding['uuid'] ?? '');
			if (in_array($kind, self::CONNECTOR_KINDS, true) === false || $this->payloadSafety->isSafeUuid(uuid: $uuid) === false) {
				$this->logger->warning(
					'Buildiq AppRepoSerializer: rejected unsafe connector binding.',
					['kind' => $kind, 'uuid' => $uuid]
				);
				continue;
			}

			$realUuid = null;
			$object = $this->findConnector(kind: $kind, uuid: $uuid, resolvedUuid: $realUuid);
			if ($object === null) {
				// Counted, not silently skipped: "declared 25, missing 25" is a
				// diagnosable artefact; "declared 0" is indistinguishable from an
				// app that declared nothing, which is how a lookup bug hid here
				// once already.
				$missing++;
				continue;
			}

			// Keyed on the object's OWN resolved identity (findConnector()'s
			// $resolvedUuid out-param), never the raw reference string — see
			// exportReferencedConnector(). The check is needed HERE too, not
			// only on the reference path: a synchronization binding processed
			// EARLIER can resolve this exact object first via its slug-shaped
			// sourceId and already have written it under a different filename,
			// and the declared path used to write unconditionally regardless
			// (live-verified: `norway-doffin.json` AND `bc2d32cc-….json` both
			// present for one object before this check existed).
			$identity = (string)($realUuid ?? '');
			if ($identity === '') {
				$identity = $uuid;
			}

			if (isset($seen[$kind . '/' . $identity]) === true) {
				continue;
			}

			$name = $this->payloadSafety->connectorFileName(binding: $binding, object: $object, uuid: $uuid);
			$sanitised = $this->payloadSafety->stripSecrets(data: $object, stripped: $stripped);

			$files[$kind . '/' . $name . '.json'] = $sanitised;
			$seen[$kind . '/' . $identity] = true;
			$declared++;

			// ONE level of dependency resolution, and no further.
			$resolved += $this->collectDirectReferences(
				kind: $kind,
				object: $object,
				files: $files,
				seen: $seen,
				stripped: $stripped
			);
		}//end foreach

		return [
			'files' => $files,
			'declaredCount' => $declared,
			'resolvedCount' => $resolved,
			'strippedCount' => $stripped,
			'missingCount' => $missing,
		];

	}//end collectConnectors()

	/**
	 * Export the objects one declared connector DIRECTLY references — one level,
	 * never a transitive graph walk.
	 *
	 * A synchronization without its source, mapping and target installs into
	 * something that cannot run, so those are pulled in alongside the declared
	 * entry. What they in turn reference is NOT followed — one level is the
	 * contract. `$files`/`$seen`/`$stripped` are by-reference because a resolved
	 * object must count against the SAME MAX_CHANNEL_ENTRIES budget and dedupe
	 * against the SAME declared entries as its caller.
	 *
	 * @param string $kind The declared entry's connector kind.
	 * @param array<string,mixed> $object The declared entry's payload.
	 * @param array<string,array<string,mixed>> $files IN/OUT: the export file map.
	 * @param array<string,bool> $seen IN/OUT: dedupe index, keyed `kind/resolved-identity`.
	 * @param int $stripped IN/OUT: running count of stripped secrets.
	 *
	 * @return int How many referenced objects were newly exported.
	 */
	private function collectDirectReferences(
		string $kind,
		array $object,
		array &$files,
		array &$seen,
		int &$stripped,
	): int {
		$resolved = 0;

		foreach ($this->directReferences(kind: $kind, object: $object) as $refKind => $refUuids) {
			foreach ($refUuids as $refUuid) {
				if (count($files) >= self::MAX_CHANNEL_ENTRIES) {
					continue;
				}

				$exported = $this->exportReferencedConnector(
					refKind: $refKind,
					refUuid: $refUuid,
					files: $files,
					seen: $seen,
					stripped: $stripped
				);
				if ($exported === true) {
					$resolved++;
				}
			}//end foreach
		}//end foreach

		return $resolved;
	}//end collectDirectReferences()

	/**
	 * Resolve, dedupe and export ONE referenced connector object.
	 *
	 * Split out of {@see collectDirectReferences()} so "which references do we
	 * follow" and "is this object safe, real and not already exported" read apart.
	 *
	 * @param string $refKind The referenced object's connector kind.
	 * @param string $refUuid The reference as authored — a UUID *or* a slug.
	 * @param array<string,array<string,mixed>> $files IN/OUT: the export file map.
	 * @param array<string,bool> $seen IN/OUT: dedupe index, keyed `kind/resolved-identity`.
	 * @param int $stripped IN/OUT: running count of stripped secrets.
	 *
	 * @return bool True when this call added a new file to the export.
	 */
	private function exportReferencedConnector(
		string $refKind,
		string $refUuid,
		array &$files,
		array &$seen,
		int &$stripped,
	): bool {
		if ($this->payloadSafety->isSafeUuid(uuid: $refUuid) === false && $this->payloadSafety->isSafeSlug(slug: $refUuid) === false) {
			return false;
		}

		$realRefUuid = null;
		$refObject = $this->findConnector(kind: $refKind, uuid: $refUuid, resolvedUuid: $realRefUuid);
		if ($refObject === null) {
			return false;
		}

		// Keyed on the object's OWN resolved identity, never the raw reference:
		// a synchronization's `sourceId` is commonly a SLUG ("tenderned") while
		// a declared binding's `uuid` is a real UUID, and two spellings of one
		// identifier must dedupe to the SAME entry.
		$refIdentity = (string)($realRefUuid ?? '');
		if ($refIdentity === '') {
			$refIdentity = $refUuid;
		}

		$key = $refKind . '/' . $refIdentity;
		if (isset($seen[$key]) === true) {
			return false;
		}

		$refName = $this->payloadSafety->connectorFileName(binding: [], object: $refObject, uuid: $refUuid);

		$files[$refKind . '/' . $refName . '.json'] = $this->payloadSafety->stripSecrets(data: $refObject, stripped: $stripped);
		$seen[$key] = true;
		return true;
	}//end exportReferencedConnector()

	/**
	 * Resolve one connector object by kind + UUID from the shared `openconnector`
	 * register.
	 *
	 * @param string $kind The connector kind.
	 * @param string $uuid The object UUID (or slug — `find(id:)` accepts either).
	 * @param string|null $resolvedUuid OUT: the object's OWN real UUID, read from
	 *                                  the ObjectEntity, never from its payload —
	 *                                  identity is OpenRegister metadata, not
	 *                                  authored data, so a caller deduping two
	 *                                  spellings of one reference MUST read it
	 *                                  here rather than from the returned array.
	 *
	 * @return array<string,mixed>|null The object payload, or null when absent.
	 */
	private function findConnector(string $kind, string $uuid, ?string &$resolvedUuid = null): ?array {
		$resolvedUuid = null;
		if ($this->objectService === null) {
			return null;
		}

		// Which slug the connector register carries HERE. Branching on the absence
		// is the point of the resolution type: reading with a canonical slug on an
		// instance that has not run Integriq's rename returns zero rows, which is
		// what a register holding nothing returns too.
		$registerSlug = $this->slugResolver->resolve(canonical: self::CONNECTOR_REGISTER);
		if ($registerSlug->isResolved() === false) {
			$this->logger->warning(
				'Buildiq AppRepoSerializer: the connector register is not on this instance under any of its '
				. 'known slugs (' . implode(', ', $registerSlug->candidates) . '), so declared connector "'
				. $kind . '/' . $uuid . '" could not be resolved.'
			);
			return null;
		}

		try {
			// Resolved with find(), NOT findAll(filters: ['uuid' => …]): a uuid is OpenRegister
			// METADATA, not an object property, so a filter on it matches nothing.
			// Resolved by UUID rather than slug because Integriq objects
			// overwhelmingly have no slug (measured live: 0 of 74 jobs, 1 of 291
			// mappings), so a slug lookup would miss most real ingestion.
			$found = $this->objectService->find(
				id: $uuid,
				register: $registerSlug->slug,
				schema: $kind
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq AppRepoSerializer: declared connector "' . $kind . '/' . $uuid . '" could not be resolved: ' . $e->getMessage()
			);
			return null;
		}

		if ($found === null) {
			$this->logger->warning(
				'Buildiq AppRepoSerializer: declared connector "' . $kind . '/' . $uuid . '" does not exist.'
			);
			return null;
		}

		// `find()` answers with an ObjectEntityInterface (ADR-084), whose
		// getObject() is the object payload. The array branch this used to carry
		// was unreachable against that contract — the service never hands back a
		// bare array here — so it is gone rather than left as dead cover.
		$resolvedUuid = (string)$found->getUuid();
		return $found->getObject();
	}//end findConnector()

	/**
	 * The objects a connector directly references — one level, no graph walk.
	 *
	 * @param string $kind The declared entry's kind.
	 * @param array<string,mixed> $object The declared entry's payload.
	 *
	 * @return array<string,array<int,string>> Referenced UUIDs OR slugs keyed by
	 *                                         kind. A synchronization's `sourceId` /
	 *                                         `source_target_mapping` carry whichever
	 *                                         identifier the object was authored with
	 *                                         — measured live, sources are commonly
	 *                                         slug-referenced (`sourceId: "tenderned"`)
	 *                                         — so the caller must resolve either
	 *                                         shape (`find(id:)` accepts both).
	 */
	private function directReferences(string $kind, array $object): array {
		if ($kind !== 'synchronization') {
			return [];
		}

		$refs = ['source' => [], 'mapping' => []];

		foreach (['sourceId' => 'source', 'source_id' => 'source'] as $key => $refKind) {
			$value = (string)($object[$key] ?? '');
			if ($value !== '') {
				$refs[$refKind][] = $value;
			}
		}

		foreach (['sourceTargetMapping', 'source_target_mapping', 'sourceHashMapping', 'source_hash_mapping'] as $key) {
			$value = (string)($object[$key] ?? '');
			if ($value !== '') {
				$refs['mapping'][] = $value;
			}
		}

		return array_map(callback: 'array_unique', array: $refs);
	}//end directReferences()

	/**
	 * Collect the application's automations, selected by `applicationSlug`.
	 *
	 * @param string $slug The Application slug.
	 *
	 * @return array<string,array<string,mixed>> Automation blobs keyed by slug.
	 *
	 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-app-s-whole-configuration
	 */
	private function collectAutomations(string $slug): array {
		if ($slug === '' || $this->objectService === null) {
			return [];
		}

		try {
			$results = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => 'buildiq',
						'schema' => 'automation',
						'applicationSlug' => $slug,
					],
					'limit' => self::MAX_CHANNEL_ENTRIES,
				]
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Buildiq AppRepoSerializer: automations for "' . $slug . '" not resolvable: ' . $e->getMessage()
			);
			return [];
		}

		$out = [];
		foreach ((array)$results as $result) {
			$payload = null;
			if (is_object($result) === true && method_exists($result, 'getObject') === true) {
				$payload = (array)$result->getObject();
			} elseif (is_array($result) === true) {
				$payload = $result;
			}

			if ($payload === null) {
				continue;
			}

			$automationSlug = (string)($payload['slug'] ?? '');
			if ($this->payloadSafety->isSafeSlug(slug: $automationSlug) === false) {
				continue;
			}

			$out[$automationSlug] = $payload;
		}//end foreach

		return $out;
	}//end collectAutomations()

	/**
	 * Collect the application's bound flows and the agents that point at it.
	 *
	 * Delegated to {@see FlowAgentChannelCollector}, which adapts
	 * {@see FlowAndAgentExportBundler} — the same reader `ExportService` uses
	 * for the buildiq-exporter's standalone app scaffold — into the
	 * `path => contents` map convention every collector here returns.
	 *
	 * @param array<string,mixed> $application The Application object.
	 *
	 * @return array{flows:array<string,array<string,mixed>>,agents:array<string,array<string,mixed>>,declaredFlows:int,skipped:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-app-s-bound-flows-and-agents
	 */
	private function collectFlowsAndAgents(array $application): array {
		if ($this->flowAgentCollector === null) {
			return ['flows' => [], 'agents' => [], 'declaredFlows' => 0, 'skipped' => []];
		}

		return $this->flowAgentCollector->collect(application: $application);
	}//end collectFlowsAndAgents()

	/**
	 * Serialise a seeded `application-template` object into the same repo file
	 * map `serialize()` produces, so a published template repo round-trips back
	 * through AppRepoParser identically to a published Application version.
	 *
	 * Delegated to TemplateRepoSerializer (a template carries its own inline
	 * `manifest` and `companionSchemas`, so its serialisation is a distinct
	 * concern from the Application + per-app-register path), keeping the public
	 * seam on this service where callers (GitHubAppSyncService::publishTemplate)
	 * already resolve the serializer.
	 *
	 * @param array<string,mixed> $template The seeded application-template object
	 *                                      (jsonSerialize shape): slug/title/
	 *                                      description/useCase/category/version/
	 *                                      manifest/companionSchemas.
	 *
	 * @return array<string,string> Ordered `path => contents` map (canonical JSON + README).
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	public function serializeTemplate(array $template): array {
		return $this->templateSerializer->serialize(template: $template);
	}//end serializeTemplate()

	/**
	 * Build the `openbuild-app.json` descriptor (REQ-GARF-002, REQ-GARF-009).
	 *
	 * @param array<string,mixed> $application The Application object.
	 * @param array<string,mixed> $version The chosen ApplicationVersion object.
	 * @param array<string,mixed> $manifest The version's manifest blob.
	 * @param array<string,mixed> $channels Per-channel entry counts (app-repo-format-v2).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-a-published-repository-carries-the-app-s-whole-configuration
	 */
	private function buildDescriptor(array $application, array $version, array $manifest, array $channels = []): array {
		$appType = (string)($application['appType'] ?? 'virtual');

		$descriptor = [
			'formatVersion' => self::FORMAT_VERSION,
			'slug' => (string)($application['slug'] ?? ''),
			'name' => (string)($application['name'] ?? ($application['slug'] ?? '')),
			'description' => (string)($application['description'] ?? ''),
			'category' => $this->resolveCategory(application: $application, manifest: $manifest),
			'appType' => $appType,
			'version' => (string)($version['semver'] ?? '0.1.0'),
		];

		$iconRef = $this->iconRef(icon: ($application['icon'] ?? null), fallback: 'img/icon.svg');
		if ($iconRef !== null) {
			$descriptor['icon'] = ['ref' => $iconRef];
		}

		$iconDarkRef = $this->iconRef(icon: ($application['iconDark'] ?? null), fallback: 'img/icon-dark.svg');
		if ($iconDarkRef !== null) {
			$descriptor['iconDark'] = ['ref' => $iconDarkRef];
		}

		if ($appType === 'hybrid' && is_array($application['baseRef'] ?? null) === true) {
			$descriptor['baseRef'] = $application['baseRef'];
		}

		$credentials = $this->deriveCredentials(manifest: $manifest);
		if ($credentials !== []) {
			$descriptor['credentials'] = $credentials;
		}

		// App-repo-format-v2: per-channel counts. Collectors are deliberately total
		// (a missing source yields no entries rather than an error), so without
		// these an app that collected NOTHING would publish and look successful —
		// exactly the green-but-empty artefact this format exists to end. Recording
		// the counts makes an empty export visible in the artefact itself.
		if ($channels !== []) {
			$descriptor['channels'] = $channels;
		}

		return $descriptor;
	}//end buildDescriptor()

	/**
	 * Derive the descriptor `credentials[]` from the manifest's top-level
	 * `credentials[]` (REQ-GARF-009) — a surfaced convenience mirror; the manifest
	 * stays authoritative on parse.
	 *
	 * @param array<string,mixed> $manifest The version's manifest blob.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function deriveCredentials(array $manifest): array {
		$raw = ($manifest['credentials'] ?? null);
		if (is_array($raw) === false) {
			return [];
		}

		$credentials = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false || isset($entry['provider']) === false) {
				continue;
			}

			$scopes = [];
			if (is_array($entry['scopes'] ?? null) === true) {
				$scopes = array_values(array_map(static fn ($scope): string => (string)$scope, $entry['scopes']));
			}

			$credentials[] = [
				'provider' => (string)$entry['provider'],
				'reason' => (string)($entry['reason'] ?? ''),
				'scopes' => $scopes,
			];
		}

		return $credentials;
	}//end deriveCredentials()

	/**
	 * Read the app's per-app register companion schemas, keyed by schema slug.
	 *
	 * Mirrors DataRegisterExportBundler's schema resolution; a register that is
	 * absent (an app never provisioned a per-app register) yields no companions
	 * rather than an error — serialise is total.
	 *
	 * @param string $slug The Application slug (per-app register is `buildiq-{slug}`).
	 *
	 * @return array<string,array<string,mixed>> Schema blobs keyed by slug.
	 *
	 * @spec openspec/changes/github-app-repo-format/specs/github-app-repo-format/spec.md
	 */
	private function collectCompanionSchemas(string $slug): array {
		if ($slug === '') {
			return [];
		}

		try {
			$register = $this->registerMapper->find('openbuild-' . $slug, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Buildiq AppRepoSerializer: no per-app register for "' . $slug . '": ' . $e->getMessage()
			);
			return [];
		}

		$schemas = [];
		foreach ((array)$register->getSchemas() as $schemaId) {
			try {
				$schema = $this->schemaMapper->find($schemaId, _multitenancy: false);
			} catch (Throwable $e) {
				$this->logger->debug(
					'Buildiq AppRepoSerializer: could not resolve schema ' . ((string)$schemaId) . ': ' . $e->getMessage()
				);
				continue;
			}

			$schemaSlug = $schema->getSlug();
			if ($schemaSlug === '') {
				continue;
			}

			$version = (string)$schema->getVersion();
			if ($version === '') {
				$version = '0.1.0';
			}

			$schemas[$schemaSlug] = [
				'slug' => $schemaSlug,
				'title' => (string)$schema->getTitle(),
				'description' => (string)$schema->getDescription(),
				'version' => $version,
				'type' => 'object',
				'required' => array_values((array)$schema->getRequired()),
				'properties' => (array)$schema->getProperties(),
			];
		}//end foreach

		return $schemas;
	}//end collectCompanionSchemas()

	/**
	 * Build the optional README.md (name + description + provenance line).
	 *
	 * @param array<string,mixed> $application The Application object.
	 *
	 * @return string|null The README contents, or null when the app has no description.
	 */
	private function buildReadme(array $application): ?string {
		$description = trim((string)($application['description'] ?? ''));
		if ($description === '') {
			return null;
		}

		$name = (string)($application['name'] ?? ($application['slug'] ?? 'Buildiq app'));

		return '# ' . $name . "\n\n" . $description . "\n\n"
			. '_Built with [Buildiq](https://conduction.nl) — a citizen-developer app builder for Nextcloud._' . "\n";
	}//end buildReadme()

	/**
	 * Resolve the descriptor category from the app, then the manifest, else a
	 * neutral default.
	 *
	 * @param array<string,mixed> $application The Application object.
	 * @param array<string,mixed> $manifest The version's manifest blob.
	 *
	 * @return string
	 */
	private function resolveCategory(array $application, array $manifest): string {
		$category = trim((string)($application['category'] ?? ''));
		if ($category !== '') {
			return $category;
		}

		$category = trim((string)($manifest['category'] ?? ''));
		if ($category !== '') {
			return $category;
		}

		return 'general';
	}//end resolveCategory()

	/**
	 * Resolve an icon reference to an `img/`-scoped path for the descriptor.
	 *
	 * @param mixed $icon The Application `icon`/`iconDark` block (`{ ref }`) or null.
	 * @param string $fallback The canonical `img/` path to use when only a bare filename is stored.
	 *
	 * @return string|null The `img/`-scoped ref, or null when no icon is set.
	 */
	private function iconRef(mixed $icon, string $fallback): ?string {
		if (is_array($icon) === false) {
			return null;
		}

		$ref = trim((string)($icon['ref'] ?? ''));
		if ($ref === '') {
			return null;
		}

		if (str_starts_with($ref, 'img/') === true) {
			return $ref;
		}

		if (str_contains($ref, '/') === false) {
			return 'img/' . $ref;
		}

		return $fallback;
	}//end iconRef()

	/**
	 * Canonicalise + encode a JSON payload (sorted keys, stable indentation,
	 * trailing newline) so re-serialising an unchanged app is byte-stable.
	 *
	 * @param array<string,mixed> $data The payload to encode.
	 *
	 * @return string Canonical JSON with a trailing newline.
	 */
	private function encode(array $data): string {
		$sorted = $this->sortKeysRecursive(value: $data);
		$encoded = json_encode($sorted, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($encoded === false) {
			// A payload that cannot encode is a programming error, not user input;
			// emit an empty object so the file map stays well-formed and diffable.
			$this->logger->warning('Buildiq AppRepoSerializer: JSON encode failed for a repo file.');
			return "{}\n";
		}

		return $encoded . "\n";
	}//end encode()

	/**
	 * Recursively sort associative-array keys while preserving list order
	 * (lists are ordered runtime data and MUST NOT be reordered).
	 *
	 * @param mixed $value The value to canonicalise.
	 *
	 * @return mixed The canonicalised value.
	 */
	private function sortKeysRecursive(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === true) {
			return array_map(fn ($item): mixed => $this->sortKeysRecursive(value: $item), $value);
		}

		ksort($value);
		$result = [];
		foreach ($value as $key => $item) {
			$result[$key] = $this->sortKeysRecursive(value: $item);
		}

		return $result;
	}//end sortKeysRecursive()
}//end class
