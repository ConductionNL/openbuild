<?php

/**
 * Handler for the buildiq.upsertSchema MCP tool.
 *
 * Creates or updates a JSON Schema in the given app version's per-version OR
 * register. The slug is automatically namespaced with appSlug+versionSlug.
 *
 * @category Service
 * @package  OCA\Buildiq\Mcp\Handler
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Mcp\Handler;

use OCA\Buildiq\Service\ApplicationVersionService;

/**
 * Handles the buildiq.upsertSchema tool invocation.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-8
 */
class UpsertSchemaHandler extends AbstractToolHandler {
	/**
	 * Stated reason returned when OpenRegister is not on this instance.
	 *
	 * @var string
	 */
	private const OR_ABSENT = 'OpenRegister is not installed on this instance, so schemas cannot be authored.';

	/**
	 * Execute the upsertSchema tool.
	 *
	 * @param array<string, mixed> $args Tool arguments (appSlug, versionSlug, slug, title, description, properties, required).
	 *
	 * @return array<string, mixed>
	 */
	public function handle(array $args): array {
		$validation = $this->validateArgs(args: $args);
		if (isset($validation['error']) === true) {
			return $this->errorResult(error: 'invalid_arguments', message: $validation['error']);
		}

		// Schema creation/update mirrors OR's admin-only SchemasController gate
		// (OR #1949/#1957/#1959 — default-secure checkSchemaManagePermission).
		$adminError = $this->requireAdminUser();
		if ($adminError !== null) {
			return $adminError;
		}

		$appSlug = $validation['appSlug'];
		$versionSlug = $validation['versionSlug'];
		$rawSlug = $validation['rawSlug'];
		$title = $validation['title'];
		$description = $validation['description'];
		$properties = $validation['properties'];
		$required = $validation['required'];
		$namespacedSlug = $appSlug . '-' . $versionSlug . '-' . $rawSlug;
		// Prefix from the constant, never typed: see
		// ApplicationVersionService::VERSION_REGISTER_PREFIX for why it is
		// separate from REGISTER_SLUG and why it stays `openbuild-`.
		$registerSlug = ApplicationVersionService::VERSION_REGISTER_PREFIX . $appSlug . '-' . $versionSlug;

		try {
			// ADR-083 rule 1: establish availability BEFORE the reach.
			if ($this->schemaMappersAvailable() === false) {
				return $this->errorResult(error: 'openregister_unavailable', message: self::OR_ABSENT);
			}

			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
			$registerMapper = $this->container->get('OCA\OpenRegister\Db\RegisterMapper');

			$blob = [
				'slug' => $namespacedSlug,
				'title' => $title,
				'description' => $description,
				'type' => 'object',
				'required' => array_values(array_filter((array)$required, 'is_string')),
				'properties' => (array)$properties,
			];

			$existing = $this->findExistingSchema(schemaMapper: $schemaMapper, namespacedSlug: $namespacedSlug);

			if ($existing !== null) {
				// Ownership guard: verify the found schema belongs to the expected
				// per-version register before allowing an update (issue #168).
				$ownershipError = $this->verifySchemaOwnership(
					registerMapper: $registerMapper,
					registerSlug: $registerSlug,
					schemaId: $existing->getId()
				);
				if ($ownershipError !== null) {
					return $ownershipError;
				}

				$schema = $schemaMapper->updateFromArray($existing->getId(), $blob);
				return [
					'success' => true,
					'action' => 'updated',
					'schema' => [
						'id' => $schema->getId(),
						'slug' => $namespacedSlug,
						'shortSlug' => $rawSlug,
						'title' => $title,
						'register' => $registerSlug,
					],
				];
			}//end if

			$schema = $schemaMapper->createFromArray($blob);
			$this->attachSchemaToRegister(
				registerMapper: $registerMapper,
				registerSlug: $registerSlug,
				schemaId: $schema->getId()
			);

			return [
				'success' => true,
				'action' => 'created',
				'schema' => [
					'id' => $schema->getId(),
					'slug' => $namespacedSlug,
					'shortSlug' => $rawSlug,
					'title' => $title,
					'register' => $registerSlug,
				],
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Buildiq MCP: upsertSchema failed',
				['appSlug' => $appSlug, 'slug' => $rawSlug, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
			);
			return $this->errorResult(error: 'upsert_failed', message: 'Failed to upsert schema. See server logs for details.');
		}//end try

	}//end handle()

	/**
	 * Whether OpenRegister's schema/register mappers can be reached at all.
	 *
	 * ADR-083 rule 1 wants the dependency declared somewhere a reader — and a
	 * gate — can see it. Unlike every other handler here, this one cannot use
	 * the constructor-injected ObjectServiceInterface: OpenRegister publishes
	 * no contract for SchemaMapper/RegisterMapper (its lib/Contract/ holds only
	 * ObjectServiceInterface and ObjectEntityInterface), and type-hinting the
	 * concrete mappers would violate ADR-084. So the lookup stays and the
	 * availability question is asked out loud instead.
	 *
	 * class_exists() is the same question the container would answer —
	 * `SimpleContainer::has()` IS `isset($this->container[$id]) ||
	 * class_exists($id)`
	 * (server/lib/private/AppFramework/Utility/SimpleContainer.php:50) — but
	 * asking it here turns an opaque `internal_error` into a stated reason.
	 *
	 * @return bool True when both mappers are loadable.
	 *
	 * @spec openspec/specs/openbuild-rbac/spec.md
	 */
	private function schemaMappersAvailable(): bool {
		return class_exists('\OCA\OpenRegister\Db\SchemaMapper') === true
			&& class_exists('\OCA\OpenRegister\Db\RegisterMapper') === true;
	}//end schemaMappersAvailable()

	/**
	 * Validate and extract typed arguments for upsertSchema.
	 *
	 * Delegates slug/title checks to validateSlugsAndTitle() and
	 * properties/required normalisation to normaliseSchemaBody() so each
	 * helper stays well within the cyclomatic-complexity threshold.
	 *
	 * @param array<string, mixed> $args Raw tool arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function validateArgs(array $args): array {
		$appSlug = (string)($args['appSlug'] ?? '');
		$versionSlug = (string)($args['versionSlug'] ?? 'development');
		$rawSlug = (string)($args['slug'] ?? '');
		$title = (string)($args['title'] ?? '');
		$description = (string)($args['description'] ?? '');

		$slugError = $this->validateSlugsAndTitle(appSlug: $appSlug, versionSlug: $versionSlug, rawSlug: $rawSlug, title: $title);
		if ($slugError !== null) {
			return ['error' => $slugError];
		}

		$bodyResult = $this->normaliseSchemaBody(properties: $args['properties'] ?? [], required: $args['required'] ?? []);
		if (isset($bodyResult['error']) === true) {
			return $bodyResult;
		}

		return [
			'appSlug' => $appSlug,
			'versionSlug' => $versionSlug,
			'rawSlug' => $rawSlug,
			'title' => $title,
			'description' => $description,
			'properties' => $bodyResult['properties'],
			'required' => $bodyResult['required'],
		];

	}//end validateArgs()

	/**
	 * Validate the slug and title fields, returning an error string or null on success.
	 *
	 * @param string $appSlug Application slug.
	 * @param string $versionSlug Version slug.
	 * @param string $rawSlug Schema slug (before namespacing).
	 * @param string $title Schema title.
	 *
	 * @return string|null
	 */
	private function validateSlugsAndTitle(string $appSlug, string $versionSlug, string $rawSlug, string $title): ?string {
		if ($appSlug === '' || $this->isValidSlug(candidate: $appSlug) === false) {
			return "Invalid appSlug '{$appSlug}'.";
		}

		if ($this->isValidSlug(candidate: $versionSlug) === false) {
			return "Invalid versionSlug '{$versionSlug}'.";
		}

		if ($rawSlug === '' || $this->isValidSlug(candidate: $rawSlug) === false) {
			return "Invalid schema slug '{$rawSlug}'.";
		}

		if ($title === '') {
			return 'title is required.';
		}

		return null;
	}//end validateSlugsAndTitle()

	/**
	 * Validate and normalise the properties + required fields of the schema body.
	 *
	 * @param mixed $properties Raw properties value from tool arguments.
	 * @param mixed $required Raw required value from tool arguments.
	 *
	 * @return array{properties?: array, required?: array, error?: string}
	 */
	private function normaliseSchemaBody(mixed $properties, mixed $required): array {
		if (is_array($properties) === false || $properties === []) {
			return ['error' => 'properties must be a non-empty object of JSON-Schema property definitions.'];
		}

		if (is_array($required) === false) {
			$required = [];
		}

		return ['properties' => $properties, 'required' => $required];
	}//end normaliseSchemaBody()

	/**
	 * Find an existing schema by its namespaced slug, or return null.
	 *
	 * @param object $schemaMapper OR SchemaMapper instance.
	 * @param string $namespacedSlug Full namespaced slug to look up.
	 *
	 * @return object|null
	 */
	private function findExistingSchema(object $schemaMapper, string $namespacedSlug): ?object {
		try {
			$matches = $schemaMapper->findBySlug($namespacedSlug);
			if (is_array($matches) === true && $matches !== []) {
				return $matches[0];
			}
		} catch (\Throwable $_e) {
			// Not found — treat as absent.
		}

		return null;
	}//end findExistingSchema()

	/**
	 * Verify that the found schema belongs to the expected per-version register.
	 *
	 * Guards against a slug-collision attack where an attacker tricks the handler
	 * into overwriting a schema that belongs to a different application or version
	 * (issue #168 — UpsertSchemaHandler register ownership check).
	 *
	 * Returns a forbidden error envelope if the schema is not owned by the given
	 * register, null on success.
	 *
	 * @param object $registerMapper OR RegisterMapper instance.
	 * @param string $registerSlug Expected per-version register slug.
	 * @param int $schemaId ID of the schema to verify.
	 *
	 * @return array{isError: true, error: string, message: string}|null Null on allow.
	 */
	private function verifySchemaOwnership(object $registerMapper, string $registerSlug, int $schemaId): ?array {
		try {
			$register = $registerMapper->find($registerSlug, _multitenancy: false);
			$schemas = $register->getSchemas();
			if (is_array($schemas) === false || in_array(needle: $schemaId, haystack: $schemas, strict: true) === false) {
				$this->logger->warning(
					'Buildiq MCP: upsertSchema ownership check failed',
					['register' => $registerSlug, 'schemaId' => $schemaId]
				);
				return $this->errorResult(
					error: 'forbidden',
					message: "Schema does not belong to register '{$registerSlug}'. Update denied."
				);
			}
		} catch (\Throwable $e) {
			// Register not found: also deny (schema cannot belong to it).
			$this->logger->warning(
				'Buildiq MCP: upsertSchema register not found during ownership check',
				['register' => $registerSlug, 'exception' => $e->getMessage()]
			);
			return $this->errorResult(
				error: 'forbidden',
				message: "Register '{$registerSlug}' not found. Update denied."
			);
		}//end try

		return null;
	}//end verifySchemaOwnership()

	/**
	 * Attach a newly created schema to its per-version register.
	 *
	 * Non-fatal: logs a warning but does not re-throw if the register is missing.
	 *
	 * @param object $registerMapper OR RegisterMapper instance.
	 * @param string $registerSlug Slug of the per-version register to attach to.
	 * @param int $schemaId ID of the freshly created schema.
	 *
	 * @return void
	 */
	private function attachSchemaToRegister(object $registerMapper, string $registerSlug, int $schemaId): void {
		try {
			$register = $registerMapper->find($registerSlug, _multitenancy: false);
			$current = $register->getSchemas();
			if (is_array($current) === false) {
				$current = [];
			}

			$register->setSchemas(array_values(array_unique(array_merge($current, [$schemaId]))));
			$registerMapper->update($register);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Buildiq MCP: upsertSchema attach-to-register failed',
				['register' => $registerSlug, 'exception' => $e->getMessage()]
			);
		}

	}//end attachSchemaToRegister()
}//end class
