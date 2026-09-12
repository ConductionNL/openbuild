<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ThemePickerDialog — standalone dialog (modal-isolation rule) to pick an
  - NL Design token set for a virtual app (REQ-NTS-002).
  -
  - List population is a single `useScopedTheme().listTokenSets()` call,
  - which wraps nldesign's real non-admin `GET /api/token-sets` catalogue
  - endpoint and resolves `[]` on ANY failure (missing app, network error,
  - non-2xx, malformed body) — never throws. An empty list renders the
  - existing REQ-NTS-005 disabled-with-hint state; there is no other
  - fallback tier. The old admin list, feature-probe, and validated
  - free-text legs are REMOVED in full (REQ-NTS-002/006).
  -
  - "Default (Nextcloud)" removes runtime.theme. The live-preview toggle
  - mutates the in-flight manifest bound to the page-designer live-preview
  - pane's sandboxed CnAppRoot instance (via the host's onThemePreview),
  - which re-applies the candidate theme itself (scoped-theme-applier
  - REQ-STA-3) — no Buildiq-owned applier call. Disabled with a hint when
  - the live-preview pane itself is unavailable (design.md OQ-1 / Decision
  - 3, task 3.3).
  -
  - Contrast facts are warn-only, sourced from
  - `useScopedTheme().evaluateContrast()`, and never block Save (REQ-NTS-008).
  -->
<template>
	<NcDialog
		:open="open"
		:name="t('buildiq', 'Choose an NL Design theme')"
		size="normal"
		@update:open="$emit('update:open', $event)"
		@closing="onClose">
		<div class="ob-theme-picker">
			<p v-if="!nldesignAvailable" class="ob-theme-picker__warn">
				{{
					t(
						'buildiq',
						'Install or enable the Thematiq app to pick an NL Design theme.',
					)
				}}
			</p>

			<NcSelect
				v-if="nldesignAvailable && tokenSetOptions.length"
				v-model="selectedOption"
				:inputLabel="t('buildiq', 'Token set')"
				:options="tokenSetOptions"
				:loading="loadingList"
				label="label" />

			<p
				v-else-if="nldesignAvailable && !loadingList"
				class="ob-theme-picker__hint">
				{{ t('buildiq', 'No NL Design token sets are available yet.') }}
			</p>

			<!-- swatches + name for the resolved candidate -->
			<div v-if="candidate" class="ob-theme-picker__candidate">
				<span
					class="ob-theme-picker__swatch"
					:style="{
						background:
							candidate.primaryColor || 'var(--color-primary-element)',
					}" />
				<span
					class="ob-theme-picker__swatch"
					:style="{
						background:
							candidate.backgroundColor
							|| 'var(--color-main-background)',
					}" />
				<div class="ob-theme-picker__candidate-meta">
					<strong>{{ candidate.tokenSetName }}</strong>
					<span
						v-if="candidate.designSystem"
						class="ob-theme-picker__candidate-desc"
						>{{ candidate.designSystem }}</span
					>
				</div>
			</div>

			<!-- REQ-NTS-008: warn-only contrast facts, never a save gate. -->
			<ul
				v-if="contrastResults && contrastResults.length"
				class="ob-theme-picker__contrast">
				<li
					v-for="(result, i) in contrastResults"
					:key="i"
					class="ob-theme-picker__contrast-row"
					:class="[
						result.pass
							? 'ob-theme-picker__contrast-row--pass'
							: 'ob-theme-picker__contrast-row--warn',
					]">
					{{
						t('buildiq', '{name}: ratio {ratio}, level {level}', {
							name: result.name,
							ratio: result.ratio,
							level: result.level,
						})
					}}
				</li>
			</ul>

			<label class="ob-theme-picker__toggle">
				<input
					v-model="livePreview"
					type="checkbox"
					:disabled="!previewAvailable"
					@change="onPreviewToggle" />
				{{ t('buildiq', 'Live preview in the designer') }}
			</label>
			<p v-if="!previewAvailable" class="ob-theme-picker__hint">
				{{
					t(
						'buildiq',
						'Live preview is not available in this designer session.',
					)
				}}
			</p>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ t('buildiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="tertiary" @click="onClearTheme">
				{{ t('buildiq', 'Default (Nextcloud)') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!candidate" @click="onSave">
				{{ t('buildiq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { useScopedTheme } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'

export default {
	name: 'ThemePickerDialog',
	components: { NcDialog, NcButton, NcSelect },
	props: {
		open: {
			type: Boolean,
			default: false,
		},

		// The current runtime.theme object (null when none).
		theme: {
			type: Object,
			default: null,
		},

		// Soft capability flag for nldesign.
		nldesignAvailable: {
			type: Boolean,
			default: true,
		},

		// REQ-NTS-002 (design.md OQ-1, task 3.3): whether the live-preview
		// pane's sandboxed CnAppRoot is mounted; gates the preview toggle.
		previewAvailable: {
			type: Boolean,
			default: true,
		},
	},

	emits: ['update:open', 'save', 'clear', 'preview'],
	data() {
		return {
			// REQ-NTS-002/006/STA-2: the single owning primitive this dialog
			// consumes — `listTokenSets()` wraps nldesign's real GET
			// /api/token-sets, `evaluateContrast()` wraps POST
			// /api/contrast/evaluate. Bound once; both resolve to an empty/null
			// "unavailable" shape rather than throwing.
			scopedTheme: useScopedTheme(),
			tokenSets: [],
			loadingList: false,
			selectedOption: null,
			livePreview: false,
			contrastResults: null,
		}
	},

	computed: {
		/** @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		tokenSetOptions() {
			return this.tokenSets.map((s) => ({
				label: s.name || s.id,
				id: s.id,
				name: s.name || s.id,
				designSystem: s.design_system || s.designSystem || '',
				primaryColor: (s.theming && s.theming.primary_color) || '',
				backgroundColor: (s.theming && s.theming.background_color) || '',
			}))
		},

		/**
		 * The resolved theme candidate to save, from the selected catalogue
		 * entry — the only population path left (REQ-NTS-002/006).
		 *
		 * @return {?object} - `{ tokenSet, tokenSetName, primaryColor, backgroundColor, designSystem }`.
		 * @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		candidate() {
			if (!this.selectedOption) {
				return null
			}
			return {
				tokenSet: this.selectedOption.id,
				tokenSetName: this.selectedOption.name,
				primaryColor: this.selectedOption.primaryColor,
				backgroundColor: this.selectedOption.backgroundColor,
				designSystem: this.selectedOption.designSystem,
			}
		},
	},

	watch: {
		/**
		 * @param {boolean} isOpen - The dialog's new `open` state. Opening re-seeds the
		 *   form from the manifest's current theme and (when NlDesign is installed)
		 *   repopulates the selectable themes. Closing reverts the live preview, so
		 *   dismissing the dialog never leaves the app wearing an unsaved theme.
		 * @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		open(isOpen) {
			if (isOpen) {
				this.hydrate()
				if (this.nldesignAvailable) {
					this.populateList()
				}
			} else {
				this.revertPreview()
			}
		},

		/** @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-008 */
		selectedOption() {
			this.evaluateCandidateContrast()
		},
	},

	methods: {
		/**
		 * Seed the form from the current theme when reopening.
		 *
		 * @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		hydrate() {
			this.contrastResults = null
			this.livePreview = false
			if (this.theme && this.theme.tokenSet) {
				this.selectedOption = {
					label: this.theme.tokenSetName || this.theme.tokenSet,
					id: this.theme.tokenSet,
					name: this.theme.tokenSetName || this.theme.tokenSet,
					designSystem: '',
					primaryColor:
						(this.theme.preview && this.theme.preview.primaryColor)
						|| '',

					backgroundColor:
						(this.theme.preview && this.theme.preview.backgroundColor)
						|| '',
				}
			} else {
				this.selectedOption = null
			}
		},

		/**
		 * Populate the picker list via nldesign's real non-admin catalogue
		 * endpoint. `listTokenSets()` resolves `[]` on ANY failure — the
		 * empty-list UI state (REQ-NTS-005 hint) covers "nldesign absent",
		 * "unreachable", and "genuinely empty" identically; no separate
		 * error handling is needed here.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		async populateList() {
			this.loadingList = true
			try {
				this.tokenSets = await this.scopedTheme.listTokenSets()
			} finally {
				this.loadingList = false
			}
		},

		/**
		 * Warn-only contrast facts for the selected candidate's primary colour
		 * against its background — informational only, never a save gate
		 * (REQ-NTS-008). `evaluateContrast()` resolves `null` on any failure
		 * (distinct from "no candidate"), which simply renders no facts.
		 *
		 * `role: 'ui'` (not `'text'`) — nldesign's real endpoint validates role
		 * as `"text"|"ui"` (confirmed against the live endpoint) and applies a
		 * WCAG 1.4.11 non-text (3:1) threshold for `'ui'` vs a 1.4.3 text
		 * (4.5:1) threshold for `'text'`; a token set's primary colour is a
		 * brand/UI accent (buttons, borders), not body text, so `'ui'` is the
		 * correct role here.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-008
		 */
		async evaluateCandidateContrast() {
			this.contrastResults = null
			const c = this.candidate
			if (!c || !c.primaryColor) {
				return
			}
			const background = c.backgroundColor || '#FFFFFF'
			const candidates = [
				{
					name: t('buildiq', 'Primary'),
					value: c.primaryColor,
					role: 'ui',
				},
			]
			this.contrastResults = await this.scopedTheme.evaluateContrast(
				candidates,
				background,
			)
		},

		/**
		 * Toggle live preview: emit the candidate (or null) to the host so it
		 * retargets the sandboxed live-preview-pane CnAppRoot (design.md
		 * Decision 3).
		 *
		 * @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		onPreviewToggle() {
			this.$emit('preview', this.livePreview ? this.buildTheme() : null)
		},

		/**
		 * Revert any live preview (used on cancel/close).
		 *
		 * @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		revertPreview() {
			if (this.livePreview) {
				this.livePreview = false
				this.$emit('preview', null)
			}
		},

		/**
		 * Assemble the runtime.theme object from the resolved candidate.
		 *
		 * @return {?object}
		 * @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-001
		 */
		buildTheme() {
			const c = this.candidate
			if (!c) {
				return null
			}
			const theme = {
				// NOT an app id, and NOT the `thematiq` rename. This is the
				// DESIGN-SYSTEM id, an external Dutch-government standard that
				// is not renaming. nextcloud-vue's `useScopedTheme.apply()`
				// hard-compares `theme.source !== 'nldesign'` and bails, so
				// "correcting" this literal would stop every saved theme from
				// being applied while the diff read as a rename fix. The app id
				// half is resolved separately, by the composable itself.
				source: 'nldesign',
				tokenSet: c.tokenSet,
				tokenSetName: c.tokenSetName,
			}
			const preview = {}
			if (c.primaryColor) {
				preview.primaryColor = c.primaryColor
			}
			if (c.backgroundColor) {
				preview.backgroundColor = c.backgroundColor
			}
			if (Object.keys(preview).length) {
				theme.preview = preview
			}
			return theme
		},

		/** @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		onSave() {
			const theme = this.buildTheme()
			if (!theme) {
				return
			}
			this.revertPreview()
			this.$emit('save', theme)
			this.$emit('update:open', false)
		},

		/** @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		onClearTheme() {
			this.revertPreview()
			this.$emit('clear')
			this.$emit('update:open', false)
		},

		/** @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		onClose() {
			this.revertPreview()
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.ob-theme-picker {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ob-theme-picker__warn {
	color: var(--color-warning-text, var(--color-warning));
}

.ob-theme-picker__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.ob-theme-picker__candidate {
	display: flex;
	align-items: center;
	gap: 8px;
}

.ob-theme-picker__swatch {
	width: 24px;
	height: 24px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	display: inline-block;
}

.ob-theme-picker__candidate-meta {
	display: flex;
	flex-direction: column;
}

.ob-theme-picker__candidate-desc {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.ob-theme-picker__contrast {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 0.9em;
}

.ob-theme-picker__contrast-row--pass {
	color: var(--color-success-text, var(--color-success));
}

.ob-theme-picker__contrast-row--warn {
	color: var(--color-warning-text, var(--color-warning));
}

.ob-theme-picker__toggle {
	display: flex;
	gap: 8px;
	align-items: center;
}
</style>
