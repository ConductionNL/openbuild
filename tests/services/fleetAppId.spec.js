/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the browser-side fleet app-id resolver.
 *
 * The point of these cases is that BOTH directions are failures. Writing the
 * old id breaks a migrated instance; writing the new one breaks an instance
 * still on the published release. Neither breaks loudly, because every caller
 * in this app reads a 404 as "that app is not installed".
 */
import { afterEach, describe, expect, it } from 'vitest'
import {
	FLEET_APP_CANDIDATES,
	fleetAppCandidates,
	fleetAppPath,
	resolveFleetAppId,
} from '../../src/services/fleetAppId.js'

afterEach(() => {
	delete globalThis.OC
})

describe('fleetAppId', () => {
	it('resolves the current id when the instance has it', () => {
		globalThis.OC = { appswebroots: { integriq: '/apps/integriq' } }
		expect(resolveFleetAppId('integriq')).toBe('integriq')
	})

	it('resolves the pre-rename id when that is the one installed', () => {
		globalThis.OC = { appswebroots: { openconnector: '/apps/openconnector' } }
		expect(resolveFleetAppId('integriq')).toBe('openconnector')
	})

	it('prefers the newest id when both are registered', () => {
		globalThis.OC = {
			appswebroots: { openconnector: '/apps/oc', integriq: '/apps/integriq' },
		}
		expect(resolveFleetAppId('integriq')).toBe('integriq')
	})

	it('falls back to the canonical id when neither is installed', () => {
		globalThis.OC = { appswebroots: { files: '/apps/files' } }
		expect(resolveFleetAppId('dossiq')).toBe('dossiq')
	})

	it('survives a missing OC global', () => {
		expect(resolveFleetAppId('thematiq')).toBe('thematiq')
	})

	it('returns the app itself for an id with no rename on record', () => {
		expect(fleetAppCandidates('openregister')).toEqual(['openregister'])
		expect(resolveFleetAppId('openregister')).toBe('openregister')
	})

	it('builds an app-scoped path from the resolved id', () => {
		globalThis.OC = { appswebroots: { procest: '/apps/procest' } }
		expect(fleetAppPath('dossiq', 'api/zgw/zaken/v1')).toBe(
			'/apps/procest/api/zgw/zaken/v1',
		)
		expect(fleetAppPath('dossiq', '/api/zgw/zaken/v1')).toBe(
			'/apps/procest/api/zgw/zaken/v1',
		)
		expect(fleetAppPath('dossiq')).toBe('/apps/procest')
	})

	it('lists the newest id first for every renamed app', () => {
		// Order IS the contract: `resolveFleetAppId` returns the first entry
		// present, so a list that lost its newest-first order would quietly
		// prefer the retired id on a fully migrated instance.
		const expectedFirst = {
			integriq: 'integriq',
			filinq: 'filinq',
			thematiq: 'thematiq',
			dossiq: 'dossiq',
			buildiq: 'buildiq',
		}
		Object.entries(expectedFirst).forEach(([canonical, first]) => {
			expect(FLEET_APP_CANDIDATES[canonical][0]).toBe(first)
			expect(FLEET_APP_CANDIDATES[canonical].length).toBeGreaterThan(1)
		})
	})
})
