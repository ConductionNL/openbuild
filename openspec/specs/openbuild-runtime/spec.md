---
retrofit_extensions:
  - REQ-OBR-MCP-001
  - REQ-OBR-MCP-002
  - REQ-OBR-MCP-003
  - REQ-OBR-MCP-004
---

# buildiq-runtime Specification

**OpenSpec changes**: [public-forms-runtime](../../changes/archive/2026-07-23-public-forms-runtime/) _(archived 2026-07-23)_, [fix-builder-nav-route-and-identity](../../changes/fix-builder-nav-route-and-identity/)

**Status**: in-progress

## Purpose

The Buildiq runtime: foundational shell + per-slug manifest serving, plus every
delta later archived chains have layered on the same capability. Defines the
slug-keyed manifest endpoint backed by the `BuiltAppRoute` index, the nested
`CnAppRoot` mount under `/builder/:slug/*` (inner router resolves path segments
after the slug), the seeded hello-world Application (idempotent), and the tabbed
Application editor (Design / Raw JSON). Layers added by subsequent archives —
schema-designer routes mounted under the outer router (orthogonal to the
runtime-preview mount), a Publish action with version-snapshot toast, a
draft-vs-published indicator with "modified since last publish" marker, a
VersionHistory panel + audit-clean rollback, a ManifestDiff side-by-side view,
and the RBAC overlay (403-before-payload gate on the manifest endpoint,
group-filtered list view, role-gated editor controls, group set provided via
`IInitialState` per ADR-004) — all live here alongside the ApplicationCard
icon / no-Live-chip refinement.

## Requirements

### Requirement: Manifest endpoint per virtual-app slug

The system SHALL expose
`GET /index.php/apps/buildiq/api/applications/{slug}/manifest`
backed by `ApplicationsController::getManifest`. The endpoint SHALL
resolve `{slug}` to an `Application` via the `BuiltAppRoute` index,
return the stored `manifest` JSON blob with `Content-Type:
application/json`, and respond `200` on success or `404` when no
matching published Application exists in the caller's organisation
scope. The endpoint SHALL be registered via `appinfo/routes.php`
(ADR-016) with `#[NoAdminRequired]` and a route-auth posture that
treats it as authenticated-user-readable.

@e2e exclude pure-backend REST endpoint; manifest fetch, 404 for unknown slug and auth posture are verified by Newman, in tests/integration/buildiq.postman_collection.json (Manifest endpoint folder) and tests/integration/buildiq-api-contract.postman_collection.json (2. Manifest). No separate UI surface

**ID:** REQ-OBR-001

#### Scenario: Endpoint returns the stored manifest

- **WHEN** an authenticated user requests
  `/index.php/apps/buildiq/api/applications/hello-world/manifest`
- **AND** a published `Application` with `slug: hello-world` exists
  in their organisation
- **THEN** the response is `200 application/json` and the body is the
  exact `manifest` blob persisted on the Application

#### Scenario: Unknown slug returns 404

- **WHEN** an authenticated user requests the manifest for a slug
  that has no matching `BuiltAppRoute`
- **THEN** the response is `404` with a JSON error body

### Requirement: A published virtual app runs in its own standalone shell

`/builder/{slug}` (no sub-path) SHALL be served by
`DashboardController::builder`, which boots the `src/builder.js` webpack entry.
That entry SHALL mount the virtual app's **own** `CnAppRoot` as the
**top-level** shell, with its menu, pages and router built from
`GET /api/applications/{slug}/manifest`.

The Buildiq SPA SHALL NOT wrap it. Rendering the app inside Buildiq's shell
nests one `NcContent` in another — double chrome — and, worse, shares Buildiq's
router, which holds none of the app's page routes, so page content never
resolves.

**ID:** REQ-OBR-002

#### Scenario: A published app renders in its own shell, not inside Buildiq's

- **WHEN** an authenticated user navigates to
  `/index.php/apps/buildiq/builder/hello-world`
- **THEN** the index page declared in the `hello-world` manifest renders
- **AND** the app's own navigation is the one present
- **AND** Buildiq's own shell navigation is **not** rendered around it

> **Rewritten 2026-08-25.** This requirement previously mandated the opposite:
> a **nested** `CnAppRoot` inside the Buildiq shell, with the outer `CnAppNav`,
> header and chrome staying visible, mounted by `BuilderHost.vue`.
>
> The product deliberately moved away from that, and `src/builder.js` states
> why in its own header — double chrome, and a shared router that cannot resolve
> the app's pages. `appinfo/routes.php` maps the bare `/builder/{slug}` to
> `dashboard#builder`, a standalone page with its own webpack entry.
> `BuilderHost.vue` still exists and is still registered, but only ever mounts
> for builder sub-paths that fall through to the SPA catch-all — never for the
> runtime route this scenario navigates to.
>
> So `[data-testid="buildiq-builder-host"]` was genuinely absent while the app
> itself rendered correctly, and the e2e asserting the nested mount could not
> pass. It was left `test.skip` with a note recommending precisely this rewrite;
> this is that rewrite. The requirement now describes what ships, and the
> scenario asserts the property that actually matters — one shell, not two.

### Requirement: A virtual app's own routes resolve on its own router

The standalone runtime entry SHALL build its router from the app's own
manifest, so that the app's index, detail, form and dashboard pages resolve
from within its shell — by navigation inside the app, and by deep link.

Designer surfaces (`/builder/{slug}/pages`, `/builder/{slug}/schemas`) SHALL
remain in the Buildiq SPA and are NOT part of the virtual app's router.

**ID:** REQ-OBR-003

#### Scenario: A detail page inside a virtual app resolves

- **WHEN** an authenticated user opens a row from the app's index page
- **THEN** the app's `detail` page for that schema renders

> **Rewritten 2026-08-25**, with REQ-OBR-002. The previous wording described an
> **outer** Buildiq router forwarding an opaque path suffix to an **inner**
> router — machinery that belongs to the nested-mount design the product
> abandoned. There is no outer router in this path any more: the standalone
> entry owns the only router, built from the app's own manifest.
>
> The scenario is now written around opening a row rather than deep-linking a
> hardcoded all-zero uuid, because that uuid never existed in any fixture — a
> detail of the old scenario that guaranteed it could not be asserted even if
> the architecture had matched.
- **AND** the detail page renders for the requested object id

### Requirement: Seeded hello-world Application exercises index, detail, form

The repair step SHALL seed a single Application with `slug:
hello-world`, `status: published`, a `manifest` declaring at least
one `type: index`, one `type: detail`, and one `type: form` page over
a seeded `hello-message` schema in the Buildiq register, plus three
sample `hello-message` objects. The seed SHALL be idempotent (safe to
re-run) and SHALL only run when no `Application` with `slug:
hello-world` exists in the system organisation scope.

**ID:** REQ-OBR-004

#### Scenario: Fresh install renders the seeded virtual app

- **WHEN** the Buildiq app is installed on a fresh Nextcloud
- **AND** an administrator navigates to
  `/index.php/apps/buildiq/builder/hello-world`
- **THEN** the seeded index page lists the three sample
  `hello-message` objects
- **AND** opening one of them renders the seeded detail page
- **AND** the seeded form page is reachable from the index actions

#### Scenario: Re-running the repair step is idempotent

- **WHEN** the repair step runs a second time on an already-seeded
  install
- **THEN** no duplicate `hello-world` Application is created
- **AND** no duplicate `hello-message` objects are created

### Requirement: Textarea manifest editor saves to the Application object

The Buildiq shell SHALL render a **tabbed Application editor** for
the `manifest` field of an `Application` object, composed of two
sibling tabs sharing one in-flight manifest state:

1. **"Design"** (default tab) — mounts the visual `PageDesigner.vue`
   shipped by the `buildiq-page-designer` capability. The designer
   authors the manifest through structured per-page-type sub-editors
   and a menu-tree editor; see the `buildiq-page-designer`
   capability spec for its full requirements.
2. **"Raw JSON"** — the original JSON `<textarea>`-based editor (the
   integrator-only fallback path).

Both tabs SHALL:
(a) load the current `manifest` blob from OR via the standard OR REST
API at view mount; (b) validate the edited blob client-side using
`validateManifest` from `@conduction/nextcloud-vue` and refuse to save
when validation fails, surfacing the failing JSON path in the
shared error surface; (c) on successful validation, PUT the updated
Application back to OR via the same REST endpoint used by spec #1.
The shared in-flight manifest state SHALL persist across tab switches
without saving, so edits made in one tab are visible in the other on
tab change.

**ID:** REQ-OBR-005

#### Scenario: Invalid edit is blocked before save

- **WHEN** an integrator pastes a manifest blob missing the required
  `pages` array into the Raw JSON tab and clicks Save
- **THEN** the shared error surface cites the missing `pages` field
- **AND** no PUT request is sent to OR
- **AND** the Design tab disables its inputs and surfaces the parse
  / validation error in its side-panel error list

#### Scenario: Valid edit persists and reloads

- **WHEN** an integrator pastes a valid manifest blob in the Raw JSON
  tab and clicks Save
- **THEN** the editor sends a PUT to OR's Application endpoint
- **AND** reloading the editor surfaces the new manifest in both tabs

#### Scenario: Default tab is Design

- **WHEN** the user opens the Application editor view for an existing
  Application
- **THEN** the Design tab is selected by default
- **AND** the Raw JSON tab is accessible as a sibling tab

#### Scenario: Unsaved edits survive a tab switch

- **WHEN** the user edits a page title in the Design tab and switches
  to the Raw JSON tab without saving
- **THEN** the textarea's JSON content reflects the unsaved page title
- **AND** the dirty indicator persists across the tab switch

### Requirement: Schema designer routes mounted under the builder host

The Buildiq frontend router SHALL register two new routes under the
existing `/builder/:slug/*` host (from `bootstrap-buildiq`
REQ-OBR-002 / REQ-OBR-003):

- `/index.php/apps/buildiq/builder/:slug/schemas` — schema list.
- `/index.php/apps/buildiq/builder/:slug/schemas/:schemaId` —
  schema detail / designer.

Both routes SHALL be rendered by `src/views/SchemaDesigner.vue` and
SHALL be registered under the Buildiq **outer** router (not the
nested-CnAppRoot inner router). The Schemas surface is a meta-tool
that authors the data model OF a virtual app and SHALL stay scoped to
the Buildiq shell so the user can navigate between schema authoring
and the virtual app's runtime preview without re-mounting the nested
CnAppRoot. The existing `/builder/:slug/*` virtual-app preview route
from `bootstrap-buildiq` SHALL continue to mount the nested
CnAppRoot for the runtime preview and SHALL be unaffected by this
addition.

**ID:** REQ-OBR-006a

_Disambiguation note: original `REQ-OBR-006` from the
`buildiq-schema-editor` archive delta. Suffix `a` assigned 2026-05-24
to disambiguate from `REQ-OBR-006b` (Publish action, from
`buildiq-versioning`) and `REQ-OBR-006c` (Manifest 403 RBAC gate,
from `buildiq-rbac`) per ADR-037._

#### Scenario: Schema list route renders the designer, not the virtual app

- **WHEN** an authenticated user navigates to
  `/index.php/apps/buildiq/builder/hello-world/schemas`
- **THEN** the Buildiq outer shell renders `SchemaDesigner.vue`
- **AND** the nested `CnAppRoot` for `hello-world` is NOT mounted on
  this route

#### Scenario: Virtual-app preview route still mounts the nested CnAppRoot

- **WHEN** an authenticated user navigates to
  `/index.php/apps/buildiq/builder/hello-world`
- **THEN** the nested `CnAppRoot` for `hello-world` mounts per
  REQ-OBR-002 (bootstrap-buildiq)
- **AND** the Schemas menu entry is reachable from the outer shell's
  navigation

### Requirement: Schemas menu entry surfaced in the builder host

`src/views/BuilderHost.vue` SHALL surface a **Schemas** menu entry in
the Buildiq outer-shell secondary navigation while the user is in a
virtual app's builder context. Activating the entry SHALL route to
`/builder/{slug}/schemas`. The entry SHALL be visible to any user
authorised to read the virtual app's Application object; chain spec
`buildiq-rbac` (#7) MAY narrow this visibility further. The menu
entry SHALL use a translation key (`buildiq.builder.menu.schemas`)
in both `l10n/en.json` and `l10n/nl.json`.

**ID:** REQ-OBR-007a

_Disambiguation note: original `REQ-OBR-007` from the
`buildiq-schema-editor` archive delta. Suffix `a` assigned 2026-05-24
to disambiguate from `REQ-OBR-007b` (Draft-vs-published indicator, from
`buildiq-versioning`) and `REQ-OBR-007c` (List filters by role, from
`buildiq-rbac`) per ADR-037._

#### Scenario: Schemas entry appears in the builder context

- **WHEN** an authenticated user opens
  `/index.php/apps/buildiq/builder/hello-world`
- **THEN** the outer shell's secondary navigation includes a
  **Schemas** entry
- **AND** clicking the entry navigates to
  `/builder/hello-world/schemas`

### Requirement: Application editor exposes a Publish action

`ApplicationEditor.vue` (REQ-OBR-005) SHALL render a "Publish"
action button alongside the existing Save action. Clicking Publish
SHALL: (a) require the textarea manifest to validate cleanly via
`validateManifest`; (b) on validation success, PUT any pending
manifest changes to OR and then call the Application's
`draft → published` lifecycle transition endpoint; (c) on
transition success, surface a confirmation toast naming the new
`ApplicationVersion` `uuid` returned in the response; (d) on
transition failure (e.g. slug-conflict per REQ-OBA-004), surface an
inline error and leave the manifest in draft state. The button
SHALL be disabled while the lifecycle call is in flight.

**ID:** REQ-OBR-006b

_Disambiguation note: original `REQ-OBR-006` from the
`buildiq-versioning` archive delta. Suffix `b` assigned 2026-05-24
to disambiguate from `REQ-OBR-006a` (Schema designer routes) and
`REQ-OBR-006c` (Manifest 403 RBAC gate) per ADR-037._

#### Scenario: Successful publish creates a snapshot

- **WHEN** an integrator opens the editor for a draft Application,
  edits the manifest validly, and clicks Publish
- **THEN** the manifest is saved to OR
- **AND** the lifecycle transition is invoked
- **AND** the confirmation toast surfaces with the newly created
  `ApplicationVersion` `uuid`

#### Scenario: Validation blocks publish

- **WHEN** an integrator clicks Publish while the manifest is
  invalid
- **THEN** no save or lifecycle call is sent
- **AND** the editor surfaces the validation error inline (same
  contract as Save)

### Requirement: Draft-vs-published indicator surfaces lifecycle state (REQ-OBR-007b)

The Buildiq shell SHALL surface the Application's current
`status` (and a marker for "has unpublished draft changes") in two
places: (1) each row of the Application list view carries a small
status badge (`draft` / `published` / `archived`); (2) the editor
header for an open Application carries the same badge plus a
"draft modified since last publish" indicator when the in-textarea
manifest differs from the most recent `ApplicationVersion.manifest`.
The badge SHALL use Nextcloud CSS variables for colour (no
hardcoded colour literals — per ADR-010).

**ID:** REQ-OBR-007b

_Disambiguation note: original `REQ-OBR-007` from the
`buildiq-versioning` archive delta. Suffix `b` assigned 2026-05-24
to disambiguate from `REQ-OBR-007a` (Schemas menu entry) and
`REQ-OBR-007c` (List filters by role) per ADR-037._

#### Scenario: Newly published Application shows published badge

- **WHEN** an Application has been published and its draft has not
  yet been modified
- **THEN** both the list row and the editor header show a
  `published` badge
- **AND** no "draft modified since last publish" indicator is
  shown

#### Scenario: Edited draft shows modified indicator

- **WHEN** an integrator has edited an Application's manifest after
  the most recent publish but before publishing again
- **THEN** the editor header shows the `draft` badge with a
  "modified since last publish" marker
- **AND** the list row reflects the same state

### Requirement: VersionHistory.vue lists snapshots for an Application

The Buildiq shell SHALL render a `VersionHistory.vue` panel
inside `ApplicationEditor.vue` (collapsible / a sibling tab,
implementer's choice) listing every `ApplicationVersion` row for
the current Application in reverse-chronological order (newest
first). Each row SHALL display `version`, `publishedAt` (localised),
`publishedBy`, and any `notes`. The list SHALL be read from OR REST
filtered by `applicationUuid` — no app-local wrapper service.

**ID:** REQ-OBR-008a

_Disambiguation note: original `REQ-OBR-008` from the
`buildiq-versioning` archive delta. Suffix `a` assigned 2026-05-24
to disambiguate from `REQ-OBR-008b` (Editor UIs gate destructive
actions per role, from `buildiq-rbac`) per ADR-037._

#### Scenario: History panel renders snapshots

- **WHEN** an integrator opens an Application that has three
  `ApplicationVersion` rows
- **THEN** the version-history panel renders three rows in
  newest-first order
- **AND** each row shows `version`, `publishedAt`, `publishedBy`,
  and `notes`

#### Scenario: History panel is empty for a never-published Application

- **WHEN** an integrator opens a `draft` Application that has no
  `ApplicationVersion` rows yet
- **THEN** the version-history panel renders an empty state
- **AND** no console error is emitted from the empty-list fetch

### Requirement: Rollback action restores a chosen snapshot

Each row in the `VersionHistory.vue` panel SHALL carry a "Roll back
to this version" action. Clicking it SHALL: (a) prompt for
confirmation in a modal naming the target `version`; (b) on
confirmation, PUT the chosen snapshot's `manifest` onto the
Application as the new draft manifest, set the Application's
`version` per REQ-OBV-003, and leave the Application's `status` as
`draft`; (c) refresh the editor so the textarea reflects the
restored manifest. Per design.md Decision 3 the rollback is
audit-clean — it does **not** delete or mutate existing
`ApplicationVersion` rows. The confirmation modal SHALL live in
its own SFC under `src/modals/` per Hydra modal-isolation gate
(ADR-004).

**ID:** REQ-OBR-009a

_Disambiguation note: original `REQ-OBR-009` from the
`buildiq-versioning` archive delta. Suffix `a` assigned 2026-05-24
to disambiguate from `REQ-OBR-009b` (Caller's group set via
IInitialState, from `buildiq-rbac`) per ADR-037._

#### Scenario: Rollback restores manifest and stays in draft

- **WHEN** an integrator clicks "Roll back to this version" on the
  oldest row in the history panel and confirms in the modal
- **THEN** the Application's draft manifest is byte-equal to the
  chosen snapshot's manifest
- **AND** the Application's status is `draft`
- **AND** no `ApplicationVersion` row has been deleted

#### Scenario: Cancelling the confirmation aborts the rollback

- **WHEN** the integrator opens the confirmation modal and clicks
  Cancel
- **THEN** no PUT is sent to OR
- **AND** the textarea content is unchanged

### Requirement: ManifestDiff.vue renders a side-by-side diff

The Buildiq shell SHALL ship a `ManifestDiff.vue` component
rendering a client-side side-by-side diff between two manifest
blobs. The component SHALL: (a) accept `from` and `to`
`ApplicationVersion` UUIDs (or the literal `draft` for either) as
props; (b) fetch both manifests via the diff endpoint defined in
REQ-OBV-005; (c) compute the diff client-side via `jsdiff` (or an
equivalent library — per design.md Decision 5); (d) render added
lines, removed lines, and unchanged lines with NL Design
colour-coded tokens using Nextcloud CSS variables. By default the
editor SHALL preselect `from=draft` and `to=<currentVersion>` when
the diff view is opened.

**ID:** REQ-OBR-010

#### Scenario: Default diff shows current draft vs latest published

- **WHEN** an integrator opens the diff view from the editor of an
  Application that has been published at least once
- **THEN** the component fetches the diff endpoint with
  `from=draft` and `to=<currentVersion>`
- **AND** the side-by-side rendering shows the diff between the
  two manifests
- **AND** the diff is computed client-side (no second round-trip
  to a server-side diff service)

#### Scenario: Arbitrary snapshot pair can be diffed

- **WHEN** an integrator selects two arbitrary
  `ApplicationVersion` rows from the version-history panel and
  invokes "Compare"
- **THEN** `ManifestDiff.vue` mounts with those two UUIDs
- **AND** the rendered diff matches what the diff endpoint
  returned for that pair

### Requirement: Manifest endpoint returns 403 for unauthorised callers

`ApplicationsController::getManifest` SHALL be extended with a
permissions check that runs after the organisation-scope resolution
and before any branch that returns the manifest payload. The check
SHALL compute the caller's group set via
`IGroupManager::getUserGroups()` and the Application's authorised
groups as
`permissions.owners ∪ permissions.editors ∪ permissions.viewers`.
If the two sets do not intersect — and the caller is not exercising
the audited admin bypass declared in REQ-OBRBAC-006 — the controller
SHALL respond `403 Forbidden` with a JSON body of shape
`{ "error": "forbidden", "code": "buildiq.rbac.no_role" }`. The
existing 404 branch (slug not found) is preserved; the 403 branch
SHALL be ordered before the manifest-body emission and SHALL NOT
leak any Application metadata (no name, no description, no manifest
fragment). Implementation is a single in-controller check — no new
service class — per ADR-022 §Exceptions(1).

@e2e exclude backend manifest-403 endpoint — already covered by rbac-403.spec.ts (the canonical Playwright test for this gate)

**ID:** REQ-OBR-006c

_Disambiguation note: original `REQ-OBR-006` from the
`buildiq-rbac` archive delta. Suffix `c` assigned 2026-05-24 to
disambiguate from `REQ-OBR-006a` (Schema designer routes) and
`REQ-OBR-006b` (Publish action) per ADR-037._

#### Scenario: Caller without a role gets 403 (not 200, not 404)

- **WHEN** an authenticated user requests
  `/index.php/apps/buildiq/api/applications/hello-world/manifest`
- **AND** the Application exists in the user's organisation but no
  group the user belongs to appears in its `permissions`
- **THEN** the response is `403`
- **AND** the response body contains only the error envelope above
- **AND** the response body does NOT contain the Application's
  manifest, name, or description

#### Scenario: Caller in any role gets 200

- **WHEN** an authenticated user in group `team-alpha` requests the
  manifest for an Application whose `permissions.editors`
  contains `team-alpha`
- **THEN** the response is `200 application/json` and the body is
  the stored `manifest` blob

### Requirement: Application list view filters by caller's roles

The system SHALL ensure the frontend Application list (the entry view of the Buildiq shell, currently `ApplicationEditor.vue`'s list mode) renders only Applications on which the caller has at least one role.

The list view SHALL prefer OR-side filtering: if the Application
schema declares an `x-openregister-authorization` rule that
expresses the role intersection, the OR REST list endpoint returns
the pre-filtered set and the frontend renders it directly.

If the declarative path is not available, the frontend SHALL filter
in JS using the caller's group set, which is provided to the
frontend via `IInitialState::provideInitialState('buildiq',
'currentUserGroups', [...])` consumed by `loadState` (per ADR-004 —
no `document.getElementById().dataset` reads).

**ID:** REQ-OBR-007c

_Disambiguation note: original `REQ-OBR-007` from the
`buildiq-rbac` archive delta. Suffix `c` assigned 2026-05-24 to
disambiguate from `REQ-OBR-007a` (Schemas menu entry) and
`REQ-OBR-007b` (Draft-vs-published indicator) per ADR-037._

#### Scenario: User sees only authorised applications

- **WHEN** user `bob` (in groups `team-alpha`, `qa-shared`) opens
  the Buildiq shell
- **AND** the organisation contains Applications A (`permissions.owners
  = ["team-alpha"]`), B (`permissions.editors = ["other-team"]`),
  and C (`permissions.viewers = ["qa-shared"]`)
- **THEN** the Application list shows A and C
- **AND** B is absent (not greyed out, not visible)

#### Scenario: Empty list when user has no roles

- **WHEN** an authenticated user with no role on any Application in
  their organisation opens the Buildiq shell
- **THEN** the Application list is empty
- **AND** the empty-state UI explains "No applications available —
  ask an owner to grant you access"

### Requirement: Editor UIs gate destructive actions per role

The system SHALL gate role-restricted actions in the Buildiq editor views (currently the textarea editor `ApplicationEditor.vue`; the visual editors arriving in chain specs #5 and #6 when they land) via a shared `useRole(application)` composable that returns the caller's effective role (`owner | editor | viewer | none`). The
mapping in REQ-OBRBAC-004 is the canonical source. UI controls
SHALL be:

- **viewer** — textarea (or visual editor) rendered read-only;
  Save / Publish / Archive / Delete / Transfer / Permissions
  controls are hidden (`v-if`).
- **editor** — textarea (or visual editor) is editable; Save is
  enabled; Publish / Archive / Delete / Transfer / Permissions
  controls are hidden.
- **owner** — all controls visible and enabled, including the
  Permissions panel and the Permission history panel.

A user whose role is `none` cannot reach the editor at all
(REQ-OBR-007c ensures the Application doesn't appear in their
list; REQ-OBR-006c ensures direct-URL access returns 403).

**ID:** REQ-OBR-008b

_Disambiguation note: original `REQ-OBR-008` from the
`buildiq-rbac` archive delta. Suffix `b` assigned 2026-05-24 to
disambiguate from `REQ-OBR-008a` (VersionHistory panel, from
`buildiq-versioning`) per ADR-037._

#### Scenario: Editor sees Save but not Publish

- **WHEN** a user with only the `editor` role opens an Application
- **THEN** the manifest textarea is editable
- **AND** the Save button is enabled
- **AND** the Publish, Archive, Delete, Transfer-ownership, and
  Permissions buttons are not rendered

#### Scenario: Owner sees all controls

- **WHEN** a user with the `owner` role opens an Application
- **THEN** every control listed in REQ-OBRBAC-004 is visible and
  enabled
- **AND** the Permission history panel is reachable

### Requirement: Caller's group set is provided via initial state

The Buildiq PHP layer SHALL provide the caller's Nextcloud group
IDs to the frontend via
`IInitialState::provideInitialState('buildiq',
'currentUserGroups', string[])`, written from the relevant
controller's `index` action (or a dedicated `InitialStateProvider`
service registered in `lib/AppInfo/Application.php`). The frontend
SHALL consume this value through `loadState('buildiq',
'currentUserGroups')` from `@nextcloud/initial-state`. The
frontend SHALL NOT read group membership from any DOM
data-attribute, fetch endpoint, or `document.getElementById`
pattern (ADR-004 hard rule; enforced by the
`gate-initial-state` Hydra gate).

@e2e exclude pure-backend PHP IInitialState contract — loadState value verified by PHPUnit; no Playwright-accessible surface to assert server-side initial state injection

**ID:** REQ-OBR-009b

_Disambiguation note: original `REQ-OBR-009` from the
`buildiq-rbac` archive delta. Suffix `b` assigned 2026-05-24 to
disambiguate from `REQ-OBR-009a` (Rollback action, from
`buildiq-versioning`) per ADR-037._

#### Scenario: Frontend sees the caller's groups

- **WHEN** the Buildiq shell boots for user `bob` (in groups
  `team-alpha`, `qa-shared`)
- **THEN** `loadState('buildiq', 'currentUserGroups')` returns
  `["team-alpha", "qa-shared"]`
- **AND** no DOM data-attribute access is needed to obtain the
  groups

### Requirement: ApplicationCard renders icon and omits redundant Live chip

`ApplicationCard.vue` SHALL render the Application's icon in front of the app title using an
`<img>` element whose `src` is the URL of the icon-serving light endpoint
(`/index.php/apps/buildiq/icons/{slug}.svg`). The image SHALL carry a descriptive `alt`
attribute (the app's name). The component SHALL omit the `Live` chip that was previously
conditionally rendered on `app.currentVersion` (line 30 of the original file); the
lifecycle-status pill (line 23) already communicates "Published" state to the user and the
Live chip produces duplicate signalling. The `ob-app-card__chip--live` CSS rule and the
`v-if="app.currentVersion"` conditional SHALL be removed.

@e2e exclude already covered — all four scenarios verified by applicationCard.spec.ts; adding duplicate tags would double-count the same test

**ID:** REQ-OBR-013

#### Scenario: Published app card shows icon before the title

- **WHEN** a user views the virtual apps index and a published Application has an icon
  registered at the icon endpoint
- **THEN** each ApplicationCard renders an `<img>` element with
  `src="/index.php/apps/buildiq/icons/{slug}.svg"` before the app name heading

#### Scenario: Card icon falls back gracefully when endpoint returns an error

- **WHEN** the icon endpoint returns a non-200 response (e.g. slug not found)
- **THEN** the `<img>` element's `@error` handler replaces the src with a transparent 1×1
  placeholder or the Buildiq default icon path, so no broken-image icon appears in the card

#### Scenario: Live chip is absent from all ApplicationCards

- **WHEN** a user views the virtual apps index and one Application has `currentVersion` set
- **THEN** no element with class `ob-app-card__chip--live` or text "Live" is rendered on
  any card — the Published status pill on the same card is the sole visual indicator

#### Scenario: Card layout and existing fields are not disrupted

- **WHEN** the icon is rendered in front of the title
- **THEN** the title heading, description paragraph, version chip, role chip, and slug chip
  continue to render in their expected positions and the card's click navigation to
  VirtualAppDetail is unaffected

### Requirement: MCP tool-provider contract

The Buildiq MCP surface SHALL be implemented by a class
(`OCA\Buildiq\Mcp\BuildiqToolProvider`) that implements
`OCA\OpenRegister\Mcp\IMcpToolProvider`. The provider SHALL declare its
host Nextcloud app id (`buildiq`), expose a static tool catalogue of
read tools (`buildiq.listApps`, `buildiq.getAppManifest`) and write
tools covering virtual-app lifecycle (`buildiq.createApp`,
`buildiq.promoteVersion`) and draft-version authoring
(`buildiq.upsertSchema`, `buildiq.upsertPage`, `buildiq.addWidget`,
`buildiq.upsertMenuItem`), and SHALL dispatch each invocation by tool
id to the matching internal handler. Unknown tool ids SHALL return a
uniform error envelope of shape
`{ isError: true, error, message }` carrying the machine-readable code
`unknown_tool` and a human-readable message that lists the available
tool ids.

@e2e exclude pure-backend PHP IMcpToolProvider unit — getAppId, getTools, invokeTool dispatch, and unknown-tool error envelope verified by PHPUnit; no Playwright-testable UI surface

**ID:** REQ-OBR-MCP-001

#### Scenario: Provider reports the Buildiq app id

- **WHEN** OpenRegister's MCP orchestrator calls `getAppId()` on the
  provider
- **THEN** the provider returns the string `buildiq`

#### Scenario: Catalogue surfaces all Buildiq tools

- **WHEN** OpenRegister's MCP orchestrator calls `getTools()`
- **THEN** the returned array contains the eight tool descriptors
  (`buildiq.listApps`, `buildiq.getAppManifest`,
  `buildiq.createApp`, `buildiq.promoteVersion`,
  `buildiq.upsertSchema`, `buildiq.upsertPage`,
  `buildiq.addWidget`, `buildiq.upsertMenuItem`), each with an
  `inputSchema` of `type: object`

#### Scenario: Unknown tool id returns a structured error

- **WHEN** OpenRegister's MCP orchestrator calls
  `invokeTool('buildiq.nope', [])`
- **THEN** the response is `{ isError: true, error: 'unknown_tool',
  message: ... }` and `message` lists the available tool ids

### Requirement: Auth-gated dispatch with arg validation

Every MCP tool exposed by this provider SHALL require an authenticated
Nextcloud session. The provider SHALL resolve the active user via
`IUserSession`; if no user is signed in (or the user UID is empty), the
handler SHALL short-circuit with an `{ isError: true, error:
'forbidden', message }` envelope before performing any read or write.

@e2e exclude pure-backend PHP MCP arg-validation unit — unauthenticated rejection, limit/statusFilter clamping, and isAdmin helper verified by PHPUnit; no Playwright-testable UI surface
Read-tool argument shape SHALL be validated up-front — `listApps`
SHALL clamp `limit` to the range 1..50 and SHALL reject any
`statusFilter` outside the closed set `{any, draft, published,
archived}` with `{ isError: true, error: 'invalid_arguments' }`. Slug
arguments accepted by the write surface SHALL conform to a shared
pattern (lowercase alphanumeric, hyphen-separated, 2..48 chars,
matching `^[a-z0-9][a-z0-9-]*[a-z0-9]$`). A public `isAdmin($userId)`
helper SHALL delegate to `IGroupManager::isAdmin` so callers can probe
admin posture without re-implementing the check.

**ID:** REQ-OBR-MCP-002

#### Scenario: Unauthenticated caller is rejected

- **WHEN** the MCP orchestrator invokes any Buildiq tool with no
  active `IUserSession` user
- **THEN** the response is `{ isError: true, error: 'forbidden', ... }`
  and no OpenRegister read/write is attempted

#### Scenario: listApps rejects an out-of-range limit

- **WHEN** an authenticated caller invokes `buildiq.listApps` with
  `limit: 0` (or `limit: 51`)
- **THEN** the response is `{ isError: true, error:
  'invalid_arguments', message: "Invalid limit 0." }`

#### Scenario: listApps rejects an unknown statusFilter

- **WHEN** an authenticated caller invokes `buildiq.listApps` with
  `statusFilter: 'weird'`
- **THEN** the response is `{ isError: true, error:
  'invalid_arguments', message: "Invalid statusFilter 'weird'." }`

#### Scenario: isAdmin reports admin membership

- **WHEN** a caller queries `isAdmin('alice')` and Nextcloud's group
  manager reports Alice in the admin group
- **THEN** the helper returns `true`

#### Note

`isValidSlug` (private) duplicates the slug pattern enforced by the
existing `SlugValidator` service. TODO: collapse onto `SlugValidator`
in a follow-up so the pattern lives in exactly one place.

### Requirement: Application resolution and uniform response mapping

Tools that operate on a single virtual app SHALL resolve the supplied
slug to an `Application` object via the `built-app-route` index in the
`buildiq` register: the provider SHALL call
`ObjectService::searchObjectsBySlug` to locate a matching route, then
`ObjectService::find` to load the Application by its `applicationUuid`.

@e2e exclude pure-backend PHP MCP resolution helpers — slug resolution, not_found/inconsistent_state envelopes, deepLink builder, extractUuid fallback verified by PHPUnit; no Playwright-testable UI surface
A missing route SHALL surface as `{ isError: true, error: 'not_found'
}`; a route present without a matching Application (orphaned index
row) SHALL surface as `{ isError: true, error: 'inconsistent_state' }`.
The compact response shape used by `listApps` SHALL include
`{ uuid, slug, name, description, status, version }`. Each MCP
response SHALL carry an Buildiq `source` descriptor of shape
`{ type: 'buildiq.application', uuid, url, label }` where `url` is
a Nextcloud deep link of the form `/apps/buildiq/builder/{slug}`
(or `/apps/buildiq` when no slug is bound). OR entities, arrays, and
`jsonSerialize`-able objects SHALL all be accepted as input to the
mapping pipeline (`toArray`); UUIDs SHALL be extracted from the
`uuid`, `id`, `@self.uuid`, or `@self.id` fields in that fallback
order (`extractUuid`).

**ID:** REQ-OBR-MCP-003

#### Scenario: Slug resolves to its Application

- **GIVEN** a published virtual app with slug `hello-world` and a
  matching `built-app-route` row pointing at its Application UUID
- **WHEN** a tool resolves the slug via `resolveApplicationBySlug`
- **THEN** the helper returns
  `{ application: { ..., slug: 'hello-world', ... } }`

#### Scenario: Missing route returns not_found

- **WHEN** a tool resolves a slug for which no `built-app-route` row
  exists
- **THEN** the helper returns `{ error: 'not_found', message: ... }`

#### Scenario: Route without Application returns inconsistent_state

- **GIVEN** a `built-app-route` row whose `applicationUuid` points at
  an Application that has been deleted
- **WHEN** a tool resolves the slug
- **THEN** the helper returns `{ error: 'inconsistent_state', message:
  ... }`

#### Scenario: Deep link uses /apps/buildiq/builder/{slug}

- **WHEN** the provider calls `buildDeepLink('hello-world')`
- **THEN** the returned URL is `/apps/buildiq/builder/hello-world`

#### Scenario: UUID extraction falls back through @self

- **GIVEN** an OR object array of shape
  `{ '@self': { uuid: 'abc-123' } }` (no top-level `uuid` or `id`)
- **WHEN** `extractUuid` is called
- **THEN** the returned UUID is `'abc-123'`

### Requirement: Draft-version manifest mutation isolation

Authoring tools that mutate a virtual app (`buildiq.upsertSchema`, `buildiq.upsertPage`, `buildiq.addWidget`, `buildiq.upsertMenuItem`) SHALL default the
`versionSlug` argument to `development` so a misfired tool call cannot
mutate a production version. A version row SHALL be located via
`loadVersion(objectService, appSlug, versionSlug)`, which SHALL look
up the row in the `application-version` schema under
`{appSlug}-{versionSlug}` slug composition; missing rows SHALL surface
as `{ error: 'not_found' }` so the orchestrator can return a
structured error envelope. Manifest writes SHALL be performed
exclusively through `saveVersionManifest`, which SHALL deep-merge the
mutated manifest blob back onto the located version row and persist it
via `ObjectService::saveObject`; partial writes that bypass this
helper SHALL be considered a violation of this requirement.

**ID:** REQ-OBR-MCP-004

@e2e exclude pure-backend PHP MCP authoring-tool isolation — versionSlug defaulting, loadVersion/saveVersionManifest contracts verified by PHPUnit; no Playwright-testable UI surface

#### Scenario: Authoring tools default versionSlug to development

- **WHEN** a caller invokes `buildiq.upsertPage` with `appSlug:
  hello-world` and omits `versionSlug`
- **THEN** the mutation targets the `hello-world-development` version
  row, not any production version

#### Scenario: Unknown version returns not_found

- **WHEN** an authoring tool resolves `loadVersion(_, 'hello-world',
  'staging')` and no `application-version` row exists with slug
  `hello-world-staging`
- **THEN** the helper returns `{ error: 'not_found', message: ... }`
  and the calling tool surfaces an MCP error envelope

#### Scenario: Manifest persistence routes through saveVersionManifest

- **WHEN** an authoring tool persists a mutated manifest
- **THEN** the persistence path is `saveVersionManifest(...)` and the
  underlying `ObjectService::saveObject` call carries the merged
  manifest on the located version row

### Requirement: The runtime MUST inject the current user's group context

When rendering a virtual app, the Buildiq runtime MUST resolve the current
user's group memberships server-side and supply them to the manifest renderer
as the set of permission strings the user holds (`group:<gid>`). When no
permission context is available the renderer MUST fall back to showing all
items (no regression for apps without permission fields).

@e2e exclude backend permission-context resolution verified by ManifestResolverServicePermissionFilterTest (testResolveCallerPermissionsForDisplayReturnsGroupSetForViewer, testUngatedManifestIsUnchanged); live E2E deferred per task constraints (no deploy to shared dev instance) — see Conduction/buildiq#41 quarantine pattern

**ID:** REQ-OBR-014

#### Scenario: A vet's group context reaches the renderer

- **GIVEN** the current user is a member of the `vets` group
- **WHEN** the virtual app is rendered
- **THEN** the renderer receives a permissions set containing `group:vets`

#### Scenario: Apps without permissions render unchanged

- **GIVEN** a manifest whose menu items and pages declare no `permission`
- **WHEN** any user opens the app
- **THEN** every menu item and page renders regardless of the user's groups

### Requirement: Menu items and pages MUST be filterable by permission

A manifest `menu[]` item or `pages[]` entry MAY declare a `permission`
(string or list). `ApplicationsController::getManifest` MUST, via
`ManifestResolverService::filterManifestForCaller`, strip that item/page from
the response payload SERVER-SIDE when the caller holds none of the declared
permissions — the manifest is filtered before it leaves the server, not
merely hidden client-side. Admins and callers holding an owner or editor role
on the Application MUST receive the manifest unfiltered.

@e2e exclude the server-side deny path is verified by ManifestResolverServicePermissionFilterTest::testOutOfGroupCallerNeverReceivesGatedMenuItemOrPage (the load-bearing proof) plus testGroupMemberReceivesGatedMenuItemAndPage / testAdminBypassesFiltering / testOwnerBypassesFiltering / testEditorBypassesFiltering; live E2E deferred per task constraints (no deploy to shared dev instance) — see Conduction/buildiq#41 quarantine pattern

**ID:** REQ-OBR-015

#### Scenario: Vets-only medical menu and page

- **GIVEN** the medical menu item and its page declare `permission: "group:vets"`
- **WHEN** a user in `vets` requests the manifest
- **THEN** the medical menu item and its page are present in the response
- **AND WHEN** a user not in `vets` (non-admin, non-owner, non-editor) requests the manifest
- **THEN** the medical menu item and its page are ABSENT from the response — not merely hidden client-side

### Requirement: A group-scoped dashboard MAY be the landing page for its group

When more than one dashboard-type page exists in `pages[]`, the filtered
manifest MUST land the caller on the highest-priority dashboard page (index 0)
whose `permission` the caller satisfies, falling back to the default
dashboard when none match.

@e2e exclude backend reorder logic verified by ManifestResolverServicePermissionFilterTest (testGroupScopedDashboardIsPromotedToLandingForMatchingCaller, testNonMatchingCallerKeepsDefaultDashboardAsLanding); live E2E deferred per task constraints (no deploy to shared dev instance) — see Conduction/buildiq#41 quarantine pattern

**ID:** REQ-OBR-016

#### Scenario: Vets land on the vet dashboard

- **GIVEN** a default dashboard and a `MedicalDashboard` page with `permission: "group:vets"`
- **WHEN** a user in `vets` requests the manifest
- **THEN** `pages[0]` is the vet dashboard
- **AND** a non-vet user's `pages[0]` is the default dashboard

### Requirement: Navigation hiding MUST NOT be treated as object security

Permission-based hiding of menus and pages remains a presentation concern;
the authoritative access control for the data a page reads MUST be enforced
by OpenRegister schema RBAC (`schema.authorization`).

@e2e exclude OpenRegister-side object-authorization behaviour, out of Buildiq's own Playwright-testable surface — verified in OpenRegister's own test suite; this requirement documents the boundary, it does not add new Buildiq-side behaviour

**ID:** REQ-OBR-017

#### Scenario: Object access holds even if navigation is bypassed

- **GIVEN** `medicalRecord.authorization.read = ["vets"]` in OpenRegister
- **WHEN** a non-vet user requests medical objects directly
- **THEN** OpenRegister returns no medical objects for that user
