// SPDX-License-Identifier: EUPL-1.2
/**
 * fleetAppId — resolve a Conduction fleet app's installed id in the browser.
 *
 * The fleet renamed: `openconnector` became `integriq`, `procest` became
 * `dossiq`, `nldesign` became `thematiq`, `docudesk` became `filinq`. Both
 * spellings are in the field at once — a current instance answers to the new
 * id, one pinned to an older release only to the old one.
 *
 * Nextcloud mounts routes under the id an app actually REGISTERED, so a
 * literal in a URL is a 404 on half the fleet. And a 404 here is invisible:
 * every caller in this app treats a failed request as "that app is not
 * installed" and degrades to an empty list or a disabled control. Nothing
 * errors, nothing is logged, the feature simply stops working. A hard swap to
 * the new literal has the identical failure pointing the other way.
 *
 * So the app segment is RESOLVED, never written. `OC.appswebroots` is keyed by
 * installed app id, which makes membership the same duck-typed question
 * `IAppManager::isInstalled()` answers on the server. This is the browser-side
 * counterpart of `lib/Support/FleetAppId.php`.
 *
 * @spec exclude Infrastructure utility with no feature requirement of its own;
 *  it is exercised through the features that call it.
 */

/**
 * Candidate ids per canonical app, NEWEST FIRST.
 *
 * Order is the contract, as it is in `FleetAppId::CANDIDATES` on the PHP side:
 * the first id the instance actually has wins, so a migrated instance resolves
 * to the new name and one still on an older release falls back to the old one.
 * Adding a rename means PREPENDING, never replacing — dropping an old id
 * re-breaks every instance that has not migrated yet.
 *
 * @type {Readonly<Record<string, string[]>>}
 */
export const FLEET_APP_CANDIDATES = Object.freeze({
	integriq: ['integriq', 'openconnector'],
	filinq: ['filinq', 'docudesk'],
	thematiq: ['thematiq', 'nldesign'],
	stackiq: ['stackiq', 'softwarecatalog'],
	larpinq: ['larpinq', 'larpingapp'],
	dossiq: ['dossiq', 'procest'],
	learniq: ['learniq', 'scholiq'],
	decidiq: ['decidiq', 'decidesk'],
	buildiq: ['buildiq', 'openbuild'],
	keepiq: ['keepiq', 'doriath'],
	humaniq: ['humaniq', 'hrmq'],
	planninq: ['planninq', 'planix'],
	launchpad: ['launchpad', 'mydash'],
})

/**
 * Every id a canonical app may answer to on this instance, newest first.
 *
 * An app with no rename on record yields just itself, so callers can pass any
 * app id without checking whether it is in the map.
 *
 * @param {string} canonical - canonical (new) app name, e.g. `integriq`.
 * @return {string[]} - candidate ids, newest first.
 * @spec exclude Rename-map lookup with no requirement of its own: no feature
 *  asks for it, and its only callers are resolvers. Covered by
 *  tests/services/fleetAppId.spec.js, which pins that an app with no rename
 *  on record yields only itself.
 */
export function fleetAppCandidates(canonical) {
	return FLEET_APP_CANDIDATES[canonical] || [canonical]
}

/**
 * The id a canonical app is installed under on THIS instance.
 *
 * Resolved per call rather than at module load: this module is imported by the
 * webpack entry, and reading a global at import time races whatever populated
 * it.
 *
 * @param {string} canonical - canonical (new) app name, e.g. `integriq`.
 * @return {string} - the installed id, or the canonical name when neither
 *  candidate is present. A request that 404s is a better signal than one that
 *  is never sent.
 * @spec exclude The browser half of the fleet-rename resolver, implementing no
 *  requirement of its own. It exists so REQ-OCAS-005, REQ-PWA-002/003/005 and
 *  REQ-NTS-005 keep holding on both sides of the rename, and it is exercised
 *  through them. Directly covered by tests/services/fleetAppId.spec.js, which
 *  pins BOTH directions: a test that only pinned one could not tell this
 *  apart from a hardcoded literal.
 */
export function resolveFleetAppId(canonical) {
	const candidates = fleetAppCandidates(canonical)
	let roots = {}
	try {
		roots =
			(typeof window !== 'undefined' && window.OC && window.OC.appswebroots)
			|| (typeof OC !== 'undefined' && OC.appswebroots)
			|| {}
	} catch {
		// A missing `OC` global (unit tests, early boot) is not an error: fall
		// through to the canonical name.
		roots = {}
	}
	const found = candidates.find((id) => roots[id] !== undefined)
	return found || candidates[0]
}

/**
 * An app-scoped path built from the id this instance actually has.
 *
 * @param {string} canonical - canonical (new) app name, e.g. `integriq`.
 * @param {string} [suffix] - path after the app segment, with or without a
 *  leading slash.
 * @return {string} - e.g. `/apps/integriq/api/endpoint/kvk/companies`. Not run
 *  through `generateUrl` — callers do that, because some of them build the
 *  suffix from already-encoded input.
 * @spec exclude String assembly over resolveFleetAppId's answer, with no
 *  requirement of its own; the requirements belong to the callers that build
 *  a URL with it. Covered by tests/services/fleetAppId.spec.js, and end to
 *  end by tests/services/procestLinks.spec.js and
 *  tests/composables/useConnectorDataSource.spec.js.
 */
export function fleetAppPath(canonical, suffix = '') {
	const base = `/apps/${resolveFleetAppId(canonical)}`
	if (!suffix) {
		return base
	}
	return `${base}/${String(suffix).replace(/^\/+/, '')}`
}
