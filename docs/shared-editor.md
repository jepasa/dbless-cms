# Shared content and code editor

DBLess-CMS provides one core editor for plugins. HTML and Mustache support visual editing, source editing and a read-only template-CSS preview. PHP, CSS and JavaScript are source profiles: language support never executes PHP, evaluates JavaScript, writes server scripts or grants publishing permissions.

## Existing plugins

Homepage, Pages and Blog use the shared HTML/Mustache control. The original textarea and `{html, bindings}` remain the saved contract. Global supplemental CSS/JavaScript use the same source editor while retaining their own validated drafts, revisions, history and explicit publishing workflow.

Visual editing patches individual static content blocks, including static siblings before and after dynamic loops. Administrative controls follow the panel dictionary; the canvas content language/direction follows the public site locale. Dynamic sections, Forms markers and trusted template fragments remain protected; edit their structure in Code. Valid markup without a supported visual representation remains available in Code. No migration or automatic rewrite runs when a document opens. A no-edit round trip preserves original bytes. Only the explicitly edited block may receive canonical HTML serialization.

## Plugin integration

The administrative frame loads the local assets and metadata. Use the shared PHP `system/admin/markup-editor.php` fragment for HTML with typed bindings. For a plain textarea mounted by trusted plugin JavaScript:

```js
const field = document.querySelector('textarea[name="content"]');
const editor = window.DBLESSEditor.mount(field, {
  language: 'mustache',
  visual: true,
  mustache: true,
  readOnly: false,
  height: 420,
  toolbar: ['bold', 'italic', 'link', 'image', 'list', 'paragraph', 'heading', 'quote']
});
await editor.flush();
const originalSource = editor.getValue();
editor.setValue(originalSource);
```

Available profiles: `html`, `mustache`, `php`, `css`, `js`. The `mustache` profile has HTML as its language and enables the supported Mustache subset. `DBLESSCMS\Editor::profile($language)` returns equivalent server-side metadata. A textarea may declare a readable JSON `data-editor-options` attribute instead. Unsupported options/profiles fail rather than enabling arbitrary behavior. `visual`/`mustache` are invalid for non-HTML languages; height is 220–1200 pixels. `readOnly` prevents editing but is not an authorization mechanism.

```js
window.DBLESSEditor.mount(cssTextarea, { language: 'css' });
window.DBLESSEditor.mount(jsTextarea, { language: 'js' });
window.DBLESSEditor.mount(phpTextarea, { language: 'php' });
```

PHP source can be displayed, highlighted, edited and retrieved as text, including mixed HTML. The plugin must define its own safe storage policy. The core does not offer an endpoint to execute, upload or publish arbitrary PHP. HTML editorial fields continue to reject PHP and scripts. Use `get(field)` to retrieve an existing instance and `destroy()` to remove the UI without rewriting the textarea. Existing `DBLESSMarkup.mount/create/code` integrations remain supported. `insertMarkup(source)` appends an explicitly selected validated integration block; binding cards expose an Insert block action. Clipboard HTML preserves supported inline formatting; unsupported markup falls back to plain text. Enter splits ordinary paragraphs/headings without changing unrelated source.

## Content rules

Variables are escaped by the existing core renderer. Bindings discover enabled providers and their schemas; no provider names are hardcoded into the visual runtime. Triple braces, executable helpers and recursive data expansion are unsupported. `{{cms.base}}`, data loops/conditions, `{{forms:form id="contact"}}` and declared `{{template:fragment id="contact"}}` retain their existing contracts. The canvas does not render live provider data, submit forms or persist session tokens.

Use image addresses relative to the public site root or same-origin absolute addresses; provide alternative text, leaving it empty only for decorative images. Remote/unresolved image sources are not fetched by the canvas. Rights/attribution records remain managed by the Images plugin; the editor does not infer image licenses.

## Security and operations

The HTML allowlist comes from `ContentMarkup::policy`; server validation always applies. The visual canvas has an opaque sandbox, restrictive CSP and a per-instance window-checked channel. Template CSS is loaded only in read-only preview; public template scripts and administrator-authored scripts never execute in the editor. Preserve normal session/CSRF/CAS and lifecycle rules in every plugin mutation. Saving waits for queued visual input; synchronization failure is reported without submitting stale content.

Code mode keeps the original buffer available for correction. Tab indentation is opt-in and Escape restores keyboard navigation. Client diagnostics do not replace server validation or PHP/JS/CSS checks appropriate to a plugin's workflow. The core cache remains parse-only; editor state, rendered HTML, provider values and tokens are not cached.

The editor implementation is original DBLess-CMS code under the distribution license. Quillit ME was studied as a product reference; its source, WPM presets, installers and unspecified license are not redistributed.

## Qualification

Run `npm run test:browser:editor` with `PLAYWRIGHT_MODULE` pointing to an existing authorized local Playwright installation. Optional `DBLESS_CMS_PACKAGE_ROOT` repeats source journeys against an isolated production package. Fixtures use synthetic credentials/content and ephemeral loopback ports. No browser/dependency download or live-content migration is performed.

Flush acknowledgements use an instance-generated UUID v4 and a fixed native event. Only the exact outstanding token completes its lexical promise; message identifiers never select callable methods or callbacks. Window, opaque origin, channel and generation checks remain mandatory.

Canvas source serialization is text-only: it restores attributes in a plain map without assigning URLs to detached DOM clones. Unresolved Mustache image addresses never trigger requests during rendering, selection, flushing or editing. Original attribute/token order is preserved.

## Toolbar and editor appearance

Version 2.8.1 uses DBLess-CMS ink/mint colors with segmented Visual/Code/Preview modes. Original locally addressed SVG icons identify grouped history, formatting, block and insertion commands. Titles and accessible names follow the selected administration dictionary. Undo/redo availability reflects actual visual history; formatting commands are hidden in Code/Preview.

Arrow keys (including RTL direction), Home and End move between enabled toolbar controls; Tab leaves the toolbar. Code-only PHP/CSS/JavaScript profiles use the same header, language identifier and compact diagnostic/indentation footer. Groups wrap on small screens without removing controls. Existing language/toolbar/readOnly options and all content/publication/security contracts remain unchanged. No external editor library or icon service is required.

Core metadata provides a closed action-to-icon map from `cms-admin/assets/editor-icons/`; plugins keep using the editor API instead of embedding SVG or depending on toolbar markup. This presentation change does not alter API version 1 or the accepted options.
