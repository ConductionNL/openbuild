<?php

/**
 * FleetAppId — resolve a Conduction fleet app's installed id across the rename.
 *
 * The fleet renamed: `openconnector` became `integriq`, `procest` became
 * `dossiq`, `nldesign` became `thematiq`, `docudesk` became `filinq`, and so
 * on. The new names have reached `development`, `beta` and `main`, so a
 * current instance answers to the new id — but an instance pinned to an older
 * release still answers only to the old one, and both are in the field at
 * once.
 *
 * That matters because every cross-app reference is a DUCK-TYPED RUNTIME
 * LOOKUP. `IAppManager::isEnabledForUser('openconnector')` against an instance
 * running `integriq` does not error — it returns false, and the integration
 * silently does nothing. A hard swap to the new id has exactly the same
 * failure in the other direction against an older deployment.
 *
 * So neither name alone is correct. This resolver takes a LIST, newest first,
 * and returns whichever id the instance actually has. Callers ask for the
 * canonical (new) name and get back the identity that really exists.
 *
 * WHY ONLY THE ID HALF. The rename moved two things, the app id and the PHP
 * namespace, and each breaks its own set of call sites. Buildiq has no
 * cross-app class lookups into a renamed app — its only PHP reaches are
 * `openregister` and `hermiq`, neither of which renamed — so the namespace
 * half of dossiq's `FleetAppId` would be dead code here. Should a cross-app
 * `class_exists()` or `$container->get()` ever be added, port
 * `classCandidates()`, `resolveClass()`, `getService()` and `isInstanceOf()`
 * from `dossiq/lib/Support/FleetAppId.php` rather than writing new ones:
 * fixing the id half and not the namespace half leaves the integration just
 * as dark.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Buildiq\Support
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

namespace OCA\Buildiq\Support;

use OCP\App\IAppManager;
use Throwable;

/**
 * Resolves fleet app ids across the in-flight rename.
 *
 * @spec exclude Infrastructure utility with no feature requirement of its own;
 *  it is exercised through the features that call it.
 */
final class FleetAppId {

	/**
	 * Candidate ids per canonical app, NEWEST FIRST.
	 *
	 * Order is the contract: the first entry that is installed wins, so a
	 * migrated instance resolves to the new id and one still on an older
	 * release falls back to the old one. Adding a rename means PREPENDING,
	 * never replacing — dropping an old id here is what silently breaks the
	 * integrations this class exists to protect.
	 *
	 * `buildiq`/`openbuild` is this app's own pair. It is listed for
	 * completeness and is never resolved through here; Buildiq addresses
	 * itself through `Application::APP_ID`.
	 *
	 * @var array<string, list<string>>
	 */
	public const CANDIDATES = [
		'integriq' => ['integriq', 'openconnector'],
		'filinq' => ['filinq', 'docudesk'],
		'thematiq' => ['thematiq', 'nldesign'],
		'stackiq' => ['stackiq', 'softwarecatalog'],
		'larpinq' => ['larpinq', 'larpingapp'],
		'dossiq' => ['dossiq', 'procest'],
		'learniq' => ['learniq', 'scholiq'],
		'decidiq' => ['decidiq', 'decidesk'],
		'buildiq' => ['buildiq', 'openbuild'],
		'keepiq' => ['keepiq', 'doriath'],
		'humaniq' => ['humaniq', 'hrmq'],
		'planninq' => ['planninq', 'planix'],
		'launchpad' => ['launchpad', 'mydash'],
	];

	/**
	 * The id this instance actually has installed, or null if none is.
	 *
	 * @param IAppManager $appManager The Nextcloud app manager.
	 * @param string      $canonical  Canonical (new) app name, e.g. 'integriq'.
	 *
	 * @return string|null The installed id, or null when the app is absent.
	 *
	 * @spec exclude Infrastructure utility with no feature requirement of its
	 *  own; it is exercised through the features that call it.
	 */
	public static function resolve(IAppManager $appManager, string $canonical): ?string {
		foreach ((self::CANDIDATES[$canonical] ?? [$canonical]) as $candidate) {
			try {
				if ($appManager->isInstalled($candidate) === true) {
					return $candidate;
				}
			} catch (Throwable $e) {
				// An app manager that cannot answer for one candidate must not
				// abort the search — the next candidate may still resolve.
				continue;
			}
		}

		return null;

	}//end resolve()

	/**
	 * Whether the app is installed AND enabled for the current user.
	 *
	 * Resolves the id first so the enabled check runs against the id the
	 * instance actually registered, rather than a name it never knew.
	 *
	 * @param IAppManager $appManager The Nextcloud app manager.
	 * @param string      $canonical  Canonical (new) app name, e.g. 'integriq'.
	 *
	 * @return bool True when present and enabled for the current user.
	 *
	 * @spec exclude Infrastructure utility with no feature requirement of its
	 *  own; it is exercised through the features that call it.
	 */
	public static function isEnabledForUser(IAppManager $appManager, string $canonical): bool {
		$id = self::resolve(appManager: $appManager, canonical: $canonical);
		if ($id === null) {
			return false;
		}

		try {
			return $appManager->isEnabledForUser($id);
		} catch (Throwable $e) {
			return false;
		}

	}//end isEnabledForUser()

}//end class
