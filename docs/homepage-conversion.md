# Editable native Homepages

The Homepage content-source selector offers HTML/Mustache or a published plugin document. Legacy native mode is a read-only compatibility format, never a new authoring choice.

A template can declare `homepageMarkup` as a registered partial ID. That trusted PHP adapter exports the current native body as ordinary editable HTML, preserving classes, section IDs, local assets and content. It receives the public template view scope with `$homepageConversion=true`, and must never emit a form token, private data, tracking state or rendered plugin snapshots. `{{cms.base}}` supplies the request base URL; the `cms` binding namespace is reserved. Ordinary HTML safety rules, escaped Mustache variables and budgets remain in effect. This is an opt-in template adapter, not a PHP-to-HTML compiler.

Use `markupFragments` to declare named live public islands mapped to registered private partial IDs. Example: `"markupFragments":{"contact":"contact-form"}`. Insert `{{template:fragment id="contact"}}` into the exported body. The optional bounded `key="section-example"` argument is available to that partial as `$fragmentKey`; it never selects a file. Only explicitly declared current-template partials may run. They are trusted template code with the same security responsibility as native templates: escape values and protect POST forms. Do not declare administrative or confidential partials. Missing references produce a visible warning after template changes. Private previews show inert fragment labels and never issue interactive tokens. Never cache generated fragments or put them into public JSON providers.

Essencial converts native editorial sections and keeps its contact form, live examples, extra plugin blocks and each ordered custom section as declared live fragments. Labels, classes, the contact endpoint and fresh CSRF remain the same. Converted editorial text becomes the Homepage copy: subsequent changes to the old native JSON do not overwrite it. The shared editor shows converted native content without writing on GET; Save draft changes only the private draft, and Publish / Schedule remains explicit.

For an existing installation, first back up and review the adapter and generated body in an isolated copy. Obtain current SHA-256 revisions from `Homepages::read()`, `JsonStore::read('site')` and `JsonStore::read('home')`, then run:

```sh
php src/system/bin/convert-homepage.php CATALOG_SHA256 SITE_SHA256 HOME_SHA256
```

The CLI holds lifecycle/content locks, checks all three revisions and validates the whole generated definition before mutation. It converts only `legacy` snapshots on the protected baseline, preserves existing HTML/source drafts and publication dates, archives the original catalog in private history, and returns unchanged when already converted. No migration occurs during public rendering. Existing catalogs remain readable for rollback; restoring legacy history makes the editor offer conversion again. No live content or private history is part of the public distribution.
