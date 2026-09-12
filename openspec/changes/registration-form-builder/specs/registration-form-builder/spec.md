# registration-form-builder Specification (delta)

## Purpose

An admin builds the registration form for one type of object: questions
grouped per phase, rules, steps and save for later. Buildiq stores the form
and the user's drafts and serves both as a data-provider leaf (ADR-066). The
owning app renders the form with `CnFormDialog`. Requested by the dossiq
competitor analysis, finding B11.

## ADDED Requirements

### Requirement: A registration form is bound to one type value (REQ-OBRF-001)

Buildiq SHALL declare a `registrationForm` schema with `targetApp`,
`register`, `schema`, `typeProperty`, `typeValue`, manifest-shaped `fields[]`,
`steps[]` with a `phase` label, `formLogic`, `allowSaveForLater` and a
`draft`, `published` lifecycle, and a `formDraft` schema with
`registrationFormId`, `userId`, `values`, `step` and `updatedAt`. Drafts SHALL
be readable and writable only by their owner and SHALL expire after 30 days.

**ID:** REQ-OBRF-001

#### Scenario: A draft is private to its owner

- **WHEN** user A saves a draft and user B lists drafts for the same form
- **THEN** user B's list does not contain A's draft
- @e2e exclude RBAC on the draft schema; covered by PHPUnit against the imported register

### Requirement: The form builder groups questions per phase with rules and steps (REQ-OBRF-002)

The form page sub-editor SHALL author a `registrationForm`: an "applies to"
panel, steps with a phase label, `visibleWhen` rules and validation from
`form-editor-logic`. Saving SHALL validate field names against the target
schema and warn on an unknown field.

**ID:** REQ-OBRF-002

#### Scenario: An admin builds a two-phase registration form

- **WHEN** an admin binds a form to `dossiq/case` `caseType = bouwvergunning`, adds steps "Aanvrager" and "Bouwwerk", a rule and publishes
- **THEN** one published `registrationForm` exists with two steps carrying those phase labels
- e2e: `tests/e2e/registration-form-builder.spec.ts`

### Requirement: The form and the user's drafts are a data-provider leaf (REQ-OBRF-003)

Buildiq SHALL register a leaf `buildiq-registration-form` of kind
`data-provider`, storage strategy `app-local`. Placed on a type object, its
`list` SHALL return the published form for that type value and the calling
user's drafts; its `create` SHALL append a `formDraft` for the calling user
only. The provider SHALL NOT create the target object and SHALL NOT call any
action in the consuming app.

**ID:** REQ-OBRF-003

#### Scenario: dossiq fetches the form for a case type

- **WHEN** dossiq's create dialog asks the provider to `list` for the `bouwvergunning` case type
- **THEN** the published form and the user's drafts are returned and `CnFormDialog` renders the steps
- e2e: `tests/e2e/registration-form-leaf.spec.ts`

#### Scenario: Save for later appends a draft

- **WHEN** a user saves a half-filled form for later
- **THEN** the provider's `create` stores a `formDraft` with the values and the current step, and the next `list` returns it
- @e2e exclude the append runs in buildiq's DI context; covered by PHPUnit on `RegistrationFormLeafProvider::create()`
