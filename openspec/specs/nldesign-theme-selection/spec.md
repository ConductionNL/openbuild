# nldesign-theme-selection Specification

**OpenSpec changes**: [theme-picker-consumes-nldesign](../../changes/archive/2026-07-25-theme-picker-consumes-nldesign/) _(archived 2026-07-25)_

## Purpose
TBD - created by archiving change nldesign-theme-selection. Update Purpose after archive.
## Requirements
### Requirement: REQ-NTS-001 Theme declaration in the v2 manifest

The system SHALL support an optional `theme` object in the manifest v2 `runtime` block. The object SHALL carry:

- `source` (closed enum `nldesign`, required) — theme provider; only nldesign is supported in v1.
- `tokenSet` (string, required, kebab-case id) — the nldesign token-set id (e.g. `rijkshuisstijl`, `amsterdam`).
- `tokenSetName` (string, required) — display snapshot of the set's name at pick time (refreshed on edit).
- `preview` (object, optional) — `{ primaryColor, backgroundColor }` hex snapshots for swatch rendering without an nldesign call.

Buildiq's manifest validation layer SHALL reject: an unknown `source`, a missing or non-kebab-case `tokenSet`, non-hex `preview` colours, and unknown keys. At most one `theme` object exists per app (it is a single object, not an array); per-page themes are not supported in v1. Apps without a theme SHALL serialize byte-identical manifests (purely additive). Codification into the canonical `app-manifest-v2.schema.json` is an external `nextcloud-vue` follow-up, not part of this requirement.

#### Scenario: Valid theme declaration passes validation

<!-- @e2e exclude pure app-side manifest validation, covered by vitest tests/composables/useManifestValidator.spec.js. -->

- **GIVEN** a virtual app manifest
- **WHEN** it declares `runtime.theme: { source: "nldesign", tokenSet: "amsterdam", tokenSetName: "Gemeente Amsterdam", preview: { primaryColor: "#004699", backgroundColor: "#FFFFFF" } }`
- **THEN** the validator pass reports no errors
- **AND** the saved manifest round-trips the block losslessly

#### Scenario: Unknown source is rejected

<!-- @e2e exclude pure app-side manifest validation, covered by vitest tests/composables/useManifestValidator.spec.js. -->

- **WHEN** the manifest declares `runtime.theme.source: "material"`
- **THEN** the validator reports `buildiq.theme.error.unknown-source` against the theme block
- **AND** the Save button is disabled

#### Scenario: Themeless app serializes byte-identically

<!-- @e2e exclude serialization-regression assertion, covered by vitest tests/components/ThemeSection.spec.js. -->

- **GIVEN** a virtual app that has never had a theme set
- **WHEN** the app is saved through a build containing this feature
- **THEN** the persisted manifest is byte-identical to the pre-feature baseline

### Requirement: REQ-NTS-002 Builder UI: visual theme picker

The system SHALL provide `ThemePickerDialog.vue` (standalone dialog in `src/dialogs/` per
the modal-isolation rule), opened from a "Theme" section on the application-detail/designer
surface that shows the current theme (swatches + name) or "Default (Nextcloud)". The dialog
SHALL populate its token-set list via a single call to
`@conduction/nextcloud-vue`'s `useScopedTheme().listTokenSets()`, which itself wraps
nldesign's real non-admin `GET /api/token-sets` catalogue endpoint
(`app-token-set-selection` change) and resolves `[]` on any failure (missing app, network
error, non-2xx, malformed body) rather than throwing. When the resolved list is non-empty,
each entry SHALL render name, design system, and colour swatches (`theming.primary_color`,
`theming.background_color`). When the resolved list is empty, the dialog SHALL render the
existing REQ-NTS-005 disabled-with-hint state (`buildiq.theme.hint.nldesign-missing`) —
there is no other fallback tier. The three-tier fallback this requirement previously
specified (admin `GET /apps/nldesign/settings/tokensets`, a feature-probed non-admin
endpoint, and a validated free-text `css/tokens/<id>.css` input) is REMOVED in full; none
of those calls or that input exist in the dialog. The dialog SHALL offer "Default
(Nextcloud)" to remove the theme (deleting `runtime.theme` entirely) and a **live preview
toggle** that, instead of driving a separate Buildiq-owned applier, mutates the in-flight
manifest object already bound to the page-designer live-preview-pane's sandboxed
`CnAppRoot` instance — that instance re-applies the candidate theme itself per
`scoped-theme-applier` REQ-STA-3. Saving SHALL write/refresh `tokenSet`, `tokenSetName`,
and `preview` snapshots exactly as before. All `NcSelect`s carry `inputLabel`; every
user-visible string uses English-source i18n keys under `buildiq.theme.*` with nl
translations.

#### Scenario: Builder picks a theme from the visual list

- **GIVEN** nldesign is installed and `useScopedTheme().listTokenSets()` resolves a non-empty array
- **WHEN** the builder opens the Theme section, clicks Change, picks "Gemeente Amsterdam" from the list, and saves
- **THEN** the in-flight manifest gains `runtime.theme` with `tokenSet: "amsterdam"` and refreshed name + preview snapshots
- **AND** the Theme section shows the Amsterdam swatches
- **AND** no admin-only or free-text endpoint was ever called

#### Scenario: Empty catalogue renders the absence hint, not a free-text fallback

- **GIVEN** `listTokenSets()` resolves `[]` (nldesign absent, unreachable, or genuinely empty)
- **WHEN** the builder opens the dialog
- **THEN** the change action is disabled with `buildiq.theme.hint.nldesign-missing`
- **AND** no free-text token-set id input is rendered

#### Scenario: Live preview applies via the sandboxed live-preview-pane CnAppRoot and reverts on cancel

- **GIVEN** the dialog is open with "Rijkshuisstijl" selected and the preview toggle on, and the page-designer live-preview-pane's `CnAppRoot` instance is mounted
- **WHEN** the candidate theme changes
- **THEN** the live-preview-pane's bound manifest's `runtime.theme` updates to the candidate
- **AND** that `CnAppRoot` instance re-applies the theme itself (no Buildiq-owned applier call)
- **WHEN** the builder cancels the dialog
- **THEN** the live-preview-pane's manifest reverts to the previously saved theme (or default) and the saved manifest is unchanged

### Requirement: REQ-NTS-003 Runtime: theme application delegates entirely to `CnAppRoot`/`useScopedTheme`

Buildiq SHALL own no runtime theme applier. `src/composables/useAppTheme.js` — the
`:root`-rewrite/inject/teardown implementation this requirement previously specified — is
DELETED in full. `@conduction/nextcloud-vue`'s `CnAppRoot` SHALL carry
`data-nldesign-theme-scope="<appId>"` on its own root element and SHALL self-apply
`manifest.runtime.theme` (fetch, verify-flat-`:root`, rewrite, inject one managed
`<style data-nldesign-theme="<appId>">`, teardown on unmount) per that library's
`scoped-theme-applier` REQ-STA-1/REQ-STA-3, with zero Buildiq-side wiring.
`src/views/BuilderHost.vue`'s nested `CnAppRoot` mount SHALL require no
`data-buildiq-theme-scope` attribute and no `useAppTheme()` call to render correctly; the
same applies to any other `CnAppRoot` mount Buildiq hosts.

#### Scenario: Themed app renders via CnAppRoot's own applier, no Buildiq composable involved

- **GIVEN** a published virtual app whose manifest declares `tokenSet: "amsterdam"`
- **WHEN** an end user opens the app (mounted through `BuilderHost.vue`'s nested `CnAppRoot`)
- **THEN** a single `<style data-nldesign-theme="...">` element exists, scoped by `[data-nldesign-theme-scope="..."]`
- **AND** `--nldesign-color-primary` computed inside the app root is `#004699`
- **AND** no `useAppTheme.js` file, no `data-buildiq-theme-scope` attribute, and no `data-buildiq-theme` style element exist anywhere

#### Scenario: Leaving the app removes the injected style (via CnAppRoot's own teardown)

- **GIVEN** the themed app is open
- **WHEN** the user navigates away and `CnAppRoot` tears down
- **THEN** the managed `<style>` element is removed from the document
- **AND** no Buildiq-owned teardown call was involved

#### Scenario: Missing token asset degrades to default styling (unchanged end-user behaviour)

<!-- @e2e exclude applier 404-degradation now lives entirely in @conduction/nextcloud-vue (scoped-theme-applier); covered against the REAL published dist by tests/composables/nextcloud-vue-useScopedTheme.spec.js, not vitest tests/composables/useAppTheme.spec.js (deleted). -->

- **GIVEN** a manifest referencing a token set whose CSS asset is unreachable
- **WHEN** the app renders
- **THEN** the app renders fully functional in default styling
- **AND** the degrade decision is made entirely inside `useScopedTheme`/`CnAppRoot`, not Buildiq code

### Requirement: REQ-NTS-004 Theme travels with versioning, promotion, and export

Because `runtime.theme` lives in the manifest, it SHALL be captured in ApplicationVersion snapshots, carried by version promotion (all strategies), included verbatim in the exporter's bundled manifest, and applied by the exported app through the same `useAppTheme` path. Builder preview of a non-production version via `?_version=` SHALL render that version's theme (which may differ from production's).

#### Scenario: Promotion carries the theme

<!-- @e2e exclude version-promotion exercised by the existing promoteDestructive/version e2e; theme is a plain manifest field carried losslessly, asserted by applier vitest. -->

- **GIVEN** a development version themed `rijkshuisstijl` and a production version themed `nextcloud`-default
- **WHEN** the development version is promoted to production
- **THEN** the production manifest's `runtime.theme.tokenSet` is `rijkshuisstijl`

#### Scenario: Version preview renders the version's own theme

<!-- @e2e exclude version-routing covered by the existing versionRouting e2e; theme-per-version selection covered by useAppTheme applyTheme(version) vitest. -->

- **GIVEN** the same two versions
- **WHEN** an editor opens the app with `?_version=` targeting the development version
- **THEN** the rendered app is scoped to the Rijkshuisstijl variables while production users continue to see the default

### Requirement: REQ-NTS-005 Capability check and graceful absence of nldesign

At design time, when `useAppStatus('nldesign')` reports the app missing or disabled, the Theme section SHALL render its change action disabled with the i18n hint `buildiq.theme.hint.nldesign-missing` (an existing theme remains visible and removable). At runtime on an instance without nldesign, a themed manifest SHALL render in default styling with one console warning. The theme SHALL NOT add `"nldesign"` to the manifest `dependencies[]` array and SHALL NOT trigger CnAppRoot's dependency gate — theming is a progressive enhancement, never a gate. No buildiq surface SHALL hard-fail, blank, or throw because nldesign is absent.

#### Scenario: Designer degrades when nldesign is missing

- **GIVEN** nldesign is not installed
- **WHEN** the builder opens the Theme section
- **THEN** the change action is disabled with the missing-app hint
- **AND** an existing theme declaration can still be removed

#### Scenario: Themed app still renders without nldesign

- **GIVEN** a published app themed `amsterdam`, on an instance where nldesign was uninstalled after publication
- **WHEN** an end user opens the app
- **THEN** the app renders fully functional in default styling
- **AND** no dependency-gate block is shown

#### Scenario: Saving a theme never edits dependencies

<!-- @e2e exclude dependencies-untouched assertion, covered by vitest tests/components/ThemeSection.spec.js. -->

- **WHEN** the builder saves a manifest after picking a theme
- **THEN** the persisted `dependencies[]` array is unchanged from before the pick

### Requirement: REQ-NTS-006 Integration contract pinned to nldesign's real, published surface

Buildiq's nldesign integration SHALL call exactly: `@conduction/nextcloud-vue`'s
`useScopedTheme()` — `apply`, `teardown`, `listTokenSets`, `evaluateContrast` — and,
through it only, nldesign's `GET /api/token-sets` and `POST /api/contrast/evaluate`
(`app-token-set-selection` change). Buildiq SHALL NOT call
`/apps/nldesign/settings/tokensets`, `/apps/nldesign/settings/tokenset-preview/{id}`, or
any other `/settings/*` nldesign route. Buildiq SHALL NOT fetch
`css/tokens/{tokenSet}.css` directly — that fetch is `useScopedTheme().apply()`'s internal
concern. Buildiq SHALL NOT import nldesign PHP classes or read its tables, and SHALL NOT
bundle a copy of nldesign's token catalogue or WCAG contrast math. The previously-flagged
Codeberg dependency issue (REQ-NTS-006's original text: "requesting a
`#[NoAdminRequired]` read-only token-set list endpoint") is RESOLVED by
`app-token-set-selection` shipping; no further issue-filing task remains open for this
capability.

#### Scenario: Contract surface is closed to the published, non-admin endpoints only

<!-- @e2e exclude static source-tree assertion, pinned by grep during apply/verify (no /settings/tokensets, /settings/tokenset-preview, or css/tokens/*.css reference in src/) and by ThemePickerDialog.spec.js's "never calls any nldesign settings/* route or a direct css/tokens fetch" test; not a browser flow. -->

- **WHEN** the Buildiq source tree is scanned for `/apps/nldesign/` references
- **THEN** every reference resolves inside `node_modules/@conduction/nextcloud-vue`'s `useScopedTheme` implementation, never in Buildiq's own `src/`
- **AND** no `/settings/tokensets` or `/settings/tokenset-preview` call exists anywhere in Buildiq's own code
- **AND** no direct `css/tokens/*.css` fetch exists in Buildiq's own code

### Requirement: REQ-NTS-007 `@conduction/nextcloud-vue` dependency bump gates every deletion

Buildiq's `package.json` `@conduction/nextcloud-vue` dependency SHALL be bumped to the
first published version that exports `useScopedTheme`, wires `CnAppRoot`'s
`runtime.theme` self-application, and carries the `app-manifest-v2.schema.json` 2.20.0+
`$defs/runtimeTheme` field, BEFORE `src/composables/useAppTheme.js` or
`src/services/manifestValidation/theme.js` is deleted. An apply run that finds the
installed `node_modules/@conduction/nextcloud-vue` missing `useScopedTheme` SHALL NOT
proceed with either deletion.

#### Scenario: Deletions are blocked until the bump is confirmed installed

<!-- @e2e exclude one-time apply-time prerequisite check (task 0.2), not a recurring runtime scenario: `node_modules/@conduction/nextcloud-vue/dist/esm/composables/useScopedTheme.js` was verified present BEFORE useAppTheme.js/manifestValidation/theme.js were deleted in this change; there is no ongoing UI to regress-test. -->

- **GIVEN** the installed `@conduction/nextcloud-vue` does not export `useScopedTheme`
- **WHEN** this change is applied
- **THEN** `useAppTheme.js` and `manifestValidation/theme.js` are NOT deleted
- **AND** the apply run reports the unmet prerequisite explicitly

### Requirement: REQ-NTS-008 Warn-only contrast preview, no local WCAG math

Any WCAG contrast display `ThemePickerDialog.vue` offers for a candidate theme SHALL be
sourced only from `useScopedTheme().evaluateContrast(candidates, background)`. Results
SHALL be displayed as informational only and SHALL NEVER block Save — consistent with
nldesign's own non-blocking selection policy. Buildiq SHALL contain no relative-luminance or
contrast-ratio computation of its own; `checkThemeContrast.js` (the `app-theming` change's
duplicate, already removed by the PR #20 revert) SHALL NOT be reintroduced in any form.

#### Scenario: Contrast facts display without blocking save

<!-- @e2e exclude covered by vitest tests/components/ThemePickerDialog.spec.js "shows warn-only contrast facts without disabling Save", exercising the real published useScopedTheme.evaluateContrast() via the vitest stub's subpath re-export; not a browser flow. -->

- **GIVEN** `evaluateContrast()` resolves a result with `pass: false` for the candidate theme
- **WHEN** the dialog renders that result
- **THEN** the ratio/level/pass facts are shown
- **AND** the Save button remains enabled

#### Scenario: No local contrast math exists anywhere in Buildiq

<!-- @e2e exclude static source-tree assertion, pinned by grep during apply/verify (no checkThemeContrast.js, no relative-luminance/contrast-ratio computation in src/); not a browser flow. -->

- **WHEN** the Buildiq source tree is scanned for relative-luminance or contrast-ratio computation
- **THEN** none exists anywhere in `src/`
- **AND** no file named `checkThemeContrast.js` (or functional equivalent) exists
