<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader and pin OCP/NCU PSR-4 prefixes onto it
// so unit tests can resolve interfaces like OCP\IRequest, OCP\AppFramework\Http,
// etc. from the nextcloud/ocp composer package — without booting Nextcloud.
// This mirrors phpstan-bootstrap.php; the unit suite intentionally stays
// out-of-container (integration tests live elsewhere).
/** @var \Composer\Autoload\ClassLoader $autoloader */
$autoloader = require __DIR__ . '/../vendor/autoload.php';
$autoloader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP/');
$autoloader->addPsr4('NCU\\', __DIR__ . '/../vendor/nextcloud/ocp/NCU/');

// Re-pin the Buildiq PSR-4 prefix to the LOCAL lib/. The vendor/ dir
// may be symlinked from a sibling checkout (e.g. running in a git worktree)
// where the optimized classmap points at a stale baseDir. We rebuild the
// classmap entries for every OCA\Buildiq class so they resolve against
// the worktree-local lib/ directory.
$openBuildLib = realpath(__DIR__ . '/../lib');
$autoloader->setPsr4('OCA\\Buildiq\\', [$openBuildLib]);
$rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($openBuildLib));
$classMap = [];
foreach ($rii as $file) {
	if ($file->isFile() === false || $file->getExtension() !== 'php') {
		continue;
	}
	$relative = substr($file->getPathname(), strlen($openBuildLib) + 1);
	$classMap['OCA\\Buildiq\\' . str_replace(['/', '.php'], ['\\', ''], $relative)] = $file->getPathname();
}
$autoloader->addClassMap($classMap);

// OpenRegister types are referenced by hard-typed constructor params on
// our controllers/services. The autoload psr-4 prefix is registered so
// the typehint reflection resolves; the actual implementations are
// replaced with PHPUnit mocks in each test.
$orCandidates = [
	__DIR__ . '/../../openregister/lib',                                                            // git-worktree sibling
	dirname(realpath(__DIR__ . '/../vendor'), levels: 1) . '/../openregister/lib',                  // symlinked vendor → apps-extra/openregister/lib
	'/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/lib',           // dev fallback
];
foreach ($orCandidates as $orPath) {
	if (is_dir($orPath) === true) {
		$autoloader->addPsr4('OCA\\OpenRegister\\', realpath($orPath));
		break;
	}
}

// Final fallback — if OpenRegister sources aren't present at all,
// register a stub set so type-hinted parameters resolve.
require_once __DIR__ . '/stubs/openregister-stubs.php';

// OCP\Files\IRootFolder extends OC\Hooks\Emitter (NC server core, not part
// of the nextcloud/ocp stub package) — see the stub's own docblock for why
// this is needed to mock IRootFolder in this out-of-container run.
require_once __DIR__ . '/stubs/nc-hooks-emitter.stub.php';

// Doctrine constant holders. `IQueryBuilder` evaluates class constants
// referencing `Doctrine\DBAL\ParameterType` at parse time, and
// `IDBConnection::getQueryBuilder()` returns `IQueryBuilder` — so without these,
// `createMock(IDBConnection::class)` dies with `Class "Doctrine\DBAL\ParameterType"
// not found`, raised from INSIDE createMock(), which reads as a broken test
// rather than a missing dependency.
//
// Pre-existing, and it cost 23 errors on this config before this line: every
// test that mocks a query builder. It went unnoticed because CI runs
// `phpunit.xml`, whose bootstrap already loads these, and only `phpunit-unit.xml`
// was missing them — so the local convenience config was the broken one and the
// measured one was fine.
//
// Unconditional here, unlike in `bootstrap.php`, which guards on there being no
// installed Nextcloud. This file is the out-of-container config by construction:
// it never loads `lib/base.php`, so a real doctrine is never coming and the stub
// can never shadow one.
require_once __DIR__ . '/stubs/DoctrineStubs.php';

// OpenRegister's IMcpToolProvider interface ships in PR #1466 (ai-chat
// companion orchestrator). Until that merges, the interface may not be
// loadable in unit-test isolation — load the stub so `implements
// IMcpToolProvider` doesn't blow up at class-load time.
require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';

/**
 * Tell whether a Nextcloud root is an INSTALLED instance, not just a source tree.
 *
 * `lib/base.php` from a source tree that was never installed still declares
 * `OC` and builds `\OC::$server` before it throws "Not installed". That server
 * cannot be undone (`OC::$server` is a typed static), so from then on every
 * `\OC::$server->get()` in the code under test hits a container that knows
 * none of this app's registrations and autowires from scratch; constructor
 * cycles then recurse until memory runs out (19 GB on one openregister test,
 * 2026-09-08). So the decision has to be made BEFORE base.php is loaded, and
 * the only cheap signal is the `installed` flag in config/config.php.
 *
 * @param string $ncRoot Candidate Nextcloud root.
 *
 * @return bool True when config/config.php declares `installed => true`.
 */
function buildiq_nc_root_is_installed(string $ncRoot): bool
{
	$configFile = $ncRoot . '/config/config.php';
	if (is_file($configFile) === false || filesize($configFile) === 0) {
		return false;
	}

	// The config file is a plain `$CONFIG = [...]` script; including it in a
	// closure keeps `$CONFIG` out of the global scope.
	$config = (static function () use ($configFile): array {
		$CONFIG = [];
		try {
			include $configFile;
		} catch (\Throwable) {
			return [];
		}

		if (is_array($CONFIG) === false) {
			return [];
		}

		return $CONFIG;
	})();

	return ($config['installed'] ?? false) === true;
}

// If the surrounding Nextcloud server is present AND installed (we're running
// inside the docker container), boot it so functional tests can talk to OC.
// In a stripped CI environment, or under a bare source tree whose
// config/config.php is empty, we skip: unit tests don't need it, and a bare
// tree's base.php would leave a half-built `OC::$server` behind (see
// buildiq_nc_root_is_installed).
$buildiqNcRoot = dirname(__DIR__, 3);
if (is_file($buildiqNcRoot . '/lib/base.php') === true
	&& getenv('BUILDIQ_SKIP_NC_BOOTSTRAP') === false
) {
	if (buildiq_nc_root_is_installed($buildiqNcRoot) === false) {
		fwrite(
			STDERR,
			sprintf(
				"[buildiq/tests/bootstrap-unit] Nextcloud tree at %s is not installed (config/config.php lacks installed => true); "
				. "skipping lib/base.php and running in pure-unit mode.\n",
				$buildiqNcRoot
			)
		);
	} else {
		try {
			require_once $buildiqNcRoot . '/lib/base.php';
		} catch (\Throwable $e) {
			// The tree IS installed, so the dangerous case this guard exists for
			// (loading a bare source tree) did not happen. base.php still failed
			// part-way.
			//
			// This does NOT abort. `OC::$server` is a typed static, so a half-built
			// container cannot be unset, and aborting was tried: it turned all six
			// PHPUnit legs red on a suite that passes (humaniq, 2026-09-08). The
			// runaway this guard exists for needs an autowiring lookup to reach the
			// poisoned container, this app has none in lib, and phpunit.xml's 2G cap
			// bounds one anyway.
			//
			// So: say plainly that the container is unreliable, and let the pure unit
			// tests run. A container-bound test failing loudly is the intended outcome.
			fwrite(
				STDERR,
				sprintf(
					"[buildiq/tests/bootstrap-unit] Nextcloud at %s could not finish booting (%s).\n"
					. "  \\OC::\$server now holds a HALF-BUILT container and cannot be unset. Pure unit tests\n"
					. "  continue; anything resolving a service from that container is UNVERIFIED by this run.\n",
					$buildiqNcRoot,
					$e->getMessage()
				)
			);
		}

		// Register Test\ namespace for NC test classes.
		$serverTestsLib = $buildiqNcRoot . '/tests/lib/';
		if (is_dir($serverTestsLib) === true) {
			$autoloader->addPsr4('Test\\', $serverTestsLib);
		}
	}
}
