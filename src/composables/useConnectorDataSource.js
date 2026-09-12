import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
// SPDX-License-Identifier: EUPL-1.2
/**
 * useConnectorDataSource — runtime resolver for a `dataSource.connector`
 * binding in a virtual app's manifest. Consumed by the runtime render path
 * (custom page / widget components registered in the v2 registry) whenever a
 * page or widget declares a connector data source instead of an OpenRegister
 * register/schema binding.
 *
 * Behaviour (REQ-OCAS-006, REQ-OCAS-004):
 *  - Issues `GET /apps/openconnector/api/endpoint/{endpointPath}` with the
 *    binding's `query` parameters, authenticated ONLY by the Nextcloud
 *    session + requesttoken (`@nextcloud/axios` adds the requesttoken). No
 *    external-API credential ever touches buildiq — auth lives in the
 *    OpenConnector Source object (ADR-022, REQ-OCAS-004).
 *  - Applies `itemsPath` then the `fields` dot-path selectors (shared
 *    `selectors.js` grammar) to produce rows (index) or a value object.
 *  - Exposes `{ loading, data, error, isStale, retry }`.
 *  - Caches per binding key with stale-on-error fallback (connectorCache).
 *  - Unresolved selectors yield `null` cells + one console warning per field
 *    per mount; never throws into the render path.
 *
 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-4.1
 */
import { ref } from 'vue'
import { cacheKey, readThrough, ttlToMs } from '../services/connectorCache.js'
import { fleetAppPath } from '../services/fleetAppId.js'
import { extractItems, projectFields } from '../services/selectors.js'

/**
 * Resolve a connector binding into reactive render state.
 *
 * @param {object} opts - options.
 * @param {string} opts.appId - the virtual app id (cache namespacing).
 * @param {object} opts.binding - the `dataSource.connector` block:
 *   `{ endpointPath, method?, query?, itemsPath?, fields, cacheTtl? }`.
 * @param {Function} [opts.client] - axios-like client injection for tests.
 * @return {{ loading: import('vue').Ref<boolean>, data: import('vue').Ref<*>,
 *   error: import('vue').Ref<?Error>, isStale: import('vue').Ref<boolean>,
 *   retry: Function, load: Function }}
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-006
 */
export function useConnectorDataSource(opts = {}) {
	const appId = opts.appId || ''
	const binding = opts.binding || {}
	const client = opts.client || axios

	const loading = ref(false)
	const data = ref(null)
	const error = ref(null)
	const isStale = ref(false)

	// Warn at most once per field per mount for selectors that never resolve.
	const warnedFields = new Set()
	const onMissing = (fieldName, selector) => {
		if (warnedFields.has(fieldName)) {
			return
		}
		warnedFields.add(fieldName)
		// eslint-disable-next-line no-console
		console.warn(
			`[buildiq] connector field "${fieldName}" selector "${selector}" `
				+ `resolved to no value for endpoint "${binding.endpointPath}"`,
		)
	}

	/**
	 * Project a raw response into rows using itemsPath + fields.
	 *
	 * @param {*} raw - the raw response payload.
	 * @return {Array<object>}
	 */
	function project(raw) {
		const items = extractItems(raw, binding.itemsPath)
		return items.map((item) => projectFields(item, binding.fields, onMissing))
	}

	/**
	 * Perform the network call (the cache loader).
	 *
	 * @return {Promise<*>} - the raw response payload.
	 */
	async function fetchRaw() {
		// endpointPath is validated builder-side to be scheme/host-free; the
		// runtime call is always same-origin to OpenConnector.
		const path = String(binding.endpointPath || '').replace(/^\/+/, '')
		const url = generateUrl(fleetAppPath('integriq', `api/endpoint/${path}`))
		const response = await client.get(url, {
			params: binding.query || {},
		})
		return response && response.data !== undefined ? response.data : response
	}

	/**
	 * Load (read-through cache) and project into render state.
	 *
	 * @return {Promise<void>}
	 */
	async function load() {
		if (!binding.endpointPath) {
			error.value = new Error('connector binding missing endpointPath')
			return
		}
		loading.value = true
		error.value = null
		warnedFields.clear()
		const key = cacheKey(appId, binding.endpointPath, binding.query)
		const ttlMs = ttlToMs(binding.cacheTtl)
		try {
			const { data: raw, isStale: stale } = await readThrough(
				key,
				ttlMs,
				fetchRaw,
			)
			data.value = project(raw)
			isStale.value = stale
		} catch (e) {
			error.value = e instanceof Error ? e : new Error(String(e))
			data.value = null
			isStale.value = false
		} finally {
			loading.value = false
		}
	}

	/**
	 * Retry action for the error state.
	 *
	 * @return {Promise<void>}
	 */
	function retry() {
		return load()
	}

	return { loading, data, error, isStale, retry, load }
}
