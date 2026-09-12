## 1. Schemas

- [ ] 1.1 Add `registrationForm` and `formDraft` to `lib/Settings/openbuild_register.json` with uniqueness, owner-only RBAC on drafts and 30-day retention (REQ-OBRF-001)
- [ ] 1.2 Seed one published registration form for `dossiq/case` `caseType = bouwvergunning` with two phases and one `visibleWhen` rule

## 2. Builder

- [ ] 2.1 Mount `FormPageEditor.vue` for a `registrationForm` with the applies-to panel and a phase label per step (REQ-OBRF-002)
- [ ] 2.2 Validate field names against the target schema on save; warn, do not block

## 3. Leaf

- [ ] 3.1 Add `lib/Integration/RegistrationFormLeafProvider.php` with `list` and draft `create`, registered on `RegisterLeafProvidersEvent` (REQ-OBRF-003)

## 4. Quality

- [ ] 4.1 PHPUnit for the provider: published form per type, drafts scoped to the caller, `create` refuses a draft for another user
- [ ] 4.2 Playwright `tests/e2e/registration-form-builder.spec.ts` for building a two-phase form and publishing it
- [ ] 4.3 Dutch and English strings; docs with screenshots
- [ ] 4.4 Hand the leaf id and the `{form, drafts}` shape to dossiq for `friendly-case-create-form`
