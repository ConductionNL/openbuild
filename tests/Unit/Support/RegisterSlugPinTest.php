<?php

/**
 * No code under lib/ pins a superseded register slug.
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

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The case that has never once been caught.
 *
 * A consumer pinned to a superseded register slug on a MIGRATED instance does
 * not raise. `openregister_registers` has no row with that slug, so the read
 * matches nothing and returns an empty result set, which is byte-for-byte what a
 * healthy, empty register returns. There is no exception, no 404, no log line
 * that separates the two. Every other guard in this repository watches
 * behaviour, and this defect has no behaviour to watch: it is a feature that
 * quietly stops happening.
 *
 * So the guard is static, and it is repo-wide rather than diff-scoped. Diff
 * scope is right for debt a PR could reasonably be asked to carry; it is wrong
 * here, because every one of these references was written BEFORE the slug was
 * renamed and will therefore never appear in a diff. A diff-scoped version of
 * this test passes on a repository full of the defect.
 *
 * ## What it does NOT catch
 *
 * It reads lines, not data flow. A superseded slug arriving from app config,
 * from a manifest, or through more than one assignment is invisible to it, as is
 * a `match` arm built at run time. That is why
 * {@see \OCA\Buildiq\Tests\Unit\Service\ConnectorRegisterResolutionTest} exists
 * beside it: this guard stops the literal being TYPED, and that one stops the
 * resolved slug being IGNORED.
 *
 * Measured on the mutation that reinstated `openconnector` in
 * `AppChannelApplier`: the unmigrated-instance test still passed, because on an
 * unmigrated instance the pinned literal happens to be the right answer. Only
 * the migrated case failed, and only the migrated case has ever mattered.
 */
class RegisterSlugPinTest extends TestCase {

	/**
	 * Superseded register slug => the canonical slug replacing it.
	 *
	 * All ten, and this list used to hold one. The narrow version said it
	 * covered "the slugs THIS app could plausibly type", and that premise is
	 * measurably wrong about this repository: a sweep of register position under
	 * `lib/` finds `hermiq` and `integriq` already there, beside the 33 sites
	 * naming `buildiq` itself. An app that types two other apps' register slugs
	 * today can type a third tomorrow, and a guard scoped to `openconnector`
	 * alone would watch the one slug this repository has already been cleaned of
	 * while missing the nine it is actually exposed to.
	 *
	 * Transcribed from openregister's `lib/Support/RegisterSlugAliases.php`,
	 * which is the authority and is NOT published to consumers. Note that the
	 * list is not derivable from the app-rename map: `stackiq` renamed the
	 * register `voorzieningen`, while its former app id `softwarecatalog` was
	 * never a register slug on any instance.
	 *
	 * @var array<string, string>
	 */
	private const SUPERSEDED = [
		'openconnector'   => 'integriq',
		'openbuild'       => 'buildiq',
		'decidesk'        => 'decidiq',
		'hrmq'            => 'humaniq',
		'larpingapp'      => 'larpinq',
		'planix'          => 'planninq',
		'voorzieningen'   => 'stackiq',
		'procest'         => 'dossiq',
		'procest-default' => 'dossiq-default',
		'scholiq'         => 'learniq',
	];

	/**
	 * Files allowed to name a superseded slug, and why.
	 *
	 * Each entry must be a genuine exception — a file that exists in order to
	 * name the old slug — not a deferral. Anything else belongs in a resolver
	 * call.
	 *
	 * `FleetAppId` is the standing example, and it is the distinction this whole
	 * mechanism turns on: it maps APP IDS, whose source of truth is
	 * `IAppManager`, not register slugs, whose source of truth is
	 * `openregister_registers`. Two different repair steps move them and either
	 * can run first, so one cannot be used to predict the other.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = [
		'lib/Support/FleetAppId.php' => 'app ids, not register slugs; a different question with a different source of truth',
	];

	/**
	 * Source patterns that put a string literal in REGISTER position.
	 *
	 * Deliberately narrow. A slug is only a defect where it identifies a
	 * register; the same word in a log message, a skip reason or an app id is not
	 * this defect, and a guard that flagged those would be turned off.
	 *
	 * The last three cover the NULL-COALESCING DEFAULT, and they are the reason
	 * this list is eight long rather than five. integriq shipped
	 * `register: ($data['register'] ?? 'openconnector')` in MappingsController
	 * and this guard, copied from here, did not see it: all five of the original
	 * patterns require the quote to follow `register:` directly, and the
	 * coalesce operator sits in between. It was found by a hand grep. A default
	 * is the likeliest place for a pin to survive a rename, because it is the
	 * branch nobody exercises on a healthy instance.
	 *
	 * BEFORE WIDENING THIS LIST AGAIN, read
	 * {@see testNoFileTypesAPerVersionRegisterPrefix()}. Two things it knows
	 * that this list cannot learn.
	 *
	 * Every pattern here captures a WHOLE slug, and whole-slug matching is not
	 * sufficient. A register name assembled from a prefix carries the stale half
	 * as `openbuild-`, which is not a key in SUPERSEDED and never will be, so no
	 * amount of widening here reaches it.
	 *
	 * And that guard runs in the opposite direction to this one. This list
	 * forbids the OLD name. That one forbids the NEW name where the old is
	 * frozen, which is the shape that was live in this repository while this
	 * guard read green.
	 *
	 * @var list<string>
	 */
	private const REGISTER_POSITION = [
		'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/',
		'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\bregister:\s*\(?[^,()]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\'register\'\s*=>\s*\(?[^,()]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*[^;]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/',
	];

	/**
	 * Source patterns that BUILD a register slug from a typed prefix.
	 *
	 * Separate from REGISTER_POSITION because they capture a prefix rather than
	 * a slug, and are checked against the constant rather than against
	 * SUPERSEDED. See {@see testNoFileTypesAPerVersionRegisterPrefix()}.
	 *
	 * The `slug:` named-argument form is deliberately absent. `slug:` also names
	 * schema slugs, template slugs and app slugs in this repository, so a
	 * pattern on it would report findings that are not this defect, and a guard
	 * that cries wolf is a guard someone turns off. The one register call that
	 * used that form now takes the constant, so it is held by the code rather
	 * than by a pattern.
	 *
	 * @var list<string>
	 */
	private const BUILT_REGISTER_PREFIX = [
		'/\$[a-zA-Z0-9_]*[Rr]egister[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]*-)\'\s*\./',
		'/\$[a-zA-Z0-9_]*[Rr]egister[a-zA-Z0-9_]*\s*=\s*sprintf\(\s*\'([a-zA-Z0-9_-]*-)%s/',
		'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]*-)\'\s*\./',
	];

	/**
	 * No file under lib/ names a superseded register slug in register position.
	 *
	 * @return void
	 */
	public function testNoSourceFilePinsASupersededRegisterSlug(): void {
		$findings = [];
		foreach ($this->sourceFiles() as $relative => $absolute) {
			if (isset(self::ALLOWED[$relative]) === true) {
				continue;
			}

			// NOT FILE_SKIP_EMPTY_LINES. Skipping blank lines renumbers every
			// line after the first one, so `$index + 1` stops being the line
			// number and becomes the count of non-blank lines. Measured on
			// openregister's reconciler: a pin on line 590 was reported as line
			// 528, because 62 blank lines preceded it. A guard that names the
			// wrong line is a guard whose next reader concludes it is broken.
			$lines = file($absolute, FILE_IGNORE_NEW_LINES);
			if ($lines === false) {
				continue;
			}

			foreach ($lines as $index => $line) {
				foreach (self::REGISTER_POSITION as $pattern) {
					if (preg_match($pattern, $line, $matches) !== 1) {
						continue;
					}

					$slug = strtolower($matches[1]);
					if (isset(self::SUPERSEDED[$slug]) === false) {
						continue;
					}

					$findings[] = sprintf(
						'%s:%d pins the superseded register slug \'%s\'. Resolve \'%s\' through '
						. 'RegisterSlugResolverInterface::resolve() instead, and branch on isResolved(), '
						. 'because reading with a slug this instance does not carry returns zero rows, '
						. 'not an error.',
						$relative,
						($index + 1),
						$slug,
						self::SUPERSEDED[$slug]
					);
				}
			}
		}

		$this->assertSame([], $findings, "Superseded register slugs are pinned:\n" . implode("\n", $findings));
	}//end testNoSourceFilePinsASupersededRegisterSlug()

	/**
	 * The guard actually looks at something.
	 *
	 * A file walker that silently finds no files is the classic hollow green: the
	 * assertion above would pass on an empty list forever. This pins the walker
	 * to a floor well below the real count, so a broken path fails here rather
	 * than passing there.
	 *
	 * @return void
	 */
	public function testTheGuardScansTheSourceTree(): void {
		$files = $this->sourceFiles();

		$this->assertGreaterThan(100, count($files), 'The walker must see lib/, or the guard above cannot fail.');
		$this->assertArrayHasKey(
			'lib/Service/AppChannelApplier.php',
			$files,
			'The applier is one of the two files this guard was written for; the walker must reach it.'
		);
		$this->assertArrayHasKey(
			'lib/Service/AppRepoSerializer.php',
			$files,
			'The serializer is the other; the walker must reach it too.'
		);
	}//end testTheGuardScansTheSourceTree()

	/**
	 * The patterns match a pinned slug when one is present.
	 *
	 * Watched failing is not enough on its own once the tree is clean: from then
	 * on the guard passes whether or not its regexes still work. This feeds each
	 * register-position form a known-bad line and requires a match, so a regex
	 * that stops matching reddens immediately instead of going quiet.
	 *
	 * The sixth sample is not invented. It is integriq's MappingsController line
	 * as it stood on `development`, copied verbatim, and it is here because the
	 * five patterns above let it through when this file was the source they were
	 * copied from.
	 *
	 * @return void
	 */
	public function testEachRegisterPositionPatternStillMatches(): void {
		$samples = [
			'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/' => "\$objectService->setRegister('openconnector');",
			'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/'                    => "\$svc->find(id: \$id, register: 'openconnector', schema: 'source');",
			'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/'              => "'filters' => ['register' => 'openconnector', 'schema' => 'job'],",
			'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\tprivate const CONNECTOR_REGISTER = 'openconnector';",
			'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$registerSlug = 'openconnector';",
			'/\bregister:\s*\(?[^,()]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/'   => "\t\t\tregister: (\$data['register'] ?? 'openconnector'),",
			'/\'register\'\s*=>\s*\(?[^,()]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/' => "'register' => (\$data['register'] ?? 'openbuild'),",
			'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*[^;]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$register = \$resolution?->slug ?? 'openbuild';",
		];

		foreach (self::REGISTER_POSITION as $pattern) {
			$this->assertArrayHasKey($pattern, $samples, 'Every register-position pattern needs a known-bad sample.');
			$this->assertSame(
				1,
				preg_match($pattern, $samples[$pattern], $matches),
				'Pattern must match its known-bad sample: ' . $pattern
			);
			$this->assertArrayHasKey(
				strtolower($matches[1]),
				self::SUPERSEDED,
				'The sample must capture a slug this guard calls superseded: ' . $pattern
			);
		}
	}//end testEachRegisterPositionPatternStillMatches()

	/**
	 * A per-version register prefix is never typed, it comes from the constant.
	 *
	 * This is the shape the patterns above cannot see, and it was live in this
	 * repository while the guard read green. A per-version register slug is
	 * BUILT rather than typed, so the superseded half of it is a prefix
	 * (`openbuild-`) and never a whole captured slug, and every pattern above
	 * captures whole slugs.
	 *
	 * `ApplicationInsightsService` fell into the gap. It built its fallback as
	 * `sprintf('buildiq-%s-%s', ...)` while every writer of a per-version
	 * register uses {@see ApplicationVersionService::VERSION_REGISTER_PREFIX},
	 * which is `openbuild-` and is frozen there on purpose: the
	 * applicationVersion schema pins `"pattern": "^openbuild-..."`, so a
	 * `buildiq-` register cannot be stored and the fallback named one that could
	 * never exist. The KPI panel then read zero objects, zero files and zero
	 * audit events, which is exactly what a real but empty version looks like.
	 *
	 * Note which direction this runs in. Everything above forbids the OLD name;
	 * this forbids the NEW one where the old is frozen. A guard that only knew
	 * how to say "openbuild is stale" would have called the defect correct.
	 *
	 * @return void
	 */
	public function testNoFileTypesAPerVersionRegisterPrefix(): void {
		$findings = [];
		foreach ($this->sourceFiles() as $relative => $absolute) {
			$lines = file($absolute, FILE_IGNORE_NEW_LINES);
			if ($lines === false) {
				continue;
			}

			foreach ($lines as $index => $line) {
				if (preg_match('/^\s*(\*|\/\/)/', $line) === 1) {
					continue;
				}

				foreach (self::BUILT_REGISTER_PREFIX as $pattern) {
					if (preg_match($pattern, $line, $matches) !== 1) {
						continue;
					}

					$findings[] = sprintf(
						'%s:%d builds a per-version register slug from the typed prefix \'%s\'. Use '
						. 'ApplicationVersionService::VERSION_REGISTER_PREFIX, because a typed prefix is '
						. 'the half of a register slug no rename touches and a wrong one reads zero rows '
						. 'rather than raising.',
						$relative,
						($index + 1),
						$matches[1]
					);
				}
			}
		}//end foreach

		$this->assertSame(
			[],
			$findings,
			"Per-version register prefixes are typed rather than taken from the constant:\n" . implode("\n", $findings)
		);
	}//end testNoFileTypesAPerVersionRegisterPrefix()

	/**
	 * The built-prefix patterns match a typed prefix when one is present.
	 *
	 * Same reasoning as the register-position samples: once the tree is clean
	 * these regexes are only as trustworthy as the last time something proved
	 * they still match. The second sample is the defect line copied verbatim
	 * from `ApplicationInsightsService` as it stood on `development`.
	 *
	 * @return void
	 */
	public function testEachBuiltPrefixPatternStillMatches(): void {
		$samples = [
			'/\$[a-zA-Z0-9_]*[Rr]egister[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]*-)\'\s*\./' => "\t\t\$registerSlug = 'openbuild-' . \$appSlug . '-' . \$versionSlug;",
			'/\$[a-zA-Z0-9_]*[Rr]egister[a-zA-Z0-9_]*\s*=\s*sprintf\(\s*\'([a-zA-Z0-9_-]*-)%s/' => "\t\t\t\t\$registerSlug = sprintf('buildiq-%s-%s', \$appSlug, \$versionSlug);",
			'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]*-)\'\s*\./' => "\t\t\t\t\t'register' => 'openbuild-' . \$appId,",
		];

		foreach (self::BUILT_REGISTER_PREFIX as $pattern) {
			$this->assertArrayHasKey($pattern, $samples, 'Every built-prefix pattern needs a known-bad sample.');
			$this->assertSame(
				1,
				preg_match($pattern, $samples[$pattern], $matches),
				'Pattern must match its known-bad sample: ' . $pattern
			);
			$this->assertStringEndsWith('-', $matches[1], 'The sample must capture a prefix: ' . $pattern);
		}
	}//end testEachBuiltPrefixPatternStillMatches()

	/**
	 * Every PHP file under lib/, keyed by repository-relative path.
	 *
	 * @return array<string, string> Relative path => absolute path.
	 */
	private function sourceFiles(): array {
		$root = dirname(__DIR__, 3);
		$lib = $root . '/lib';

		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			if (($file instanceof SplFileInfo) === false || $file->isFile() === false) {
				continue;
			}

			if ($file->getExtension() !== 'php') {
				continue;
			}

			$path = $file->getPathname();
			$files[ltrim(str_replace($root, '', $path), '/')] = $path;
		}

		return $files;
	}//end sourceFiles()
}//end class
