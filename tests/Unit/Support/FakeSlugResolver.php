<?php

/**
 * A register-slug resolver bound to a fixed instance state.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Support;

use OCA\OpenRegister\Contract\RegisterSlugResolution;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;

/**
 * Answers as an instance that carries exactly the given register slugs.
 *
 * Hand written rather than `createMock()`, and the reason is the whole point of
 * these tests. What is being checked is the DIFFERENCE between a migrated and an
 * unmigrated instance, and that difference is the resolver's entire behaviour. A
 * mock stubbed to return one slug behaves identically on both, so every test
 * built on one would pass whether or not the code under test used the answer.
 *
 * The candidate list is stated here rather than read from
 * `RegisterSlugAliases`, which lives in openregister's `lib/Support/` and is not
 * published to consumers. Only the register this app actually reads is listed:
 * a double that carried the whole fleet map would be a second copy of
 * OpenRegister's truth, maintained here by someone who does not own it, which is
 * precisely what the published resolver exists to prevent.
 */
final class FakeSlugResolver implements RegisterSlugResolverInterface {

	/**
	 * The slug history of the one register Buildiq reads.
	 *
	 * @var array<string, list<string>>
	 */
	private const CANDIDATES = ['integriq' => ['integriq', 'openconnector']];

	/**
	 * Constructor.
	 *
	 * @param list<string> $present The register slugs this instance carries.
	 */
	public function __construct(private readonly array $present = []) {
	}//end __construct()

	/**
	 * Resolve against the fixed instance state.
	 *
	 * @param string       $canonical  The canonical register slug.
	 * @param list<string> $candidates Explicit candidates, newest first.
	 *
	 * @return RegisterSlugResolution The resolution.
	 */
	public function resolve(string $canonical, array $candidates=[]): RegisterSlugResolution {
		$probe = $candidates;
		if ($probe === []) {
			$probe = (self::CANDIDATES[$canonical] ?? [$canonical]);
		}

		$matched = array_values(
			array_filter($probe, fn (string $slug): bool => in_array($slug, $this->present, true))
		);

		$state = RegisterSlugResolution::RESOLVED;
		if ($matched === []) {
			$state = RegisterSlugResolution::ABSENT;
		} else if (count($matched) > 1) {
			$state = RegisterSlugResolution::AMBIGUOUS;
		}

		return new RegisterSlugResolution(
			canonical: $canonical,
			slug: ($matched[0] ?? null),
			state: $state,
			candidates: $probe,
			matched: $matched
		);
	}//end resolve()

	/**
	 * The slug to read with, or null.
	 *
	 * @param string       $canonical  The canonical register slug.
	 * @param list<string> $candidates Explicit candidates, newest first.
	 *
	 * @return string|null The slug, or null.
	 */
	public function slugOrNull(string $canonical, array $candidates=[]): ?string {
		return $this->resolve(canonical: $canonical, candidates: $candidates)->slug;
	}//end slugOrNull()
}//end class
