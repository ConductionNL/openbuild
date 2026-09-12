# page-layout-per-type Specification (delta)

## Purpose

An admin decides per schema and per type value which header, tabs and widgets
a detail page shows. Buildiq stores the layout and serves it to the owning
app as a data-provider leaf (ADR-066). Requested by the dossiq competitor
analysis, finding B09.

## ADDED Requirements

### Requirement: A page layout is bound to a schema and an optional type value (REQ-OBPL-001)

Buildiq SHALL declare a `pageLayout` schema with `targetApp`, `register`,
`schema`, optional `typeProperty` and `typeValue`, `header`, `tabs[]`,
`widgets[]` and a `draft`, `published` lifecycle. The tuple `(targetApp,
register, schema, typeProperty, typeValue)` SHALL be unique. `tabs[]` and
`widgets[]` SHALL validate against the canonical manifest detail-page config.

**ID:** REQ-OBPL-001

#### Scenario: Two layouts for one type value are refused

- **WHEN** an admin saves a second published layout for `dossiq/case` with `caseType = bouwvergunning`
- **THEN** OpenRegister validation refuses it and names the existing layout
- @e2e exclude uniqueness is a schema-validation invariant; covered by PHPUnit on the register import

### Requirement: The detail-page editor authors a layout per type (REQ-OBPL-002)

The detail-page sub-editor SHALL offer an "applies to" panel with target app,
register, schema and an optional type property and value. Saving SHALL write
a `pageLayout` object through the OpenRegister objects API. A `tabs[].ref`
unknown to the integration registry SHALL raise a warning, not block the save.

**ID:** REQ-OBPL-002

#### Scenario: An admin lays out the building-permit case page

- **WHEN** an admin picks `dossiq`, `case`, `caseType = bouwvergunning`, orders three tabs and saves
- **THEN** a `pageLayout` object exists in `draft` with those tabs and the editor shows it in the live preview
- e2e: `tests/e2e/page-layout-editor.spec.ts`

### Requirement: The layout is served as a data-provider leaf (REQ-OBPL-003)

Buildiq SHALL register a leaf `buildiq-page-layout` of kind `data-provider`,
storage strategy `app-local`, through `RegisterLeafProvidersEvent`. For a host
object its `list` SHALL return at most one published layout: the one matching
the object's type value first, else the schema-wide one, else nothing. Draft
layouts SHALL never resolve. The provider SHALL NOT offer `create` and SHALL
NOT call any action in the consuming app.

**ID:** REQ-OBPL-003

#### Scenario: A type-specific layout wins over the schema-wide one

- **WHEN** a published schema-wide layout and a published `bouwvergunning` layout exist and the provider lists for a `bouwvergunning` case
- **THEN** only the `bouwvergunning` layout is returned
- @e2e exclude resolution runs in buildiq's DI context; covered by PHPUnit on `PageLayoutLeafProvider::list()`

#### Scenario: Buildiq absent means manifest layout

- **WHEN** the consuming app renders a case detail page on an instance without buildiq
- **THEN** no provider answers and the page renders its manifest layout unchanged
- @e2e exclude the absent-app path cannot be staged on CI, which installs the whole fleet; covered by the consumer's unit test on the fallback
