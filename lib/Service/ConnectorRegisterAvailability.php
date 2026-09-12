<?php

/**
 * Buildiq ConnectorRegisterAvailability
 *
 * Answers one question for the connectors and automations channels: can this
 * instance's Integriq register be written to, and if not, why.
 *
 * It exists because that question used to be asked as two half-questions in two
 * places, and only one half was ever answered. `applyConnectors()` checked that
 * the APP was enabled — `IAppManager`, via `FleetAppId` — and then wrote into a
 * hardcoded `openconnector` register. On an instance that had run Integriq's
 * register-slug repair step those two facts disagree: the app answers to
 * `integriq` and the register row does too, so the app check passed and every
 * write went to a slug nothing answers to.
 *
 * The app id and the register slug are moved by two SEPARATE repair steps, and
 * either can run first. `IAppManager` is the authority for one,
 * `openregister_registers` for the other, and neither predicts the other. Asking
 * both here, together, is the only shape in which the disagreement cannot be
 * missed.
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

use OCA\Buildiq\Support\FleetAppId;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Whether the connector register can be written to here, and with which slug.
 *
 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md
 */
class ConnectorRegisterAvailability {

	/**
	 * The canonical register slug, which is not necessarily the one to write with.
	 *
	 * Integriq's repair step renames this register from `openconnector` per
	 * instance, so both names are live across the estate and neither is safe to
	 * write as a literal. This is the name asked ABOUT; the answer comes back
	 * from {@see RegisterSlugResolverInterface}.
	 *
	 * @var string
	 */
	public const CANONICAL_REGISTER = 'integriq';

	/**
	 * Reason recorded when Integriq itself is not available.
	 *
	 * @var string
	 */
	public const REASON_APP_UNAVAILABLE = 'openconnector-unavailable';

	/**
	 * Reason recorded when Integriq is present but its register is not.
	 *
	 * Distinct from {@see REASON_APP_UNAVAILABLE} on purpose. That one means the
	 * app is absent, which an operator fixes by enabling it. This one means the
	 * app is present and the register is not, which is a different repair, and
	 * used to be reported as neither: the write simply went to a slug nothing
	 * answers to.
	 *
	 * @var string
	 */
	public const REASON_REGISTER_ABSENT = 'connector-register-absent';

	/**
	 * Constructor.
	 *
	 * @param RegisterSlugResolverInterface $slugResolver Which slug the register answers to here.
	 * @param IAppManager                   $appManager   Optional-dependency detection.
	 * @param LoggerInterface               $logger       PSR logger (secret-free diagnostics).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterSlugResolverInterface $slugResolver,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The slug to write connector objects with, or null with the channel skipped.
	 *
	 * Records the skip itself rather than handing the caller a reason to record,
	 * so that a caller cannot take the null and carry on. The two channels that
	 * use this then need one branch each — `if null, return` — instead of the two
	 * they carried before, and the second of those two was the one nobody wrote.
	 *
	 * @param string             $channel  The report channel to skip when unusable.
	 * @param int                $declared How many items the channel declared, for the log line.
	 * @param ChannelApplyReport $report   The report to record the skip on.
	 *
	 * @return string|null The register slug to write with, or null when the
	 *                     channel has been skipped and the caller must return.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FleetAppId is a stateless resolver
	 *  over a constant map, with no state to inject and nothing a test would
	 *  substitute. Injecting it would add a constructor argument to every
	 *  consumer to no end.
	 *
	 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md
	 */
	public function slugOrSkip(string $channel, int $declared, ChannelApplyReport $report): ?string {
		if (FleetAppId::isEnabledForUser(appManager: $this->appManager, canonical: self::CANONICAL_REGISTER) === false) {
			$this->logger->info(
				'Buildiq channel apply: integriq (formerly openconnector) is not enabled — skipping '
				. $declared . ' declared ' . $channel . '.'
			);
			$report->skipChannel(channel: $channel, reason: self::REASON_APP_UNAVAILABLE);
			return null;
		}

		$resolution = $this->slugResolver->resolve(canonical: self::CANONICAL_REGISTER);
		if ($resolution->isResolved() === false) {
			$this->logger->warning(
				'Buildiq channel apply: the connector register is not on this instance under any of its known '
				. 'slugs (' . implode(', ', $resolution->candidates) . '). Integriq IS enabled, so this is a '
				. 'register that has not been provisioned rather than a missing app — skipping ' . $declared
				. ' declared ' . $channel . '.'
			);
			$report->skipChannel(channel: $channel, reason: self::REASON_REGISTER_ABSENT);
			return null;
		}

		return $resolution->slug;
	}//end slugOrSkip()
}//end class
