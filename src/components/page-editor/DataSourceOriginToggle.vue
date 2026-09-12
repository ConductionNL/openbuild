<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - DataSourceOriginToggle — the `OpenRegister | OpenConnector` origin switch
  - shared by IndexPageEditor and DashboardPageEditor (REQ-OCAS-002). When set
  - to OpenConnector it hosts ConnectorSourcePicker + ConnectorFieldMapper and
  - owns the `dataSource.connector` block on the page/widget config; the
  - register/schema pickers are hidden by the parent via the `origin` it emits.
  -
  - Switching back to OpenRegister with an existing mapping prompts a confirm
  - and clears the connector block on accept.
  -->
<template>
	<div class="ds-origin-toggle">
		<fieldset class="ds-origin-toggle__choice">
			<legend>{{ t('buildiq', 'Data source') }}</legend>
			<label class="ds-origin-toggle__radio">
				<input
					type="radio"
					:checked="origin === 'openregister'"
					value="openregister"
					@change="selectOrigin('openregister')" />
				{{ t('buildiq', 'OpenRegister') }}
			</label>
			<label class="ds-origin-toggle__radio">
				<input
					type="radio"
					:checked="origin === 'openconnector'"
					value="openconnector"
					@change="selectOrigin('openconnector')" />
				{{ t('buildiq', 'OpenConnector') }}
			</label>
		</fieldset>

		<div v-if="origin === 'openconnector'" class="ds-origin-toggle__connector">
			<ConnectorSourcePicker
				:binding="connector"
				@update:endpointPath="onEndpointPath"
				@sampleFetch="onSampleFetch" />
			<ConnectorFieldMapper
				:binding="connector"
				:sample="sample"
				:refreshing="sampleLoading"
				@update:itemsPath="onItemsPath"
				@update:fields="onFields"
				@refetchSample="onRefetch" />
		</div>

		<ConfirmActionDialog
			v-model:open="confirmSwitchOpen"
			:name="t('buildiq', 'Switch data source')"
			:message="
				t(
					'buildiq',
					'Switching to OpenRegister discards the OpenConnector mapping. Continue?',
				)
			"
			:confirmLabel="t('buildiq', 'Confirm')"
			destructive
			@confirm="onConfirmSwitch" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import ConfirmActionDialog from '../../dialogs/ConfirmActionDialog.vue'
import ConnectorFieldMapper from './ConnectorFieldMapper.vue'
import ConnectorSourcePicker from './ConnectorSourcePicker.vue'
import { fleetAppPath } from '../../services/fleetAppId.js'

export default {
	name: 'DataSourceOriginToggle',
	components: { ConnectorSourcePicker, ConnectorFieldMapper, ConfirmActionDialog },
	props: {
		// The page/widget `dataSource` object.
		dataSource: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update:dataSource'],
	data() {
		return {
			sample: null,
			sampleLoading: false,
			confirmSwitchOpen: false,
		}
	},

	computed: {
		/**
		 * Derive the active origin from the binding shape: connector wins,
		 * otherwise default to OpenRegister.
		 *
		 * @return {string}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-002
		 */
		origin() {
			return this.dataSource && this.dataSource.connector
				? 'openconnector'
				: 'openregister'
		},

		/** @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-002 */
		connector() {
			return (this.dataSource && this.dataSource.connector) || {}
		},
	},

	methods: {
		/**
		 * Switch the data-source origin. Switching to OpenConnector seeds an
		 * empty connector block; switching back to OpenRegister with an
		 * existing mapping prompts a confirm and clears the connector block.
		 *
		 * @param {string} next - `openregister` | `openconnector`.
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-3.2
		 */
		selectOrigin(next) {
			if (next === this.origin) {
				return
			}
			if (next === 'openconnector') {
				this.emitConnector({ endpointPath: '', fields: {} })
				return
			}
			// next === openregister
			const hasMapping =
				this.connector
				&& (this.connector.endpointPath
					|| (this.connector.fields
						&& Object.keys(this.connector.fields).length))
			if (hasMapping) {
				// There IS a mapping to lose — ask first. dropConnector() runs
				// only from the dialog's confirm, so cancelling keeps it.
				this.confirmSwitchOpen = true
				return
			}
			this.dropConnector()
		},

		/**
		 * Apply the switch to OpenRegister once the user has confirmed
		 * discarding the OpenConnector mapping.
		 *
		 * @return {void}
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-3.2
		 */
		onConfirmSwitch() {
			this.confirmSwitchOpen = false
			this.dropConnector()
		},

		/**
		 * Remove the connector block and emit the updated data source.
		 *
		 * @return {void}
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-3.2
		 */
		dropConnector() {
			const next2 = { ...this.dataSource }
			delete next2.connector
			this.sample = null
			this.$emit('update:dataSource', next2)
		},

		/**
		 * Merge a partial connector update into the dataSource and emit.
		 *
		 * @param {object} patch - partial connector block.
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-002
		 */
		emitConnector(patch) {
			const connector = { ...this.connector, ...patch }
			this.$emit('update:dataSource', { ...this.dataSource, connector })
		},

		/**
		 * Store the endpoint the source picker settled on.
		 *
		 * @param {string} endpointPath - host-relative OpenConnector endpoint path, already stripped of any scheme/host by ConnectorSourcePicker; `''` when the selection was cleared.
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-002
		 */
		onEndpointPath(endpointPath) {
			this.emitConnector({ endpointPath })
		},

		/**
		 * Store the list-root selector picked in the field mapper.
		 *
		 * @param {string} itemsPath - dot-path, relative to the sample root, of the array the runtime iterates (e.g. `results.items`); `''` means the response root is the list.
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-003
		 */
		onItemsPath(itemsPath) {
			this.emitConnector({ itemsPath })
		},

		/**
		 * Store the whole field map after the mapper added or removed one
		 * entry — ConnectorFieldMapper always emits the complete map, never a
		 * delta.
		 *
		 * @param {{[fieldName: string]: string}} fields - display-field name to dot-path selector, relative to an item of the list (e.g. `{ Title: 'attributes.name' }`).
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-003
		 */
		onFields(fields) {
			this.emitConnector({ fields })
		},

		/**
		 * Fetch a sample payload for the mapping editor.
		 *
		 * @param {string} endpointPath - endpoint to sample.
		 * @return {Promise<void>}
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-3.1
		 */
		async onSampleFetch(endpointPath) {
			if (!endpointPath) {
				return
			}
			this.sampleLoading = true
			try {
				const path = String(endpointPath).replace(/^\/+/, '')
				const url = generateUrl(
					fleetAppPath('integriq', `api/endpoint/${path}`),
				)
				const { data } = await axios.get(url, {
					params: this.connector.query || {},
				})
				this.sample = data && data.data !== undefined ? data.data : data
			} catch {
				this.sample = null
			} finally {
				this.sampleLoading = false
			}
		},

		/** @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-003 */
		onRefetch() {
			this.onSampleFetch(this.connector.endpointPath)
		},
	},
}
</script>

<style scoped>
.ds-origin-toggle__choice {
	border: none;
	margin: 0 0 8px;
	padding: 0;
}

.ds-origin-toggle__radio {
	margin-right: 16px;
}

.ds-origin-toggle__connector {
	border-left: 3px solid var(--color-border);
	padding-left: 12px;
	margin-bottom: 12px;
}
</style>
