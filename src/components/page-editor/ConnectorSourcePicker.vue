<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ConnectorSourcePicker — builder UI that lists OpenConnector endpoints and
  - binds one as the data source of an index page or dashboard widget.
  -
  - Each row shows the endpoint path + its Source display name ONLY — never any
  - credential material (REQ-OCAS-004). Selection writes `endpointPath` to the
  - in-flight binding and emits `sample-fetch` so the mapping editor can pull a
  - sample payload.
  -
  - When OpenConnector is absent (soft check via useAppStatus), the picker
  - disables the live list and offers a manual endpoint-path escape hatch that
  - marks the binding "unverified" (REQ-OCAS-005).
  -->
<template>
	<div class="connector-source-picker">
		<div v-if="appAvailable" class="connector-source-picker__live">
			<NcSelect
				:modelValue="selectedOption"
				:options="endpointOptions"
				:loading="loading"
				:inputLabel="t('buildiq', 'OpenConnector endpoint')"
				:placeholder="t('buildiq', 'Select an endpoint')"
				label="label"
				@update:modelValue="onSelect" />
			<p v-if="error" class="connector-source-picker__error">
				{{ t('buildiq', 'Could not load OpenConnector endpoints.') }}
			</p>
			<p
				v-else-if="!loading && endpointOptions.length === 0"
				class="connector-source-picker__hint">
				{{ t('buildiq', 'No OpenConnector endpoints are configured yet.') }}
			</p>
		</div>

		<div v-else class="connector-source-picker__manual">
			<p
				class="connector-source-picker__hint connector-source-picker__hint--warning">
				{{
					t(
						'buildiq',
						'OpenConnector is not installed or enabled on this instance. You can still author an endpoint path manually, but it cannot be verified here.',
					)
				}}
			</p>
			<label class="connector-source-picker__manual-label">
				{{ t('buildiq', 'Endpoint path') }}
				<input
					type="text"
					:value="manualPath"
					:placeholder="t('buildiq', 'e.g. kvk/companies')"
					@input="onManualInput($event.target.value)" />
			</label>
			<p v-if="manualPath" class="connector-source-picker__unverified">
				{{
					t('buildiq', 'This binding cannot be verified on this instance.')
				}}
			</p>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'
import { useAppStatus } from '../../composables/useAppStatus.js'

export default {
	name: 'ConnectorSourcePicker',
	components: { NcSelect },
	props: {
		// The current `dataSource.connector` block (may be partial / empty).
		binding: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update:endpointPath', 'sample-fetch'],
	/**
	 * Soft capability check for OpenConnector (REQ-OCAS-005).
	 *
	 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-2.2
	 */
	setup() {
		const status = useAppStatus('integriq')
		return { status }
	},

	data() {
		return {
			endpoints: [],
			loading: false,
			error: false,
			manualPath: '',
		}
	},

	computed: {
		/**
		 * Whether OpenConnector is available; assume available until the
		 * async soft-check resolves so the live UI does not flash.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-005
		 */
		appAvailable() {
			return !this.status.checked.value || this.status.available.value
		},

		/**
		 * Endpoint rows projected to NcSelect options — path + Source name
		 * ONLY (REQ-OCAS-004: never a credential).
		 *
		 * @return {Array<{label: string, path: string}>}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-004
		 */
		endpointOptions() {
			return this.endpoints.map((e) => ({
				label: e.sourceName ? `${e.path} — ${e.sourceName}` : e.path,
				path: e.path,
			}))
		},

		/**
		 * The option matching the current binding, for NcSelect's value.
		 *
		 * @return {?object}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-002
		 */
		selectedOption() {
			const current = this.binding && this.binding.endpointPath
			return this.endpointOptions.find((o) => o.path === current) || null
		},
	},

	/** @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-002 */
	async mounted() {
		await this.status.check()
		if (this.status.available.value) {
			await this.fetchEndpoints()
		}
		this.manualPath = (this.binding && this.binding.endpointPath) || ''
	},

	methods: {
		/**
		 * Fetch the configured OpenConnector endpoints. The list is mapped to
		 * a credential-free `{ path, sourceName }` shape; any credential-shaped
		 * field in the upstream payload is dropped here and never reaches the
		 * DOM or component state (REQ-OCAS-004).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-2.1
		 */
		async fetchEndpoints() {
			this.loading = true
			this.error = false
			try {
				// NOT REPOINTED, ON PURPOSE. Integriq no longer serves
				// `GET /api/endpoints`: its `resources` block was deleted with
				// the chain-C OpenRegister cutover and `EndpointsController`
				// now publishes only `handlePath`, `preflightedCors` and
				// `logs` (read at integriq@development, appinfo/routes.php).
				// Integriq's own UI lists endpoints from OpenRegister instead,
				// at `/apps/openregister/api/objects/integriq/endpoint`, which
				// returns a different payload shape AND depends on the
				// register-slug rename this change deliberately does not
				// touch. Correcting the app segment alone would leave the
				// picker just as empty while the diff read as a fix, so the
				// stale path stays until the move to the OpenRegister object
				// API is made and tested as its own change.
				//
				// @stale-fleet-app-id exclude the route was RETIRED, not renamed.
				// Re-verified 2026-09-10 against integriq `development`: commit
				// 496f2025 (2026-05-20) deleted the `resources` block that
				// auto-generated `GET /api/endpoints`, and its own message says
				// re-adding one without restoring the controller methods produces
				// auto-routes that 500 on hit. EndpointsController now publishes
				// only handlePath, preflightedCors and logs, so no integriq path
				// answers this call. Correcting the app segment leaves the picker
				// just as empty while the diff reads as a fix.
				const url = generateUrl('/apps/openconnector/api/endpoints')
				const { data } = await axios.get(url)
				const list = (data && (data.results || data)) || []
				this.endpoints = (Array.isArray(list) ? list : [])
					.map((row) => ({
						path: row.path || row.endpoint || row.slug || row.id || '',
						sourceName:
							row.sourceName
							|| row.source
							|| (row.sourceObject && row.sourceObject.name)
							|| '',
					}))
					.filter((e) => e.path)
			} catch {
				this.error = true
				this.endpoints = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Handle endpoint selection from the live list.
		 *
		 * @param {?object} option - selected NcSelect option.
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-2.1
		 */
		onSelect(option) {
			const path = option && option.path ? option.path : ''
			this.$emit('update:endpointPath', path)
			if (path) {
				this.$emit('sample-fetch', path)
			}
		},

		/**
		 * Handle manual endpoint-path entry (escape hatch when OpenConnector
		 * is absent). Strips any scheme/host so the runtime call stays
		 * same-origin per REQ-OCAS-004.
		 *
		 * @param {string} value - raw input.
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-2.2
		 */
		onManualInput(value) {
			const cleaned = String(value || '')
				.replace(/^https?:\/\/[^/]+/, '')
				.replace(/^\/+/, '')
			this.manualPath = cleaned
			this.$emit('update:endpointPath', cleaned)
			if (cleaned) {
				this.$emit('sample-fetch', cleaned)
			}
		},
	},
}
</script>

<style scoped>
.connector-source-picker__error {
	color: var(--color-error);
	margin: 4px 0 0;
}

.connector-source-picker__hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.connector-source-picker__hint--warning {
	color: var(--color-warning-text, var(--color-warning));
}

.connector-source-picker__unverified {
	color: var(--color-warning-text, var(--color-warning));
	margin: 4px 0 0;
	font-size: 0.9em;
}

.connector-source-picker__manual-label {
	display: block;
	margin-top: 8px;
}

.connector-source-picker__manual-label input {
	width: 100%;
}
</style>
