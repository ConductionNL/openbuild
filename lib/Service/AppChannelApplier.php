<?php

/**
 * Buildiq AppChannelApplier
 *
 * Applies a parsed v2 app-repo template's channels — `dataRegisters`,
 * `connectors`, `automations`, `skills`, `flows` and `agents` — onto this
 * instance (app-channel-application). The last two (app-repo-format-flow-agent-
 * export) reuse the same OpenRegister `Flow` entity and `agent`
 * (`applicationSlug`) store `FlowAndAgentExportBundler` reads on the export
 * side — see `FlowChannelProvisioner` and `AgentChannelProvisioner`.
 *
 * Six steps carry an app from one instance to another: serialize → bind → push →
 * fetch → parse → apply. The first five were built; this class is the sixth. Until
 * it existed, installing a published v2 app produced an app holding its manifest
 * and nothing that makes it run, and reported success.
 *
 * Three rules shape everything here:
 *
 *  1. **Never overwrite.** Connectors are shared infrastructure — one source can
 *     serve several applications — so a colliding UUID is skipped and reported,
 *     never rewritten. Enforced with `saveObject(failIfExists: true)` so the
 *     guarantee lives in the call rather than in a preceding existence check that
 *     could drift or race.
 *  2. **Never claim atomicity.** OpenRegister has no cross-object transaction, so
 *     one failing item must not cost the caller the rest. The report carries every
 *     item's outcome instead of pretending the run was all-or-nothing.
 *  3. **Never drop silently.** Every channel is bounded, and hitting a bound is
 *     logged and counted. `ChannelApplyReport` enforces
 *     `created + skipped + failed === declared`.
 *
 * `openconnector` and `hermiq` are OPTIONAL — Buildiq declares only
 * `openregister` — so their channels degrade with a machine-readable reason while
 * the remaining channels still apply.
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
 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Exception\ObjectExistsException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies a parsed v2 app repo's channels onto this instance.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md
 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/app-channel-application/spec.md
 */
class AppChannelApplier {

	/**
	 * Connector schemas, matching AppRepoSerializer::CONNECTOR_KINDS.
	 *
	 * @var array<int,string>
	 */
	private const CONNECTOR_KINDS = ['source', 'mapping', 'synchronization', 'job'];

	/**
	 * The register holding broker credentials.
	 *
	 * @var string
	 */
	private const CREDENTIAL_REGISTER = 'credential-broker';

	/**
	 * The schema holding broker credentials.
	 *
	 * @var string
	 */
	private const CREDENTIAL_SCHEMA = 'brokeredcredential';

	/**
	 * Maximum connectors applied per kind.
	 *
	 * @var int
	 */
	private const MAX_CONNECTORS_PER_KIND = 2048;

	/**
	 * Maximum automations applied from one repo.
	 *
	 * @var int
	 */
	private const MAX_AUTOMATIONS = 512;

	/**
	 * Reason recorded when the skills channel is skipped because the supplied
	 * credential's own `allowedApps` does not include hermiq — hermiq fetches
	 * the skill bundle itself, under ITS OWN app identity, so a credential
	 * scoped only to `buildiq` cannot be used for that call.
	 *
	 * @var string
	 */
	private const REASON_CREDENTIAL_MISSING_HERMIQ_SCOPE = 'credential-missing-hermiq-scope';

	/**
	 * The app id hermiq identifies itself with to the credential broker
	 * (`GitHubTemplateCatalogService::APP_ID` on the hermiq side).
	 *
	 * @var string
	 */
	private const HERMIQ_APP_ID = 'hermiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object read/write.
	 * @param ConnectorRegisterAvailability $connectorRegister Whether Integriq's register can be written to
	 *                                                         here, and with which slug. Not nullable: the
	 *                                                         only fallback a null would leave is the
	 *                                                         literal it replaces.
	 * @param DataRegisterProvisioner $registerProvisioner The data-registers channel.
	 * @param SkillChannelDelegate $skillDelegate The skills channel (delegated to hermiq).
	 * @param FlowChannelProvisioner $flowProvisioner The flows channel (app-repo-format-flow-agent-export).
	 * @param AgentChannelProvisioner $agentProvisioner The agents channel (app-repo-format-flow-agent-export).
	 * @param LoggerInterface $logger PSR logger (secret-free diagnostics).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ConnectorRegisterAvailability $connectorRegister,
		private readonly DataRegisterProvisioner $registerProvisioner,
		private readonly SkillChannelDelegate $skillDelegate,
		private readonly FlowChannelProvisioner $flowProvisioner,
		private readonly AgentChannelProvisioner $agentProvisioner,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply every channel a parsed template declares.
	 *
	 * Best-effort by design: a channel that cannot be applied is reported, and the
	 * remaining channels are still applied.
	 *
	 * Repo coordinates are derived from the template's own `templateOrigin.repo`
	 * when the caller does not supply them, so that every install path can call
	 * this with what it already has. A path that had to thread extra arguments
	 * through is a path that eventually gets added without them.
	 *
	 * @param array<string,mixed> $template The parsed repo template.
	 * @param string|null $owner Repo owner (for the skills delegation).
	 * @param string|null $repo Repo name (for the skills delegation).
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID.
	 * @param string|null $credentialId Optional broker credential UUID.
	 * @param string|null $applicationUuid The LOCAL Application's own uuid — needed by the flows channel to
	 *                                     rebind newly created flows. Null on a caller with no local
	 *                                     Application context yet, in which case the flows channel degrades
	 *                                     with a stated reason like any other missing context.
	 * @param string|null $applicationSlug The LOCAL Application's own slug — needed by the agents channel
	 *                                     to tag newly created agents with THIS instance's slug, never the
	 *                                     source instance's.
	 *
	 * @return array<string,mixed> The channel report.
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-every-install-path-applies-the-v2-channels
	 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/app-channel-application/spec.md#requirement-published-flows-are-created-and-rebound-onto-the-local-application
	 */
	public function apply(
		array $template,
		?string $owner = null,
		?string $repo = null,
		?string $ref = null,
		?string $actingUserId = null,
		?string $credentialId = null,
		?string $applicationUuid = null,
		?string $applicationSlug = null,
	): array {
		$report = new ChannelApplyReport();

		[$owner, $repo] = $this->coordinatesFor(template: $template, owner: $owner, repo: $repo);

		$this->registerProvisioner->apply(
			registers: $this->channelOf(template: $template, name: 'dataRegisters'),
			report: $report
		);

		$this->applyConnectors(
			connectors: $this->channelOf(template: $template, name: 'connectors'),
			report: $report
		);

		$this->applyAutomations(
			automations: $this->channelOf(template: $template, name: 'automations'),
			report: $report
		);

		$this->flowProvisioner->apply(
			flows: $this->channelOf(template: $template, name: 'flows'),
			applicationUuid: $applicationUuid,
			report: $report
		);

		$this->agentProvisioner->apply(
			agents: $this->channelOf(template: $template, name: 'agents'),
			applicationSlug: $applicationSlug,
			report: $report
		);

		$this->applySkillsChannel(
			skills: $this->channelOf(template: $template, name: 'skills'),
			owner: $owner,
			repo: $repo,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId,
			report: $report
		);

		return $report->toArray();
	}//end apply()

	/**
	 * Apply the skills channel — delegating to hermiq, unless the supplied
	 * credential is already known to lack hermiq's scope.
	 *
	 * Hermiq's bundle installer does its OWN GitHub fetch, authenticating as
	 * app "hermiq" — independent of which app the credential was scoped for.
	 * A credential that works for every other channel here (buildiq's own
	 * search/fetch, all scoped as "buildiq") can still be denied by the
	 * broker for this one delegated call. Checking the credential's own
	 * `allowedApps` here — the SAME register/schema `credentialExists()`
	 * reads — catches that BEFORE attempting (and failing) the call, rather
	 * than after, so the report carries a specific, actionable reason instead
	 * of the generic `hermiq-install-failed` a caught Throwable produces.
	 *
	 * @param array<string,mixed> $skills The declared skills channel.
	 * @param string $owner Repo owner (for the delegation).
	 * @param string $repo Repo name (for the delegation).
	 * @param string|null $ref Optional git ref.
	 * @param string|null $actingUserId The session UID.
	 * @param string|null $credentialId Optional broker credential UUID.
	 * @param ChannelApplyReport $report The report to write into.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/surface-hermiq-credential-scope-requirement/specs/app-channel-application/spec.md#requirement-skills-are-delegated-to-hermiq-by-repository-coordinates
	 */
	private function applySkillsChannel(
		array $skills,
		string $owner,
		string $repo,
		?string $ref,
		?string $actingUserId,
		?string $credentialId,
		ChannelApplyReport $report,
	): void {
		if ($skills !== [] && in_array($credentialId, [null, ''], true) === false
			&& $this->credentialAllowsApp(credentialId: $credentialId, appId: self::HERMIQ_APP_ID) === false
		) {
			$declared = count($skills);

			$report->declareChannel(channel: 'skills', declared: $declared);
			$report->skipChannel(channel: 'skills', reason: self::REASON_CREDENTIAL_MISSING_HERMIQ_SCOPE);
			$report->addWarning(
				code: self::REASON_CREDENTIAL_MISSING_HERMIQ_SCOPE,
				channel: 'skills',
				message: 'This repository declares ' . $declared . ' skill(s), but the GitHub credential used '
					. 'for this install is not authorised for hermiq. Skills are fetched by hermiq itself, '
					. 'under its own app identity, so a credential scoped only to "buildiq" cannot be used '
					. 'for that fetch. Add "hermiq" to the credential\'s allowed apps and re-run the GitHub '
					. 'sync to install the skills.'
			);

			return;
		}

		$this->skillDelegate->apply(
			skills: $skills,
			owner: $owner,
			repo: $repo,
			ref: $ref,
			actingUserId: $actingUserId,
			credentialId: $credentialId,
			report: $report
		);

	}//end applySkillsChannel()

	/**
	 * Whether a broker credential's own `allowedApps` includes the given app id.
	 *
	 * A plain metadata read of a document the acting user's own request
	 * already names, not a use of the credential — so this is NOT in tension
	 * with the broker's own fail-closed "never tell the caller which guard
	 * failed" contract (that governs what the BROKER tells a caller trying to
	 * USE a credential; it says nothing about reading the credential's own
	 * `allowedApps` field before deciding whether to even attempt a
	 * downstream call with it).
	 *
	 * A lookup failure MUST NOT be reported as a confident "does not allow" —
	 * same reasoning as {@see credentialExists()}: an inconclusive answer here
	 * can only ever suppress the generic post-hoc failure this method exists
	 * to avoid, never introduce a NEW block that did not exist before it.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param string $appId The app id to check for.
	 *
	 * @return bool|null True/false when conclusive, null when the credential is absent or the lookup failed.
	 *
	 * @spec openspec/changes/surface-hermiq-credential-scope-requirement/specs/app-channel-application/spec.md#requirement-skills-are-delegated-to-hermiq-by-repository-coordinates
	 */
	private function credentialAllowsApp(string $credentialId, string $appId): ?bool {
		try {
			$credential = $this->objectService->find(
				id: $credentialId,
				register: self::CREDENTIAL_REGISTER,
				schema: self::CREDENTIAL_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Buildiq channel apply: credential scope lookup for "' . $credentialId . '" was inconclusive: '
				. $e->getMessage()
			);

			return null;
		}//end try

		// `?->` folds "no credential found" into the same absent-data path as
		// a malformed `allowedApps` — both are "inconclusive", not "denied".
		$allowedApps = ($credential?->getObject()['allowedApps'] ?? null);
		if (is_array($allowedApps) === false) {
			return null;
		}

		return in_array($appId, $allowedApps, true);
	}//end credentialAllowsApp()

	/**
	 * Resolve the repo coordinates, falling back to the template's own origin.
	 *
	 * @param array<string,mixed> $template The parsed template.
	 * @param string|null $owner Caller-supplied owner, if any.
	 * @param string|null $repo Caller-supplied repo name, if any.
	 *
	 * @return array{0:string,1:string} Owner and repo name, possibly empty.
	 */
	private function coordinatesFor(array $template, ?string $owner, ?string $repo): array {
		if (in_array($owner, [null, ''], true) === false && in_array($repo, [null, ''], true) === false) {
			return [$owner, $repo];
		}

		$origin = ($template['templateOrigin'] ?? []);
		$slug = '';
		if (is_array($origin) === true) {
			$slug = (string)($origin['repo'] ?? '');
		}

		$parts = explode('/', $slug);
		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
			return [(string)$owner, (string)$repo];
		}

		return [$parts[0], $parts[1]];
	}//end coordinatesFor()

	/**
	 * Read one channel from the template, tolerating its absence (a v1 repo).
	 *
	 * @param array<string,mixed> $template The parsed template.
	 * @param string $name The channel key.
	 *
	 * @return array<string,mixed> The channel, or an empty array.
	 */
	private function channelOf(array $template, string $name): array {
		// AppRepoParser NESTS the v2 channels under `channels`, and adds the key
		// only for a v2 repo. Reading them from the top level instead returns
		// nothing for every channel — which is not an error, just a silent
		// `declared: 0`, i.e. exactly the do-nothing-and-report-success failure
		// this class exists to end. Verified against the parser, not assumed.
		$channels = ($template['channels'] ?? []);
		if (is_array($channels) === false) {
			return [];
		}

		$channel = ($channels[$name] ?? []);
		if (is_array($channel) === false) {
			return [];
		}

		return $channel;
	}//end channelOf()

	/**
	 * Apply the connectors channel at the PUBLISHED uuids, so that the installed
	 * application's `connectors[]` bindings still resolve.
	 *
	 * A uuid that already exists is skipped, never overwritten — see the class
	 * docblock.
	 *
	 * @param array<string,mixed> $connectors The channel (kind → name → blob).
	 * @param ChannelApplyReport $report The report to write into.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-an-existing-connector-is-skipped-and-never-overwritten
	 */
	private function applyConnectors(array $connectors, ChannelApplyReport $report): void {
		$declared = 0;
		foreach (self::CONNECTOR_KINDS as $kind) {
			$declared += count((array)($connectors[$kind] ?? []));
		}

		$report->declareChannel(channel: 'connectors', declared: $declared);

		if ($declared === 0) {
			return;
		}

		// One question, asked once: is the connector register usable here, and
		// with which slug. It used to be two — an app-id check here and a
		// hardcoded register slug at the write — and only the first was ever
		// answered. `ConnectorRegisterAvailability` records the skip itself, so
		// a null cannot be carried past this line.
		$registerSlug = $this->connectorRegister->slugOrSkip(
			channel: 'connectors',
			declared: $declared,
			report: $report
		);
		if ($registerSlug === null) {
			return;
		}

		foreach (self::CONNECTOR_KINDS as $kind) {
			$applied = 0;
			foreach ((array)($connectors[$kind] ?? []) as $name => $blob) {
				$item = $kind . '/' . (string)$name;

				if ($applied >= self::MAX_CONNECTORS_PER_KIND) {
					$this->logTruncation(
						channel: 'connectors/' . $kind,
						declared: count((array)($connectors[$kind] ?? [])),
						bound: self::MAX_CONNECTORS_PER_KIND
					);
					$report->recordTruncated(channel: 'connectors', item: $item);
					continue;
				}

				$applied++;
				$this->applyOneConnector(
					kind: $kind,
					item: $item,
					blob: (array)$blob,
					report: $report,
					registerSlug: $registerSlug
				);
			}
		}//end foreach

	}//end applyConnectors()

	/**
	 * Apply a single connector at its published uuid.
	 *
	 * @param string $kind The connector kind (schema).
	 * @param string $item The report item identity.
	 * @param array<string,mixed> $blob The published connector body.
	 * @param ChannelApplyReport $report The report to write into.
	 * @param string $registerSlug The slug the connector register answers to on this
	 *                             instance, already resolved by the calling channel.
	 *                             Passed in rather than resolved here so one absent
	 *                             register skips a channel once instead of failing
	 *                             every item in it separately.
	 *
	 * @return void
	 */
	private function applyOneConnector(
		string $kind,
		string $item,
		array $blob,
		ChannelApplyReport $report,
		string $registerSlug,
	): void {
		// The published body carries its identity in `id` — verified across all 42
		// connectors of a real published artefact. `uuid` is present but null,
		// because the serializer emits ObjectEntity::getObject(), the body only.
		$uuid = (string)($blob['id'] ?? '');
		if ($uuid === '') {
			$report->recordFailed(channel: 'connectors', item: $item, reason: 'no-identity-in-blob');
			return;
		}

		try {
			// The never-overwrite guarantee lives IN this call via failIfExists. A
			// check-then-write would both race and drift.
			$this->objectService->saveObject(
				object: $blob,
				register: $registerSlug,
				schema: $kind,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
				failIfExists: true
			);

			$report->recordCreated(channel: 'connectors', item: $item);
			$this->collectCredentialRefs(blob: $blob, connector: $item, report: $report);
		} catch (ObjectExistsException) {
			// Collision detected BY TYPE, never by message text. An earlier draft
			// matched on strings, which meant a plain PHP "Unknown named parameter
			// $failIfExists" error was reported as a benign "already exists" — a
			// wiring bug wearing the costume of a normal, expected outcome.
			$report->recordSkipped(
				channel: 'connectors',
				item: $item,
				reason: ChannelApplyReport::REASON_EXISTS
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq channel apply: connector "' . $item . '" failed: ' . $e->getMessage());
			$report->recordFailed(channel: 'connectors', item: $item, reason: $e->getMessage());
		}//end try

	}//end applyOneConnector()

	/**
	 * Collect credential references that do not resolve on this instance.
	 *
	 * Publishing blanks secrets but keeps `credentialRef`, so an applied connector
	 * can be perfectly installed and still unable to run.
	 *
	 * @param array<string,mixed> $blob The connector body.
	 * @param string $connector The connector identity.
	 * @param ChannelApplyReport $report The report to write into.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-unresolvable-credential-references-are-reported
	 */
	private function collectCredentialRefs(array $blob, string $connector, ChannelApplyReport $report): void {
		foreach ($this->credentialNames(node: $blob) as $name) {
			if ($this->credentialExists(name: $name) === false) {
				$report->needsCredential(credential: $name, connector: $connector);
			}
		}

	}//end collectCredentialRefs()

	/**
	 * Walk a connector body collecting `credentialRef` names.
	 *
	 * @param mixed $node The node to walk.
	 *
	 * @return array<int,string> The referenced credential names.
	 */
	private function credentialNames(mixed $node): array {
		if (is_array($node) === false) {
			return [];
		}

		$names = [];
		foreach ($node as $key => $value) {
			if ($key === 'credentialRef' && is_array($value) === true) {
				$name = (string)($value['credentialName'] ?? '');
				if ($name !== '') {
					$names[] = $name;
				}

				continue;
			}

			$names = array_merge($names, $this->credentialNames(node: $value));
		}

		return array_values(array_unique($names));
	}//end credentialNames()

	/**
	 * Whether a credential of this name resolves on the instance.
	 *
	 * Absence is the answer we act on, so a lookup FAILURE must not be reported as
	 * a confident "does not exist" — an unavailable broker would otherwise
	 * manufacture a list of missing credentials that are in fact present.
	 *
	 * @param string $name The credential name.
	 *
	 * @return bool True when it resolves, or when the lookup was inconclusive.
	 */
	private function credentialExists(string $name): bool {
		try {
			// Broker credentials live in register `credential-broker`, schema
			// `brokeredcredential` — verified against the live instance rather
			// than assumed, because a lookup pointed at the wrong table returns
			// "nothing matched", which is indistinguishable from a true absence
			// and would manufacture a list of missing credentials that are in
			// fact present. register/schema are FILTER keys on findAll(), not
			// parameters of their own.
			$found = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => self::CREDENTIAL_REGISTER,
						'schema' => self::CREDENTIAL_SCHEMA,
						'name' => $name,
					],
				],
				_rbac: false,
				_multitenancy: false
			);

			// `findAll()` returns an array, so the `is_array()` conjunct this
			// replaces could never be false.
			return ($found !== []);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Buildiq channel apply: credential lookup for "' . $name . '" was inconclusive: ' . $e->getMessage()
			);

			return true;
		}//end try

	}//end credentialExists()

	/**
	 * Apply the automations channel with the same create-or-skip rules.
	 *
	 * @param array<string,mixed> $automations The channel (slug → blob).
	 * @param ChannelApplyReport $report The report to write into.
	 *
	 * @return void
	 */
	private function applyAutomations(array $automations, ChannelApplyReport $report): void {
		$report->declareChannel(channel: 'automations', declared: count($automations));

		if ($automations === []) {
			return;
		}

		// Same question, same answer: automations are written into the same
		// register, so the same absence has to stop them too.
		$registerSlug = $this->connectorRegister->slugOrSkip(
			channel: 'automations',
			declared: count($automations),
			report: $report
		);
		if ($registerSlug === null) {
			return;
		}

		$applied = 0;
		foreach ($automations as $slug => $blob) {
			$slug = (string)$slug;
			if ($applied >= self::MAX_AUTOMATIONS) {
				$this->logTruncation(
					channel: 'automations',
					declared: count($automations),
					bound: self::MAX_AUTOMATIONS
				);
				$report->recordTruncated(channel: 'automations', item: $slug);
				continue;
			}

			$applied++;
			$this->applyOneConnector(
				kind: 'job',
				item: 'automations/' . $slug,
				blob: (array)$blob,
				report: $report,
				registerSlug: $registerSlug
			);
		}

	}//end applyAutomations()

	/**
	 * Log that a channel bound was reached. Never silent: an install that quietly
	 * drops half an app is the precise failure this class exists to prevent.
	 *
	 * @param string $channel The channel name.
	 * @param int $declared How many items were declared.
	 * @param int $bound The configured maximum.
	 *
	 * @return void
	 */
	private function logTruncation(string $channel, int $declared, int $bound): void {
		$this->logger->warning(
			'Buildiq channel apply: channel "' . $channel . '" declared ' . $declared
			. ' items but the bound is ' . $bound . ' — the excess was NOT applied.'
		);

	}//end logTruncation()
}//end class
