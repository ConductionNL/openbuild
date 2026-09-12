/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the Procest deep-link helper.
 *
 * Spec: procest-workflow-attachments (REQ-PWA-005).
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import {
	buildProcestCaseUrl,
	caseUuidFromReference,
} from '../../src/services/procestLinks.js'

const UUID = '11111111-2222-3333-4444-555555555555'

describe('procestLinks', () => {
	afterEach(() => {
		delete globalThis.OC
	})

	it('builds a case URL by UUID on the current id', () => {
		globalThis.OC = { appswebroots: { dossiq: '/apps/dossiq' } }
		expect(buildProcestCaseUrl(UUID)).toBe(`/apps/dossiq/cases/${UUID}`)
	})
	it('falls back to the pre-rename id when that is what is installed', () => {
		// An instance still on the `procest` release registers only that id.
		// Writing `dossiq` unconditionally would 404 there, and the panel reads
		// a 404 as "no case", so the deep link would just stop working.
		globalThis.OC = { appswebroots: { procest: '/apps/procest' } }
		expect(buildProcestCaseUrl(UUID)).toBe(`/apps/procest/cases/${UUID}`)
	})
	it('prefers the current id when both are somehow present', () => {
		globalThis.OC = {
			appswebroots: { dossiq: '/apps/dossiq', procest: '/apps/procest' },
		}
		expect(buildProcestCaseUrl(UUID)).toBe(`/apps/dossiq/cases/${UUID}`)
	})
	it('returns empty for no UUID', () => {
		expect(buildProcestCaseUrl('')).toBe('')
	})
	it('extracts a UUID from a bare UUID reference', () => {
		expect(caseUuidFromReference(UUID)).toBe(UUID)
	})
	it('extracts a UUID from a full zaak URL', () => {
		expect(
			caseUuidFromReference(
				`https://host/apps/procest/api/zgw/zaken/v1/zaken/${UUID}`,
			),
		).toBe(UUID)
	})
	it('returns empty for a reference with no UUID', () => {
		expect(caseUuidFromReference('not-a-uuid')).toBe('')
	})
})
