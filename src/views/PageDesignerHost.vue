<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  PageDesignerHost — route-level host for the visual page designer
  (/builder/:slug/pages). Resolves the slug to its Application via
  OpenRegister's objects API, hands the stored `manifest` to the
  controlled <PageDesigner> component, and persists edits back with a
  PUT. PageDesigner itself stays a pure controlled component (manifest
  prop in, update:manifest / save-and-preview events out) so it can also
  be embedded as a tab in ApplicationEditor later.

  Version routing (spec `buildiq-version-routing` REQ-OBVR-004):
  Reads `?_version=<versionSlug>` from `$route.query._version`. The
  useApplicationVersion composable resolves the active version. On 404
  (unknown or unauthorised version), the "version not found" UI state is
  rendered (REQ-OBVR-009).

  Tracks issue #26 (PageDesigner used to render with an empty manifest).
-->
<template>
	<div class="page-designer-host">
		<header class="page-designer-host__header">
			<div class="page-designer-host__title">
				<h2>
					{{
						application
							? application.name
							: t('buildiq', 'Page designer')
					}}
				</h2>
				<p v-if="application" class="page-designer-host__subtitle">
					{{
						t(
							'buildiq',
							'Design the pages and menu of this app, then publish from Apps.',
						)
					}}
				</p>
			</div>
			<div class="page-designer-host__actions">
				<router-link
					class="page-designer-host__link"
					:to="{ name: 'VirtualApps' }">
					{{ t('buildiq', 'Back to Apps') }}
				</router-link>
				<a
					v-if="builderUrl"
					class="page-designer-host__link"
					:href="builderUrl">
					{{ t('buildiq', 'Open app') }}
				</a>
				<!-- AI copilot panel toggle (spec ai-copilot REQ-OBAIC-007) —
				     health-gated, hidden for hybrid apps (copilot edits virtual
				     apps only). -->
				<NcButton
					v-if="copilotToggleVisible"
					data-testid="copilot-panel-toggle"
					:pressed="showCopilotPanel"
					@click="showCopilotPanel = !showCopilotPanel">
					{{ t('buildiq', 'AI copilot') }}
				</NcButton>
				<NcButton
					v-if="application"
					variant="primary"
					:disabled="saving"
					@click="save">
					{{
						saving ? t('buildiq', 'Saving…') : t('buildiq', 'Save pages')
					}}
				</NcButton>
			</div>
		</header>

		<div v-if="toast" class="page-designer-host__toast">
			{{ toast }}
		</div>
		<div v-if="error" class="page-designer-host__error">
			{{ error }}
		</div>

		<!-- REQ-OBVR-009: version-not-found state — identical for both "doesn't exist" and "you can't see it" -->
		<NcEmptyContent
			v-if="versionNotFound"
			:name="t('buildiq', 'Version not found')"
			:description="
				t(
					'buildiq',
					'The requested version does not exist or you do not have access to it.',
				)
			" />
		<div v-else-if="loading" class="page-designer-host__loading">
			<NcLoadingIcon :size="44" />
		</div>
		<NcEmptyContent
			v-else-if="!application"
			:name="t('buildiq', 'No app found')"
			:description="
				t('buildiq', 'No app exists for the slug {slug}.', {
					slug: routeSlug,
				})
			" />
		<PageDesigner
			v-else
			:manifest="manifest"
			:slug="routeSlug"
			:sessionKey="sessionKey"
			@update:manifest="onManifestUpdate"
			@saveAndPreview="save" />

		<!-- REQ-PWA-002: Workflows section — attach Procest case types to the
		     app's schemas. Soft-checks Procest availability for graceful absence. -->
		<WorkflowAttachmentsSection
			v-if="application"
			:manifest="manifest"
			:schemas="appSchemas"
			:procestAvailable="procestAvailable"
			@update:manifest="onManifestUpdate"
			@createLinkProperty="onCreateLinkProperty" />

		<!-- REQ-NTS-002: Theme section — pick an NL Design token set for this
		     app. Soft-checks nldesign availability for graceful absence.
		     `preview-available` gates the live-preview toggle on whether the
		     sandboxed live-preview-pane CnAppRoot is mounted (design.md OQ-1 /
		     Decision 3, task 3.3). -->
		<ThemeSection
			v-if="application"
			:manifest="manifest"
			:nldesignAvailable="nldesignAvailable"
			:previewAvailable="livePreviewAvailable"
			@update:manifest="onManifestUpdate"
			@preview="onThemePreview" />

		<!-- REQ-DDT-002: Documents section — attach Docudesk templates to the
		     app's schemas. Soft-checks Docudesk availability for graceful absence. -->
		<DocumentAttachmentsSection
			v-if="application"
			:manifest="manifest"
			:schemas="appSchemas"
			:docudeskAvailable="docudeskAvailable"
			@update:manifest="onManifestUpdate" />

		<!-- REQ-OBSA-001: Scheduled tasks section — author the app's top-level
		     `schedules[]` (cadence + action). Persistence rides the existing
		     ApplicationVersion PUT; no new save path. -->
		<SchedulesSection
			v-if="application"
			:manifest="manifest"
			@update:manifest="onManifestUpdate" />

		<!-- AI copilot side panel (spec ai-copilot REQ-OBAIC-007) — proposes
		     reviewable operations with a manifest diff; approve applies via
		     the same MCP handler layer and refreshes the designer's manifest. -->
		<aside
			v-if="copilotToggleVisible && showCopilotPanel"
			class="page-designer-host__copilot">
			<CopilotPanel :appSlug="routeSlug" @executed="load" />
		</aside>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CopilotPanel from '../components/copilot/CopilotPanel.vue'
import DocumentAttachmentsSection from '../components/DocumentAttachmentsSection.vue'
import SchedulesSection from '../components/SchedulesSection.vue'
import ThemeSection from '../components/ThemeSection.vue'
import WorkflowAttachmentsSection from '../components/WorkflowAttachmentsSection.vue'
import PageDesigner from './PageDesigner.vue'
import { useApplicationVersion } from '../composables/useApplicationVersion.js'
import { useAppStatus } from '../composables/useAppStatus.js'
import { useCopilot } from '../composables/useCopilot.js'
import { useLivePreview } from '../composables/useLivePreview.js'
import {
	reconcileConnectorDependency,
	reconcileDocumentDependency,
	reconcileWorkflowDependency,
	stripDependencyMarker,
} from '../services/manifestDependencies.js'
import { assignUnassignedFieldsToFinalStep } from '../services/manifestValidation/formLogic.js'

const EMPTY_MANIFEST = { version: '1.0.0', menu: [], pages: [] }

export default {
	name: 'PageDesignerHost',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		PageDesigner,
		WorkflowAttachmentsSection,
		ThemeSection,
		DocumentAttachmentsSection,
		SchedulesSection,
		CopilotPanel,
	},

	/**
	 * REQ-NTS-002 (design.md OQ-1 / Decision 3): `livePreview` exposes the
	 * same feature-detected `useLivePreview()` composable PageDesigner.vue's
	 * own live-preview pane uses, so this host's `livePreviewAvailable`
	 * computed can gate the theme dialog's preview toggle on whether that
	 * pane is actually mounted.
	 *
	 * @return {{copilot: object, livePreview: object}}
	 * @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002
	 */
	setup() {
		return { copilot: useCopilot(), livePreview: useLivePreview() }
	},

	data() {
		return {
			loading: true,
			saving: false,
			application: null,
			manifest: { ...EMPTY_MANIFEST },
			toast: '',
			error: '',
			// REQ-OBVR-004: reactive version state from useApplicationVersion.
			applicationVersion: null,
			versionLoading: false,
			versionError: null,
			// REQ-PWA-006: soft capability check for Procest (graceful absence).
			procestAvailable: true,
			// REQ-NTS-005: soft capability check for nldesign (graceful absence).
			nldesignAvailable: true,
			// REQ-NTS-002/OQ-1: the theme dialog's pre-preview baseline —
			// `undefined` when no preview is active; the theme (or null) that
			// was persisted before the FIRST preview mutation this dialog
			// session, restored when the preview reverts (onThemePreview(null)).
			themePreviewBaseline: undefined,
			// REQ-DDT-005: soft capability check for Docudesk (graceful absence).
			docudeskAvailable: true,
			// spec ai-copilot REQ-OBAIC-007: builder copilot panel toggle.
			showCopilotPanel: false,
			// REQ-BUR-004: incremented after each successful save; feeds
			// `sessionKey` so a save re-baselines the designer's undo/redo
			// history (design.md D3). A failed save does NOT increment this.
			saveCounter: 0,
		}
	},

	computed: {
		/**
		 * Whether the AI copilot toolbar toggle should render — health-gated
		 * and hidden for hybrid apps (the copilot edits virtual apps only).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		copilotToggleVisible() {
			const isHybrid = !!(
				this.application && this.application.appType === 'hybrid'
			)
			return this.copilot.isAvailable.value && !isHybrid
		},

		/**
		 * The app's schemas normalized to `[{ slug, title, properties }]` for
		 * the Workflows section's pickers. Reads the manifest's embedded
		 * `schemas` (array or map); empty when none are embedded.
		 *
		 * @return {Array<{slug: string, title: string, properties: object}>}
		 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002
		 */
		appSchemas() {
			const schemas = this.manifest && this.manifest.schemas
			if (Array.isArray(schemas)) {
				return schemas.map((s) => ({
					slug: s.slug || s.id || s.title,
					title: s.title || s.slug,
					properties:
						s.properties || (s.schema && s.schema.properties) || {},
				}))
			}
			if (schemas && typeof schemas === 'object') {
				return Object.keys(schemas).map((slug) => ({
					slug,
					title: schemas[slug].title || slug,
					properties: schemas[slug].properties || {},
				}))
			}
			return []
		},

		/**
		 * The virtual-app slug from the route (/builder/:slug/pages).
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		routeSlug() {
			return this.$route.params.slug || ''
		},

		/**
		 * REQ-OBVR-004: read `?_version=` from the URL query.
		 * The underscore-prefix form avoids colliding with user-defined `?version=` params.
		 *
		 * @return {string|undefined}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		versionSlug() {
			return this.$route.query._version || undefined
		},

		/**
		 * REQ-OBVR-009: true when the version fetch completed with an error (404 etc.)
		 * and no applicationVersion was resolved. Renders the version-not-found UI state —
		 * identical for both "doesn't exist" and "you can't see it" cases.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		versionNotFound() {
			return (
				!this.versionLoading
				&& this.versionError !== null
				&& this.applicationVersion === null
			)
		},

		/**
		 * The Application's canonical UUID (OR puts it at @self.id).
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		applicationUuid() {
			const self = this.application && this.application['@self']
			return (
				(self && self.id)
				|| (this.application && this.application.uuid)
				|| ''
			)
		},

		/**
		 * Full-page link into the virtual app, if it has ever been published.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		builderUrl() {
			if (!this.application) {
				return ''
			}
			const published =
				this.application.currentVersion
				|| this.application.status === 'published'
			return published
				? generateUrl(`/apps/buildiq/builder/${this.application.slug}`)
				: ''
		},

		/**
		 * REQ-BUR-004 / design.md D3: the designer's session-boundary key —
		 * a slug change, a `?_version=` switch, or a successful save each
		 * change this value, which the designer watches to reset its
		 * undo/redo history to the then-current manifest. Fixes the
		 * cross-version undo-bleed at HEAD (the local composable's
		 * `reset()` had no callers).
		 *
		 * @return {string}
		 * @spec openspec/specs/builder-undo-redo/spec.md#req-bur-004
		 */
		sessionKey() {
			return `${this.routeSlug}:${this.versionSlug || ''}:${this.saveCounter}`
		},

		/**
		 * REQ-NTS-002 (design.md OQ-1 / Decision 3, task 3.3): whether the
		 * page-designer live-preview-pane's sandboxed CnAppRoot is available
		 * to retarget a theme preview into. Gates the ThemePickerDialog's
		 * live-preview toggle so it disables with a hint instead of silently
		 * no-op'ing when the pane cannot be mounted.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		livePreviewAvailable() {
			return this.livePreview.available.value
		},
	},

	watch: {
		/**
		 * Observed behaviour of `routeSlug` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		async routeSlug() {
			await this.resolveVersion()
			await this.load()
		},

		/**
		 * A `?_version=` switch must reload the manifest from the newly
		 * resolved ApplicationVersion — pre-existing gap fixed here
		 * (builder-undo-redo): at HEAD this handler only re-resolved the
		 * version and never called `load()`, so the displayed/edited
		 * manifest silently kept showing the previous version after a
		 * version switch. `load()` reads `this.applicationVersion`, so
		 * `resolveVersion()` must run first — and be AWAITED (#174): calling the
		 * two in order without awaiting satisfied the sentence above only on
		 * paper, since resolveVersion() returned while its fetch was still in
		 * flight.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 * @spec openspec/specs/builder-undo-redo/spec.md#req-bur-004
		 */
		async versionSlug() {
			await this.resolveVersion()
			await this.load()
		},
	},

	/**
	 * Observed behaviour of `created` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
	 */
	async created() {
		// REQ-OBVR-004: resolve the active ApplicationVersion on created.
		// NOTE: we do NOT call $router.replace() or $router.push() here — that
		// would strip ?_version= and break bookmarkability (REQ-OBVR-008).
		//
		// AWAITED, in this order, deliberately (#174): load() seeds the editor
		// manifest from the resolved version exactly once, so it must not start
		// until resolveVersion() has actually settled.
		await this.resolveVersion()
		await this.load()
		// REQ-PWA-006: soft-check Procest so the Workflows section degrades
		// gracefully when it is absent.
		const status = useAppStatus('dossiq')
		status.check().then(() => {
			this.procestAvailable = status.available.value
		})
		// REQ-NTS-005: soft-check nldesign so the Theme section degrades
		// gracefully when it is absent.
		const nldesignStatus = useAppStatus('thematiq')
		nldesignStatus.check().then(() => {
			this.nldesignAvailable = nldesignStatus.available.value
		})
		// REQ-DDT-005: soft-check Docudesk so the Documents section degrades
		// gracefully when it is absent.
		const docudeskStatus = useAppStatus('filinq')
		docudeskStatus.check().then(() => {
			this.docudeskAvailable = docudeskStatus.available.value
		})
		// spec ai-copilot REQ-OBAIC-001/007: probe copilot availability
		// (cached per session by useCopilot) so the panel toggle is health-gated.
		this.copilot.checkHealth()
	},

	// REQ-NTS-002/STA-3: no beforeDestroy teardown needed — the live-preview
	// pane's sandboxed CnAppRoot (like every CnAppRoot) tears down its own
	// managed style element on unmount via `useScopedTheme`, and it unmounts
	// along with the rest of this view when the designer is left.

	methods: {
		/**
		 * REQ-NTS-002 (design.md Decision 3, OQ-1): retarget the theme dialog's
		 * live-preview toggle at the sandboxed live-preview-pane CnAppRoot
		 * (PageDesigner.vue's `livePreviewProps.manifest`, which IS this same
		 * `manifest` object by reference) instead of a separate Buildiq-owned
		 * applier. Mutating `runtime.theme` here is picked up by that CnAppRoot
		 * instance's own `useScopedTheme` watcher (REQ-STA-3) with no further
		 * wiring. The FIRST candidate mutation snapshots the pre-preview theme
		 * into `themePreviewBaseline`; reverting (`theme === null`) restores
		 * exactly that snapshot rather than whatever the manifest most recently
		 * held, so cancelling always returns to the previously SAVED theme
		 * (never a different mid-session preview) and clears the baseline.
		 *
		 * @param {?object} theme - the candidate runtime.theme, or null to revert.
		 * @return {void}
		 * @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		onThemePreview(theme) {
			if (theme) {
				if (this.themePreviewBaseline === undefined) {
					this.themePreviewBaseline =
						(this.manifest.runtime && this.manifest.runtime.theme)
						|| null
				}
				this.manifest = this.withRuntimeTheme(this.manifest, theme)
				return
			}
			if (this.themePreviewBaseline === undefined) {
				// No preview was ever started this session — nothing to revert.
				return
			}
			this.manifest = this.withRuntimeTheme(
				this.manifest,
				this.themePreviewBaseline,
			)
			this.themePreviewBaseline = undefined
		},

		/**
		 * Return a manifest copy with `runtime.theme` set (or removed when
		 * `theme` is falsy, so a themeless revert serializes byte-identically).
		 * Mirrors `ThemeSection.withTheme()`'s immutable-replace shape so Vue 2's
		 * reactivity picks up the change through the prop chain into the
		 * live-preview pane.
		 *
		 * @param {object} manifest - the manifest to copy.
		 * @param {?object} theme - the theme object, or null/undefined to clear.
		 * @return {object}
		 * @spec openspec/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		withRuntimeTheme(manifest, theme) {
			const next = { ...manifest }
			const runtime = { ...(next.runtime || {}) }
			if (theme) {
				runtime.theme = theme
			} else {
				delete runtime.theme
			}
			if (Object.keys(runtime).length === 0) {
				delete next.runtime
			} else {
				next.runtime = runtime
			}
			return next
		},

		/**
		 * Delegate one-click link-property creation to the schema designer.
		 * Emitted up from the Workflows dialog; opens the schema designer for
		 * the chosen schema so the builder adds the `zaakUrl` string property
		 * with the designer's own field validation (REQ-PWA-002).
		 *
		 * @param {string} schemaSlug - the schema to add the property to.
		 * @return {void}
		 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-002
		 */
		onCreateLinkProperty(schemaSlug) {
			if (!schemaSlug) {
				return
			}
			// Navigate to the app's schema designer (manifest-driven route at
			// /builder/:slug/schemas) with the target schema + the property to
			// add pre-seeded; the designer adds the string property with its own
			// field validation.
			const base = generateUrl(
				`/apps/buildiq/builder/${this.routeSlug}/schemas`,
			)
			window.location.href = `${base}?schema=${encodeURIComponent(schemaSlug)}&addProperty=zaakUrl`
		},

		/**
		 * Resolve the active ApplicationVersion via useApplicationVersion composable
		 * (REQ-OBVR-004 / REQ-OBVR-005). Called on created and when slug/versionSlug change.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		async resolveVersion() {
			this.versionError = null
			const { applicationVersion, loading, error, ready } =
				useApplicationVersion(this.routeSlug, this.versionSlug)
			this.applicationVersion = applicationVersion.value
			this.versionLoading = loading.value
			const unwatch = this.$watch(
				() => applicationVersion.value,
				(v) => {
					this.applicationVersion = v
				},
			)
			const unwatchLoading = this.$watch(
				() => loading.value,
				(v) => {
					this.versionLoading = v
					if (!v) {
						unwatch()
						unwatchLoading()
						this.versionError = error.value
					}
				},
			)
			// AWAIT the in-flight fetch (#174). Every caller pairs this with load(),
			// which seeds the editor manifest FROM `applicationVersion` exactly once
			// — so returning before it settles left the designer showing "No pages
			// yet" for an app whose manifest had pages, and offering to Save that
			// emptiness over the real one. The watchers above keep the value fresh
			// for later changes; this makes the FIRST read correct.
			await ready
			this.applicationVersion = applicationVersion.value
			this.versionLoading = false
			this.versionError = error.value
		},

		/**
		 * Fetch the Application for the current slug and seed the editor manifest.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.toast = ''
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/buildiq/built-app',
				)
				const { data } = await axios.get(url, { params: { _limit: 100 } })
				const apps =
					data && data.results
						? data.results
						: Array.isArray(data)
							? data
							: []
				const app = apps.find((a) => a && a.slug === this.routeSlug) || null
				this.application = app
				// ADR-002 / REQ-OBPD-009: the manifest now lives on the active
				// ApplicationVersion. Seed from the resolved version's manifest when
				// available, falling back to the Application's manifest for apps that
				// have not yet been migrated to the versioned model.
				const versionManifest =
					this.applicationVersion
					&& this.applicationVersion.manifest
					&& typeof this.applicationVersion.manifest === 'object'
						? this.applicationVersion.manifest
						: null
				const seed =
					versionManifest
					|| (app && app.manifest && typeof app.manifest === 'object'
						? app.manifest
						: null)
				this.manifest = seed
					? JSON.parse(JSON.stringify(seed))
					: { ...EMPTY_MANIFEST }
			} catch (e) {
				this.application = null
				this.error = t('buildiq', 'Failed to load the app: {error}', {
					error: (e && e.message) || String(e),
				})
			} finally {
				this.loading = false
			}
		},

		/**
		 * Receive an edited manifest from the controlled PageDesigner.
		 *
		 * @param {object} next The new manifest.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		onManifestUpdate(next) {
			this.manifest = next
		},

		/**
		 * Persist the edited manifest onto the Application object.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		async save() {
			if (!this.application || !this.applicationUuid || this.saving) {
				return
			}
			this.saving = true
			this.error = ''
			this.toast = ''
			// REQ-PWA-006 / REQ-OCAS-005 / REQ-DDT-005: auto-manage the `procest`
			// dependency against the manifest's workflow attachments, the
			// `openconnector` dependency against its connector bindings, and the
			// `docudesk` dependency against its document attachments, then strip
			// the internal auto-dep marker so it never lands in the persisted
			// manifest.
			this.manifest = stripDependencyMarker(
				reconcileDocumentDependency(
					reconcileConnectorDependency(
						reconcileWorkflowDependency({ ...this.manifest }),
					),
				),
			)
			// REQ-OBFEL-001: the unassigned-fields pool is transient editor
			// state — any form-page field key still unassigned to a step at
			// save time is appended to that page's FINAL step so the written
			// manifest always satisfies the leaf validator's complete-partition
			// rule (no renderer fail-safe exists for an incomplete partition).
			this.manifest = assignUnassignedFieldsToFinalStep(this.manifest)
			try {
				// ADR-002 / REQ-OBPD-009 (design.md Decision 6): persist the manifest
				// onto the active ApplicationVersion when one is resolved. Use a
				// PATCH of just the `manifest` field — a full-object PUT re-validates
				// the whole ApplicationVersion, and its `register` property (the
				// per-version data-register slug, e.g. `buildiq-…`) collides with
				// OpenRegister's reserved `register` routing metadata, so a
				// `{ ...version, manifest }` PUT is rejected ("required property
				// (register) is missing"). PATCH merges the new manifest into the
				// stored object server-side, leaving every untouched field intact —
				// losslessly and without tripping the collision.
				const version = this.applicationVersion
				const versionUuid =
					version
					&& ((version['@self'] && version['@self'].id)
						|| version.uuid
						|| version.id)
				if (version && versionUuid) {
					const url = generateUrl(
						`/apps/openregister/api/objects/buildiq/applicationVersion/${versionUuid}`,
					)
					const { data } = await axios.patch(url, {
						manifest: this.manifest,
					})
					if (data && typeof data === 'object') {
						this.applicationVersion = data
					}
					this.toast = t('buildiq', 'Pages saved.')
					// REQ-BUR-004: a successful save is a session boundary — bump
					// the counter so `sessionKey` changes and the designer resets
					// its undo/redo history to the just-saved manifest.
					this.saveCounter += 1
					return
				}
				const url = generateUrl(
					`/apps/openregister/api/objects/buildiq/built-app/${this.applicationUuid}`,
				)
				const { data } = await axios.put(url, {
					...this.application,
					manifest: this.manifest,
				})
				if (data && typeof data === 'object') {
					this.application = data
				}
				this.toast = t('buildiq', 'Pages saved.')
				// REQ-BUR-004: see the PATCH branch above — same session-boundary bump.
				this.saveCounter += 1
			} catch (e) {
				this.error = t('buildiq', 'Failed to save: {error}', {
					error: (e && e.message) || String(e),
				})
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.page-designer-host {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.page-designer-host__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
}

.page-designer-host__subtitle {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.page-designer-host__actions {
	display: flex;
	align-items: center;
	gap: 12px;
}

.page-designer-host__link {
	text-decoration: underline;
}

.page-designer-host__toast {
	background: var(--color-success);
	color: var(--color-primary-text);
	border-radius: var(--border-radius);
	padding: 6px 12px;
	width: fit-content;
}

.page-designer-host__error {
	background: var(--color-error);
	color: var(--color-primary-text);
	border-radius: var(--border-radius);
	padding: 6px 12px;
	width: fit-content;
}

.page-designer-host__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.page-designer-host__copilot {
	position: fixed;
	top: 0;
	right: 0;
	bottom: 0;
	width: 360px;
	max-width: 100%;
	background: var(--color-main-background);
	border-left: 1px solid var(--color-border);
	padding: 12px;
	z-index: 50;
	box-sizing: border-box;
}
</style>
