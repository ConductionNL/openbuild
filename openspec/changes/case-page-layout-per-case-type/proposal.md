---
kind: code
---

## Why

In GZAC an admin decides per case type which tabs, widgets and header fields
a case page shows (`gz/pages/CaseDefinition-Dossierdetails-Tabbladen.md`,
`gz/pages/CaseDefinition-Header.md` in the dossiq competitor analysis,
`concurrentie-analyse/procest/_round2/`). In dossiq a developer declares them
once per page in `src/manifest.json`; every case type gets the same page
(finding B09, M1 11.6 to 11.8). A layout per schema and per type value is a
buildiq concern: buildiq already authors detail pages, sidebar tabs and
widgets in its page designer (`openbuild-page-designer`).

Decision D11 of the analysis asks buildiq to write its half now. dossiq is
the first consumer; any app with a type field on a schema is the next.

## What changes

- A `pageLayout` schema in buildiq's register: a detail-page layout bound to
  a target `register` and `schema`, optionally to one value of a type property
  (`typeProperty`, `typeValue`). It carries `header` (title field, chips),
  `tabs[]` (leaf ids and widget ids in order) and `widgets[]` per slot, in
  the manifest detail-page shape.
- A layout editor: the existing detail-page sub-editor gains an "applies to"
  section, so an admin edits a layout for `dossiq/case` where `caseType =
  bouwvergunning` without touching dossiq's manifest.
- A `data-provider` leaf `buildiq-page-layout` (ADR-066): for a host object it
  returns the most specific layout, type value over schema over none. A
  consumer places the leaf; when buildiq is absent nothing is returned and the
  consumer keeps its manifest layout.

## Out of scope

- `CnDetailPage` accepting a runtime layout over its manifest config. That is
  a `@conduction/nextcloud-vue` change and is listed as a dependency.
- Rights per case type (B13, OpenRegister RBAC).
