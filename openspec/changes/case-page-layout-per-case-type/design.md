## Context

Kind: code. One schema, one editor section, one data-provider leaf.

The page designer (`openbuild-page-designer`) authors `manifest.pages[]` for
a virtual app. A layout per case type is the same shape, the detail-page
`config`, but stored per target schema and type value instead of per virtual
app page, and read at render time by another app.

## D1. `pageLayout` schema

In `lib/Settings/openbuild_register.json`:

| property | type | notes |
|---|---|---|
| `targetApp` | string | app id, e.g. `dossiq` |
| `register`, `schema` | string | the object schema the layout applies to |
| `typeProperty` | string, optional | e.g. `caseType` |
| `typeValue` | string, optional | one value; empty means schema-wide |
| `header` | object | `titleField`, `subtitleField`, `chips[]` (field names) |
| `tabs[]` | array | ordered `{id, kind: leaf|widget, ref, label}` |
| `widgets[]` | array | `{slot, id, config}` in the manifest widget shape |
| `status` | lifecycle `draft`, `published` | only `published` resolves |

`(targetApp, register, schema, typeProperty, typeValue)` is unique. The
`tabs[]` and `widgets[]` sub-shapes validate against the canonical manifest
schema (`app-manifest.schema.json`, detail page config).

## D2. Editor

`DetailPageEditor.vue` (the detail-page sub-editor) gains an "applies to"
panel: target app, register, schema and an optional type property and value
picker read from the target schema's enum or from the type register's rows.
Saving writes a `pageLayout` object through the OpenRegister objects API, the
same path the page designer already uses (REQ-OBPD save flow).

## D3. `buildiq-page-layout`, kind data-provider

- `lib/Integration/PageLayoutLeafProvider.php`, `IntegrationProvider` with
  storage strategy `app-local`, no `create`.
- `list(register, schema, objectId)` reads the object's type value when a
  type-specific layout exists, and returns at most one published layout:
  type value first, then schema-wide. Empty list when none.
- Registered on `RegisterLeafProvidersEvent` behind `class_exists()`.
- The consumer places the leaf in its manifest. `CnDetailPage` asks the
  provider before render and merges the answer over its manifest config
  (nextcloud-vue dependency, tracked as `runtime-detail-layout` there).

## D4. Resolution order

1. published layout with matching `typeValue`
2. published layout with empty `typeValue`
3. nothing: the consumer's manifest wins

Draft layouts never resolve. An admin previews a draft in the page designer's
live preview pane.

## Risks

- A layout referencing a leaf id the consumer does not register renders an
  empty tab. The editor warns on save when the leaf id is unknown to the
  integration registry.
