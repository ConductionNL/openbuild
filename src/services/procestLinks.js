// SPDX-License-Identifier: EUPL-1.2
/**
 * procestLinks — single source of truth for deep links into Procest's case
 * view (REQ-PWA-005). Every "Open case in Procest" affordance MUST go through
 * `buildProcestCaseUrl` so a Procest frontend route change is a one-line fix
 * here, not a fan-out across components.
 *
 * The exact Procest route is verified against the deployed app during apply;
 * the current form targets Procest's zaak detail by UUID. If Procest changes
 * its route, update only this module.
 *
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-005
 */
import { generateUrl } from '@nextcloud/router'
import { fleetAppPath } from './fleetAppId.js'

/**
 * Build the deep link to a Procest case (zaak) detail view by UUID.
 *
 * @param {string} caseUuid - the Procest zaak UUID.
 * @return {string} - the same-origin URL into Procest, or '' when no UUID.
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-005
 */
export function buildProcestCaseUrl(caseUuid) {
	if (!caseUuid) {
		return ''
	}
	return generateUrl(
		fleetAppPath('dossiq', `cases/${encodeURIComponent(caseUuid)}`),
	)
}

/**
 * Extract a zaak UUID from a stored case reference, which may be a bare UUID
 * or a full zaak URL (ZRC returns a `url` like `.../zaken/{uuid}`).
 *
 * @param {string} reference - the value stored on the object's linkProperty.
 * @return {string} - the UUID, or '' when none can be parsed.
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-005
 */
export function caseUuidFromReference(reference) {
	if (typeof reference !== 'string' || reference === '') {
		return ''
	}
	const uuidRe =
		/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/
	const match = reference.match(uuidRe)
	return match ? match[0] : ''
}
