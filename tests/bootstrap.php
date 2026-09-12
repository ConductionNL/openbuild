<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

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

// The Nextcloud root this checkout sits under (apps-extra/buildiq/), or null
// when there is none or it is only a bare source tree. Decided ONCE, up here,
// because two later blocks depend on the same answer: whether the Doctrine
// placeholders are needed, and whether lib/base.php gets loaded at all.
$buildiqNcRoot = null;
$buildiqNcCandidate = dirname(__DIR__, 3);
if (is_file($buildiqNcCandidate . '/lib/base.php') === true) {
	if (buildiq_nc_root_is_installed($buildiqNcCandidate) === true) {
		$buildiqNcRoot = $buildiqNcCandidate;
	} else {
		fwrite(
			STDERR,
			sprintf(
				"[buildiq/tests/bootstrap] Nextcloud tree at %s is not installed (config/config.php lacks installed => true); "
				. "skipping lib/base.php and running in pure-unit mode.\n",
				$buildiqNcCandidate
			)
		);
	}
}

// `OCP\Files\IRootFolder` extends BOTH `OCP\Files\Folder` and Nextcloud CORE's
// `OC\Hooks\Emitter`, which is not part of the `nextcloud/ocp` package. Loading
// IRootFolder without it fatals mid-autoload, so the interface never becomes
// defined and every `createMock(IRootFolder::class)` fails with a misleading
// "Class or interface OCP\Files\IRootFolder does not exist" — 8 of this suite's
// errors, from one missing symbol.
//
// The stub already existed and was wired into `bootstrap-unit.php`, but
// `phpunit.xml` boots THIS file, so it never loaded here. It must come before
// the OCP resolver below can be triggered, and it is `interface_exists`-guarded
// so a real in-container Nextcloud still wins.
// Doctrine placeholders, loaded BEFORE anything can mock an OCP DB interface.
// IQueryBuilder evaluates class constants referencing Doctrine\DBAL\ParameterType
// at parse time, and IDBConnection::getQueryBuilder() returns IQueryBuilder — so
// without these, createMock(IDBConnection::class) dies with
// `Class "Doctrine\DBAL\ParameterType" not found`, raised from inside
// createMock(), which reads as a broken test rather than a missing dependency.
// Only the two CONSTANT HOLDERS are stubbed: stubbing Doctrine\DBAL\Connection
// as well fatals a full-server run, because OC\DB\Connection extends it.
//
// ONLY WHEN THERE IS NO REAL NEXTCLOUD. The stub's own docblock explains that
// class_exists() cannot protect it — at the moment this file runs the genuine
// Doctrine is not yet reachable, so the guard passes and the STUB WINS THE NAME
// for the rest of the process. In a full-server leg that is not a harmless
// shadow: Nextcloud's AppConfig reads ArrayParameterType::BINARY while loading
// app versions, and the stub does not have it, so every PHPUnit cell died in the
// bootstrap with "Undefined constant Doctrine\DBAL\ArrayParameterType::BINARY"
// — before a single test ran, and long before any code this app owns.
//
// Completing the constant list would fix that one symbol and leave the next one
// waiting. The real fix is not to shadow a class that is genuinely present: when
// an installed Nextcloud is about to be booted below, the server ships
// doctrine/dbal in 3rdparty and the stub has nothing to add. A bare source tree
// does NOT count: its base.php is never loaded (see $buildiqNcRoot), so the
// placeholders are needed there exactly as they are in CI.
if ($buildiqNcRoot === null) {
	require_once __DIR__ . '/stubs/DoctrineStubs.php';
}
require_once __DIR__ . '/stubs/nc-hooks-emitter.stub.php';

// vendor/nextcloud/ocp doesn't ship an autoload entry — it's intended as
// a PHPStan scan-only dependency. For unit tests outside the docker
// container we want OCP\* stubs loadable so MockBuilder can resolve them.
// Register a PSR-4 path resolver for the OCP namespace pointing at the
// stubs.
$ocpStubs = __DIR__ . '/../vendor/nextcloud/ocp/OCP';
if (is_dir($ocpStubs)) {
	spl_autoload_register(static function (string $class) use ($ocpStubs): void {
		if (str_starts_with($class, 'OCP\\') === false) {
			return;
		}

		$relative = substr($class, strlen('OCP\\'));
		$path = $ocpStubs . '/' . str_replace('\\', '/', $relative) . '.php';
		if (file_exists($path)) {
			require_once $path;
		}
	});
}

// THE OpenRegister CONTRACT INTERFACES, OPTED INTO RATHER THAN AUTOLOADED.
//
// conduction/hydra-gates used to claim `OCA\OpenRegister\Contract\` as a
// RUNTIME psr-4 prefix, so every consumer got these interfaces implicitly. That
// prefix is LONGER than openregister's own `OCA\OpenRegister\` -> `lib/`, and
// PSR-4 is longest-prefix-wins, so whichever app's autoloader registered first
// defined OpenRegister's contract for the whole process (ConductionNL/.github#531).
//
// Asking whether the interface is RESOLVABLE is order-independent, which
// appending a fallback autoloader is not: spl_autoload_register appends
// relative to registration order, and registration order across independently
// loaded apps is exactly what nobody controls.
//
// Both are needed here, before the stub file below: it declares
// `class ObjectEntity ... implements \OCA\OpenRegister\Contract\ObjectEntityInterface`,
// so the interface must exist by then or PHP fatals in the bootstrap itself.
// Same shape as the OCP stub guards already used across the fleet.
//
// ONE SOURCE. The hydra-gates package ships these contracts in
// `hydra-gates/contracts/`, and gate 67 (`openregister-contract-parity`)
// requires that copy and openregister's own `lib/Contract/` to be byte
// identical, so the vendored file is the definition openregister declares.
//
// This used to list openregister's own tree beside the package as a fallback.
// `RegisterSlugResolverInterface` and `RegisterSlugResolution` were published to
// the package by ConductionNL/.github#739, which merged after v1.17.0 was cut,
// so for a while they were on main and in no release and the constraint bump
// alone did not make them loadable. v1.18.0 carries all four, measured on this
// checkout, so the fallback covers nothing and is gone.
//
// `RegisterSlugResolution` is a CLASS, not an interface, so the guard has to ask
// both questions. Asking only interface_exists() would re-require a file that is
// already loaded, and a duplicate declaration is a fatal, not a no-op.
$buildiqContractDir = __DIR__ . '/../vendor/conduction/hydra-gates/hydra-gates/contracts';

foreach ([
	'ObjectEntityInterface',
	'ObjectServiceInterface',
	'RegisterSlugResolution',
	'RegisterSlugResolverInterface',
] as $contract) {
	$fqcn = '\\OCA\\OpenRegister\\Contract\\' . $contract;
	if (interface_exists($fqcn) === true || class_exists($fqcn) === true) {
		continue;
	}

	$shipped = $buildiqContractDir . '/' . $contract . '.php';
	if (file_exists($shipped) === true) {
		require_once $shipped;
	}
}

// OpenRegister types are referenced by hard-typed constructor parameters in
// controllers, services, repair steps and listeners.  When the real
// OpenRegister sources are not on the autoload path (CI / out-of-container)
// load the minimal stub set so PHPUnit's MockBuilder can resolve the types.
// The stubs are guarded by class_exists() so they are a no-op when the real
// classes ARE available (in-container run).
require_once __DIR__ . '/stubs/openregister-stubs.php';

// Same guard for the IMcpToolProvider interface which ships with OR but may
// not be present until OR#1466 merges.
require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';

// Bootstrap Nextcloud if an INSTALLED one is available. Inside the docker
// container we'll get the full NC runtime; outside (CI / local dev / a bare
// source tree) we fall back to the vendor/nextcloud/ocp stubs and run only the
// pure-unit subset.
if (!defined('OC_CONSOLE') && $buildiqNcRoot !== null) {
	try {
		require_once $buildiqNcRoot . '/lib/base.php';

		$ncAutoload = $buildiqNcRoot . '/tests/autoload.php';
		if (file_exists($ncAutoload)) {
			require_once $ncAutoload;
		}

		if (class_exists(\OC_App::class)) {
			\OC_App::loadApps();
			\OC_App::loadApp('buildiq');
		}

		if (class_exists(\OC_Hook::class)) {
			\OC_Hook::clear();
		}
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
				"[buildiq/tests/bootstrap] Nextcloud at %s could not finish booting (%s).\n"
				. "  \\OC::\$server now holds a HALF-BUILT container and cannot be unset. Pure unit tests\n"
				. "  continue; anything resolving a service from that container is UNVERIFIED by this run.\n",
				$buildiqNcRoot,
				$e->getMessage()
			)
		);
	}
}
