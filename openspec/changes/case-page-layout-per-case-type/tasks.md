## 1. Schema

- [ ] 1.1 Add `pageLayout` to `lib/Settings/openbuild_register.json` with the D1 properties, uniqueness and the `draft`, `published` lifecycle (REQ-OBPL-001)
- [ ] 1.2 Seed one published schema-wide layout and one type-specific layout for `dossiq/case`

## 2. Editor

- [ ] 2.1 Add the "applies to" panel to `DetailPageEditor.vue` and the save path to a `pageLayout` object (REQ-OBPL-002)
- [ ] 2.2 Warn on save when a `tabs[].ref` leaf id is unknown to the integration registry

## 3. Leaf

- [ ] 3.1 Add `lib/Integration/PageLayoutLeafProvider.php` and the `RegisterLeafProvidersEvent` listener with the D4 resolution order (REQ-OBPL-003)
- [ ] 3.2 Open the nextcloud-vue change `runtime-detail-layout` so `CnDetailPage` merges a provider answer over its manifest config

## 4. Quality

- [ ] 4.1 PHPUnit for the resolution order (type value, schema-wide, none, draft ignored)
- [ ] 4.2 Playwright `tests/e2e/page-layout-editor.spec.ts` for the applies-to panel and save
- [ ] 4.3 Dutch and English strings; docs with screenshots
