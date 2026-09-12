import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
// SPDX-License-Identifier: EUPL-1.2
/**
 * useProcestCase — runtime integration with Procest's ZGW API for workflow
 * attachments (REQ-PWA-003, REQ-PWA-004). Buildiq stays a pure API consumer
 * of Procest's existing public endpoints (ADR-022): it starts a case, links it
 * back onto the OR object, and reads case status for display. The handling
 * itself (transitions, assignments, decisions) stays entirely in Procest.
 *
 * Failure-tolerant by design: a failed case start NEVER rolls back the object
 * creation — the caller surfaces a non-blocking warning + a "Start case" retry
 * (REQ-PWA-003). Retry reconciles a half-completed start (an object already
 * carrying a `linkProperty` value, or a case already created for the object's
 * UUID kenmerk) instead of creating a duplicate.
 *
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-003
 */
import { ref } from 'vue'
import { fleetAppPath } from '../services/fleetAppId.js'
import { caseUuidFromReference } from '../services/procestLinks.js'

/**
 * The ZRC (Zaken API) base path on the id dossiq is installed under here.
 *
 * A function, not a constant: `OC.appswebroots` is populated by the page
 * bootstrap and this module is imported by the webpack entry, so reading it
 * at module scope races whatever fills it in.
 *
 * @return {string} - e.g. `/apps/dossiq/api/zgw/zaken/v1`.
 */
function zrc() {
	return fleetAppPath('dossiq', 'api/zgw/zaken/v1')
}

/**
 * Render a `descriptionTemplate` with `{{objectProperty}}` placeholders
 * resolved against the created object's top-level properties.
 *
 * @param {string} template - the description template.
 * @param {object} object - the created OR object.
 * @return {string} - the rendered description.
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-003
 */
export function renderDescription(template, object) {
	if (typeof template !== 'string' || template === '') {
		return ''
	}
	return template.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_, key) => {
		const value =
			(object && object[key])
			?? (object && object['@self'] && object['@self'][key])
		return value === undefined || value === null ? '' : String(value)
	})
}

/**
 * Procest case integration for one workflow attachment.
 *
 * @param {object} opts - options.
 * @param {object} opts.attachment - the `runtime.workflows[]` entry.
 * @param {Function} [opts.client] - axios-like client injection for tests.
 * @return {object} - the integration API (see returned keys).
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-003
 */
export function useProcestCase(opts = {}) {
	const attachment = opts.attachment || {}
	const client = opts.client || axios

	const starting = ref(false)
	const startError = ref(null)
	const caseDetail = ref(null)
	const statusHistory = ref([])
	const loadingDetail = ref(false)
	const detailError = ref(null)
	const noAccess = ref(false)

	/**
	 * The object's UUID used as the case kenmerk so a retry can find an
	 * already-created case instead of duplicating it.
	 *
	 * @param {object} object - the OR object.
	 * @return {string}
	 */
	function objectUuid(object) {
		return (
			(object && object['@self'] && object['@self'].id)
			|| (object && object.uuid)
			|| (object && object.id)
			|| ''
		)
	}

	/**
	 * Search Procest for an existing case carrying this object's UUID kenmerk
	 * (reconcile step) so retry never creates a duplicate.
	 *
	 * @param {object} object - the OR object.
	 * @return {Promise<?object>} - the existing zaak, or null.
	 */
	async function findExistingCase(object) {
		const uuid = objectUuid(object)
		if (!uuid) {
			return null
		}
		try {
			const url = generateUrl(`${zrc()}/zaken/_zoek`)
			const { data } = await client.post(url, { kenmerk: uuid })
			const results = (data && (data.results || data.zaken || data)) || []
			return Array.isArray(results) && results.length ? results[0] : null
		} catch {
			return null
		}
	}

	/**
	 * Start a Procest case for a freshly created object and write the case
	 * reference back onto the object's `linkProperty`. Failure leaves the
	 * object intact and surfaces via `startError`.
	 *
	 * @param {object} object - the created OR object.
	 * @return {Promise<?object>} - the created zaak, or null on failure.
	 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-003
	 */
	async function startCase(object) {
		starting.value = true
		startError.value = null
		try {
			const uuid = objectUuid(object)
			const body = {
				zaaktype: attachment.caseTypeUuid,
				kenmerken: uuid ? [{ kenmerk: uuid, bron: 'buildiq' }] : [],
				omschrijving: renderDescription(
					attachment.descriptionTemplate,
					object,
				),
			}
			const { data: zaak } = await client.post(
				generateUrl(`${zrc()}/zaken`),
				body,
			)
			await writeBack(object, zaak)
			caseDetail.value = zaak
			return zaak
		} catch (e) {
			startError.value = e instanceof Error ? e : new Error(String(e))
			return null
		} finally {
			starting.value = false
		}
	}

	/**
	 * Write the created case URL/UUID back onto the object's linkProperty via
	 * OpenRegister's objects API (ADR-022 — no buildiq controller).
	 *
	 * @param {object} object - the OR object.
	 * @param {object} zaak - the created Procest zaak.
	 * @return {Promise<void>}
	 */
	async function writeBack(object, zaak) {
		const linkValue =
			(zaak && (zaak.url || zaak.uuid || (zaak['@self'] && zaak['@self'].id)))
			|| ''
		if (!attachment.linkProperty || !linkValue) {
			return
		}
		const register =
			(object && object['@self'] && object['@self'].register)
			|| object.register
		const schema =
			(object && object['@self'] && object['@self'].schema)
			|| object.schema
			|| attachment.schema
		const id = objectUuid(object)
		if (!register || !schema || !id) {
			return
		}
		const url = generateUrl(
			`/apps/openregister/api/objects/${register}/${schema}/${id}`,
		)
		await client.put(url, { ...object, [attachment.linkProperty]: linkValue })
	}

	/**
	 * Reconcile-then-start: if the object already links a case (or Procest
	 * already has one for its kenmerk) adopt it; otherwise start a new one.
	 * Idempotent — an already-linked object never starts a duplicate.
	 *
	 * @param {object} object - the OR object.
	 * @return {Promise<?object>} - the linked/created zaak, or null.
	 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-003
	 */
	async function reconcileOrStart(object) {
		const existingRef =
			object && attachment.linkProperty ? object[attachment.linkProperty] : ''
		if (existingRef) {
			// Already linked — adopt, never duplicate.
			await loadDetail(existingRef)
			return caseDetail.value
		}
		const found = await findExistingCase(object)
		if (found) {
			await writeBack(object, found)
			caseDetail.value = found
			return found
		}
		return startCase(object)
	}

	/**
	 * Load case detail + status history for display.
	 *
	 * @param {string} reference - the stored case reference (UUID or URL).
	 * @return {Promise<void>}
	 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004
	 */
	async function loadDetail(reference) {
		const uuid = caseUuidFromReference(reference)
		if (!uuid) {
			return
		}
		loadingDetail.value = true
		detailError.value = null
		noAccess.value = false
		try {
			const { data: zaak } = await client.get(
				generateUrl(`${zrc()}/zaken/${uuid}`),
			)
			caseDetail.value = zaak
			try {
				const { data } = await client.get(
					generateUrl(`${zrc()}/statussen`),
					{
						params: { zaak: uuid },
					},
				)
				const list = (data && (data.results || data)) || []
				statusHistory.value = Array.isArray(list) ? list : []
			} catch {
				statusHistory.value = []
			}
		} catch (e) {
			const status = e && e.response && e.response.status
			if (status === 403) {
				// No access is a distinct, non-error state (REQ-PWA-004).
				noAccess.value = true
			} else {
				detailError.value = e instanceof Error ? e : new Error(String(e))
			}
		} finally {
			loadingDetail.value = false
		}
	}

	return {
		starting,
		startError,
		caseDetail,
		statusHistory,
		loadingDetail,
		detailError,
		noAccess,
		startCase,
		reconcileOrStart,
		findExistingCase,
		loadDetail,
		writeBack,
	}
}
