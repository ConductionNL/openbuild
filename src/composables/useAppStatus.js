import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
// SPDX-License-Identifier: EUPL-1.2
/**
 * useAppStatus — soft capability check: is a sibling Nextcloud app
 * (e.g. `procest` or `openconnector`) installed and enabled on this instance?
 *
 * The builder uses this to degrade gracefully: when the app is absent the
 * dependent action is disabled with a hint (the workflow attach action /
 * case-type list, REQ-PWA-006; the connector origin option, REQ-OCAS-005 —
 * which still offers a manual "enter endpoint path" escape hatch for an
 * unverified binding). The runtime gate itself is handled by CnAppRoot via
 * the manifest `dependencies[]`; this is only the design-time soft check.
 *
 * Strategy: not every app advertises a server capability key, so we use a
 * cheap authenticated probe of a known app route and interpret a non-404/501
 * response as "present" (the app answered, even if with 400/401/403). The
 * server-injected `OC.appswebroots` map, when available, is consulted first as
 * a synchronous positive signal. The result is cached per app id for the
 * session.
 *
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-2.2
 */
import { ref } from 'vue'
import { fleetAppCandidates, resolveFleetAppId } from '../services/fleetAppId.js'

/** @type {Map<string, boolean>} */
const statusCache = new Map()

/**
 * Probe whether an app is available. Returns reactive `{ available, checked }`
 * refs that flip once the async probe resolves.
 *
 * @param {string} appId - the CANONICAL app id, e.g. `dossiq` or
 *   `integriq`. Renamed fleet apps are resolved across every id they answer
 *   to (see `services/fleetAppId.js`), so a caller never has to know which
 *   spelling this instance is on.
 * @param {object} [opts] - options.
 * @param {string} [opts.probePath] - app route to probe when the webroots map
 *   is silent (default `/apps/{appId}/api`).
 * @param {Function} [opts.client] - axios-like client injection for tests.
 * @return {{ available: import('vue').Ref<boolean>, checked: import('vue').Ref<boolean>, check: Function }}
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-005
 */
export function useAppStatus(appId, opts = {}) {
	const client = opts.client || axios
	const available = ref(false)
	const checked = ref(false)

	/**
	 * Run the webroots + probe check.
	 *
	 * @return {Promise<boolean>}
	 */
	async function check() {
		if (statusCache.has(appId)) {
			available.value = statusCache.get(appId)
			checked.value = true
			return available.value
		}
		// 1. Synchronous positive signal from the server-injected app webroots
		// map (present when the app is installed + enabled). Every id the app
		// answers to counts: an instance still on the pre-rename release
		// registers only the old one, and reading just the new name there
		// reports a perfectly healthy app as absent.
		try {
			const roots = (typeof OC !== 'undefined' && OC.appswebroots) || {}
			if (fleetAppCandidates(appId).some((id) => roots[id] !== undefined)) {
				available.value = true
				checked.value = true
				statusCache.set(appId, true)
				return true
			}
		} catch {
			// fall through to probe
		}
		// 2. Cheap authenticated probe.
		const path = opts.probePath || `/apps/${resolveFleetAppId(appId)}/api`
		try {
			await client.get(generateUrl(path))
			available.value = true
		} catch (e) {
			const status = e && e.response && e.response.status
			// 404 / 501 → route does not exist → app absent/disabled.
			// Any other status (incl. 400/401/403) means the app answered.
			available.value = !(
				status === 404
				|| status === 501
				|| status === undefined
			)
		}
		checked.value = true
		statusCache.set(appId, available.value)
		return available.value
	}

	return { available, checked, check }
}

/**
 * Test helper — clear the session status cache.
 *
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-005
 */
export function clearAppStatusCache() {
	statusCache.clear()
}
