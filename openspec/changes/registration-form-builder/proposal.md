---
kind: code
---

## Why

GZAC and Zaaksysteem let an admin build the registration form per case type:
questions grouped per phase, rules that show or hide a question, several
steps, and save for later (`gz/pages/CaseDefinition-FormFlows.md`,
`gz/pages/CaseDefinition-Formulieren.md`, `zs/pages/CaseTypeV2-phases.md`,
`zs/journeys.md` J2, all under `concurrentie-analyse/procest/_round2/`).
dossiq's `friendly-case-create-form` renders a type's questions and nothing
more: no groups, no rules, no steps, no draft (finding B11, M1 3.12, 11.4).

Buildiq already has the pieces: `form-editor-logic` authors steps,
`visibleWhen` conditions and validation on a manifest form page. What is
missing is a form bound to a type value in another app's schema, and a way for
that app to fetch it at create time. Decision D11 asks buildiq to write this
half now.

## What changes

- A `registrationForm` schema: a manifest-shaped form definition
  (`fields[]`, `steps[]`, `formLogic`) bound to a target `register`, `schema`
  and one type value, with `phase` labels on steps and `allowSaveForLater`.
- The form builder: the form page sub-editor reused for a `registrationForm`,
  with a phase label per step and an "applies to" panel.
- A `formDraft` schema: a user's half-filled form, per registration form and
  user.
- A `data-provider` leaf `buildiq-registration-form` (ADR-066). Placed on the
  type object (for dossiq: the case type), it lists the published form for
  that type and the calling user's drafts, and accepts a draft as `create`.
  The consumer renders the form with `CnFormDialog`, which already speaks the
  steps and `visibleWhen` contract.

## Out of scope

- Rendering. `CnFormDialog` renders; buildiq only stores and serves.
- Autosave inside the dialog (B12, nextcloud-vue).
