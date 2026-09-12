/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the connector runtime resolver.
 *
 * Spec: openconnector-api-sources (REQ-OCAS-006, REQ-OCAS-004).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import { useConnectorDataSource } from '../../src/composables/useConnectorDataSource.js'
import { clearConnectorCache } from '../../src/services/connectorCache.js'

const flush = () => new Promise((r) => setTimeout(r, 0))

describe('useConnectorDataSource', () => {
	beforeEach(() => clearConnectorCache())

	it('fetches, applies itemsPath + fields and exposes rows', async () => {
		const client = {
			get: vi.fn().mockResolvedValue({
				data: { resultaten: [{ naam: 'Acme', kvkNummer: '123' }] },
			}),
		}
		const { data, loading, load } = useConnectorDataSource({
			appId: 'app1',
			binding: {
				endpointPath: 'kvk/companies',
				itemsPath: 'resultaten',
				fields: { name: 'naam', kvk: 'kvkNummer' },
			},
			client,
		})
		await load()
		await flush()
		expect(loading.value).toBe(false)
		expect(data.value).toEqual([{ name: 'Acme', kvk: '123' }])
		// Same-origin call to Integriq, no extra auth headers. The app
		// segment is resolved, not written: with no webroots map the canonical
		// id is used.
		expect(client.get).toHaveBeenCalledWith(
			'/apps/integriq/api/endpoint/kvk/companies',
			{ params: {} },
		)
		const callOpts = client.get.mock.calls[0][1]
		expect(callOpts.headers).toBeUndefined()
	})

	it('calls the pre-rename app id when that is the one installed', async () => {
		// An instance still on `openconnector` registers only that id. A
		// hardcoded `integriq` 404s there, and fetchRaw's caller degrades a
		// failed fetch to an empty widget, so the data source would silently
		// render nothing.
		globalThis.OC = { appswebroots: { openconnector: '/apps/openconnector' } }
		try {
			const client = { get: vi.fn().mockResolvedValue({ data: {} }) }
			const { load } = useConnectorDataSource({
				appId: 'app1',
				binding: { endpointPath: 'kvk/companies' },
				client,
			})
			await load()
			await flush()
			expect(client.get).toHaveBeenCalledWith(
				'/apps/openconnector/api/endpoint/kvk/companies',
				{ params: {} },
			)
		} finally {
			delete globalThis.OC
		}
	})

	it('treats the response root as a single item when itemsPath is absent', async () => {
		const client = { get: vi.fn().mockResolvedValue({ data: { naam: 'Solo' } }) }
		const { data, load } = useConnectorDataSource({
			appId: 'a',
			binding: { endpointPath: 'single-endpoint', fields: { name: 'naam' } },
			client,
		})
		await load()
		expect(data.value).toEqual([{ name: 'Solo' }])
	})

	it('yields null for unresolved selectors and warns once per field', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const client = {
			get: vi
				.fn()
				.mockResolvedValue({ data: { items: [{ a: 1 }, { a: 2 }] } }),
		}
		const { data, load } = useConnectorDataSource({
			appId: 'a',
			binding: {
				endpointPath: 'list-endpoint',
				itemsPath: 'items',
				fields: { missing: 'nope' },
			},
			client,
		})
		await load()
		expect(data.value).toEqual([{ missing: null }, { missing: null }])
		expect(warn).toHaveBeenCalledTimes(1) // once per field per mount
		warn.mockRestore()
	})

	it('surfaces an error state and supports retry', async () => {
		const client = { get: vi.fn().mockRejectedValue(new Error('404')) }
		const { error, data, retry } = useConnectorDataSource({
			appId: 'a',
			binding: { endpointPath: 'x', fields: { a: 'a' } },
			client,
		})
		await retry()
		expect(error.value).toBeInstanceOf(Error)
		expect(data.value).toBeNull()
	})
})
