## Context

Kind: code. Two schemas, one editor reuse, one data-provider leaf with append.

The manifest form contract (`manifest-form-logic`) already covers steps,
`visibleWhen` and validation, and `FormPageEditor.vue` authors it. A
registration form is that same config bound to a type value, so the builder
is a reuse and the new work is the binding, the phase labels, the draft and
the leaf.

## D1. `registrationForm`

| property | notes |
|---|---|
| `targetApp`, `register`, `schema` | the object the form creates |
| `typeProperty`, `typeValue` | e.g. `caseType`, `bouwvergunning` |
| `fields[]`, `steps[]`, `formLogic` | manifest form config, validated against `app-manifest.schema.json` |
| `steps[].phase` | free label shown as the group heading |
| `allowSaveForLater` | boolean |
| `status` | lifecycle `draft`, `published` |

One published form per `(targetApp, register, schema, typeProperty,
typeValue)`.

## D2. `formDraft`

`registrationFormId`, `userId`, `values` (object), `step` (number),
`updatedAt`. Owner-only: OpenRegister RBAC restricts read and write to the
user who created it. Drafts expire after 30 days through a declared
`x-openregister-retention`.

## D3. Builder

`FormPageEditor.vue` mounts for a `registrationForm` with the "applies to"
panel from `case-page-layout-per-case-type` and a phase label field on
`FormStepsManager.vue`. Validation reuses `validateFormLogic`.

## D4. `buildiq-registration-form`, kind data-provider

- `lib/Integration/RegistrationFormLeafProvider.php`, `app-local`.
- Host object: the type object, e.g. `dossiq/caseType/<id>`. The provider
  reads the object's slug or id as `typeValue`.
- `list` returns `{form, drafts[]}`: the published form for that type and the
  calling user's drafts.
- `create` appends a `formDraft` for the calling user. This is the one append
  ADR-066 allows; it writes buildiq's own data and nothing else.
- The consumer renders `form` with `CnFormDialog`, seeds it with a chosen
  draft, and submits the finished values to its own object store. Buildiq
  never sees the created case.

## Risks

- A form referencing a property the target schema no longer has. The builder
  validates field names against the target schema on save and warns.
- Draft values hold personal data. Owner-only RBAC and 30-day retention.
