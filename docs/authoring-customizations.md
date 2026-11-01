# Shared authoring and global site customizations

Pages and Blog reuse the core HTML/Mustache editor already used by Homepage. In Pages → New Page, choose a URL identifier and initial native structure, enter individual title, description, keywords, featured image and image description, choose draft/published, and write the HTML sections manually. Save creates a fully validated document atomically. Ordinary existing pages expose the same metadata fields and an optional HTML/Mustache checkbox; unchecking returns to preserved native content. Advanced native editing, JSON, history and previous APIs remain available. Required home/404 documents retain their dedicated behavior.

New Blog articles default to HTML/Mustache. Existing typed-block articles open in their original mode; one-markup-block articles open in HTML mode. The content-mode selector preserves both unsaved representations in the editor but saves only the selected mode. Saving HTML mode replaces the article body with one markup block: switching an existing multi-block article to HTML is an explicit replacement, not automatic conversion. Article SEO, author, taxonomy, draft status and UTC scheduling remain independent fields. Empty authored bodies are allowed only as drafts. Limits: Pages HTML 128 KiB; Blog's serialized blocks (including HTML and bindings) 64 KiB.

Use the provider picker to discover enabled plugins, services, typed parameters and result schemas. Add a binding alias and insert the generated loops manually. For example, bind Pages `list` to `related`, then write:

```html
<section class="related-pages">
  <h2>Related pages</h2>
  {{#related.items}}<a href="{{href}}">{{title}}</a>{{/related.items}}
  {{^related.items}}<p>No pages yet.</p>{{/related.items}}
</section>
```

Provider text is escaped; no PHP, script tags, inline handlers, executable helpers or recursive expansion are accepted in editorial HTML. Data is resolved fresh on each render, including when structural page caching is enabled. Plugin presentation pages document their own result fields.

## Global CSS and JavaScript

Open Templates → Global customizations. This is core functionality, independent of template selection. Each editor has syntax highlighting, line numbers and optional Tab indentation (Escape releases Tab). The original textarea remains the submitted source. Enable CSS/JS independently, save a private draft, compare it with the published sources, preview, then publish explicitly. A publication affects complete public HTML documents, including public-plugin documents. CSS follows template styles; JavaScript uses a deferred external script. Admin pages, private previews, fragments, feeds, JSON, XML, downloads and redirects are excluded.

Use relative-to-site URLs such as `/media/image.png` and template-neutral selectors. Published pages expose `body[data-template="essencial"]` for optional scoped rules. Switching templates keeps customizations. Original CSS, JS and SCSS files are never edited.

Private sources/catalog/history live in `system/storage/customizations/`; enabled published files live in `public_html/customizations/site-<sha256>.css` or `.js`. A revision conflict rejects changes. Keep the submitted draft in the browser while resolving the conflict; reopen another tab to compare. History retains 20 previous states; Restore copies a previous draft into the current draft without publishing it. Publish again to apply recovery. Old immutable public URLs are retained for in-flight clients; include them in storage planning/backups.

Each source is limited to 128 KiB and UTF-8. PHP validates closed data shapes, CSS delimiter balance and unsafe bytes. The locally vendored Terser parser provides advisory JavaScript syntax diagnostics without executing source in the admin. This client check is not server-side JavaScript authentication or semantic validation. An administrator who submits invalid JS outside the normal editor may publish it; production export rejects syntax errors. PHP-only hosting needs no Node for normal editing. Node is used by the explicit release exporter to validate/minify enabled published assets and recompute filenames from the actual minified bytes. CSS imports remain browser references: the compiler never inlines filesystem or network imports. Readable published sources remain private; draft/history/orphan files do not enter that export.

Published JavaScript is trusted site code and can access public-origin data; install only reviewed code. There is no sandbox for published code. Keep existing CSP and avoid inline code/eval. Draft preview is different: an authenticated response and an iframe both impose an opaque-origin sandbox without forms, same-origin privileges, popups or navigation. Connections are denied, real administrator session/CSRF values are excluded, and draft assets are base64-encoded resources inside the preview only. Template features requiring storage, network, submission, relative CSS resources or navigation may not work there. Validate the intended behavior in a synthetic local public installation before release.

Backup/restore/upgrade preserve private customization state and public immutable files. Export packages include only the published state. Shared core/plugin changes follow the upstream-first distribution inventory workflow. Custom site sources remain installation data and must not be committed as shared examples or copied from a developer's live storage.

## Local qualification

Run `npm run verify` for source syntax/contracts and unit/integration tests. Export explicitly with `npm run export:production -- --out /tmp/dbless-cms-review-package`, using synthetic contents/configuration rather than live storage. Run `DBLESS_CMS_PACKAGE_ROOT=/tmp/dbless-cms-review-package npm test` to compare source and export. The local Chromium journey is `node tests/browser/authoring-customizations.mjs`, with `PLAYWRIGHT_MODULE` pointing to an existing authorized Playwright installation and optional `DBLESS_CMS_PACKAGE_ROOT`. It creates its own loopback fixtures, disables mail, blocks external requests, records screenshots under a temporary evidence directory, and never edits the current site's data. No Playwright/browser download is performed.

The opaque preview can also block local SVG masks and fonts through CORS because its origin is `null`. These resources remain addressed by their public URLs; no SVG is embedded and no same-origin permission is granted. Such preview-only diagnostics are recorded separately in browser evidence and each affected URL must load successfully from the anonymous public context. Any other admin/public error still fails qualification.

Privacy controls, interactive Forms, public image credits and their boundaries are documented in [Privacy, Forms and image provenance](privacy-forms-media.md).
