# Use and develop plugins

Plugins are trusted PHP extensions with independent manifests, routing and admin
dictionaries. They share the fixed admin design and the CMS security contracts.
They are not isolated processes or safe containers for untrusted code.

## 1. Use the bundled plugins

Open the account dropdown → Plugins. The table lists discovered packages, descriptions,
status and icon actions. Activate/deactivate a plugin there; activation is not a setting
inside its configuration page. A Configure action exists only when the plugin has a
functional configuration screen.

The sidebar is built from each enabled plugin's manifest. Dropdown items open integrated
pages, not nested iframes. Pages, Menu and Slides provide editor/history/trash workflows.
Files Manager additionally provides upload-root settings. History is its own route.
Uninstalling archives private code and retains content/assets for recovery; take a backup
before installing, removing or replacing any extension.

### Pages

Use New Page, choose a model supported by the active template, edit fields or structured
JSON, save a draft and preview it before publishing. Required home/404 pages remain
published. History restores a previous revision; Trash restores recoverable deletions.
The template schema determines editable fields—page content cannot execute PHP or HTML.

### Menu

Create a set of items, choose page/anchor/external destinations, nest submenus, then
select a location offered by the active template's manifest. Navbar, sidebar, list and
dropdown formatting depend on that location. Dynamic items and associations are stored
by the plugin, not in the manifest. Missing locations after a template switch produce
warnings while preserving earlier associations.

### Slides

Create a banner/product/testimonial/notice/logo collection, add and order items, preview
images, publish the collection and assign a compatible location. Images can be selected
from public File Manager uploads. A placement can be a static template key or a controlled
marker/block inside a page; the editor identifies the available template and page context.
The template owns layout and interaction, including keyboard controls and reduced motion.

### Files Manager

Use the toolbar to create folders and upload files, switch list/grid views, select an
item for preview/rename/move/download, or move it to Trash. Trash uses soft deletion;
restoring it keeps recoverable content available. Public and Private are separate scopes.

Configuration → Uploads contains two relative roots:

- Public defaults to `media`, confined to `public_html/`. Empty means that public root.
- Private defaults to `media`, confined to `system/storage/files/`. Empty means that private root.

A private file must never be exposed by a public static URL. Do not enable script execution
in either upload area. Use synthetic files when testing traversal, MIME checks, quotas,
revisions and restoration. User-edited content and uploads are never committed to Git.

## 2. Install an extension manually

Install matching private/public trees:

```text
src/system/plugins/hello/
  plugin.json
  Plugin.php
  Router.php
  settings.schema.json
  languages/pt-br.json
  languages/en-us.json
  pages/overview.php
  pages/welcome.php
src/public_html/plugins/hello/
  css/hello.css
  js/hello.js
```

Private PHP scripts must remain outside the public root. Copy the pair, inspect its
source, then open Plugins to verify discovery before enabling it. Invalid manifests
are rejected. Never solve discovery problems by disabling validation or moving private
scripts into public folders.

## 3. Install the complete Hello example

The optional [Hello example](../examples/hello/system/plugins/hello/plugin.json) is a
working minimal package included for learning; it is not enabled by default.
From the repository root:

```bash
cp -R examples/hello/system/plugins/hello src/system/plugins/hello
cp -R examples/hello/public_html/plugins/hello src/public_html/plugins/hello
```

Open Plugins and activate Hello. Its sidebar item opens an integrated overview; its
link opens `/plugins/hello/welcome` anonymously. Deactivate it and confirm that the
public route becomes unavailable. Change the example's default message before its
first activation, or implement proper revisioned settings if the message should be
editable after installation.

Read Plugin.php, Router.php and both page scripts together. The example validates its
settings, rejects unsupported API operations, scopes its CSS, escapes output and uses
a limited public settings allowlist. Copying only the manifest is not sufficient.

## 4. Manifest/API contract

Use API `1` and `cmsMajor: 2`. Required identity includes `id`, `name`, `version`,
`entry: "Plugin.php"` and a namespaced class such as `CMS2JSON\\HelloPlugin`.
The directory and ID must agree; IDs are lowercase letters/digits/hyphens, beginning
with a letter. `CMS2JSON` remains the compatibility PHP namespace.

`assets.css` must point to `css/<name>.css`; `assets.js` to `js/<name>.js` inside the
matching public tree. Both files must exist even when JavaScript behavior is unnecessary.
Production resolves exported minified files automatically.

`defaults` must validate against the local `settings.schema.json`. Use bounded types,
required fields and `additionalProperties: false`. Settings fields must provide valid
translation keys for their titles. Keep defaults additive and compatible with older
stored settings; reading defaults must not silently rewrite user data.

## 5. Implement the plugin interface

`Plugin.php` implements `CMS2JSON\PluginInterface`:

| Method | Responsibility |
| --- | --- |
| `validateSettings(array $settings, Config $config): void` | Validate settings; throw a controlled error on invalid input |
| `render(PluginContext $context): string` | Return the private page fragment for the selected route |
| `api(PluginContext $context, string $operation): ?array` | Execute an authorized private operation, return data or stream deliberately |

Require your own Router.php from the entry file and dispatch only fixed registered
routes. Keep initialization side-effect free; discovery must not execute plugin code.
Unexpected errors should propagate to the core's safe error handling. Do not swallow
exceptions or report success after a failed write.

## 6. Register private routes and sidebar navigation

Each route has a translation-key `title`, a regular `pages/<name>.php` file and a boolean
`requiresEnabled`. Declare `visibility: "private"` explicitly; omission also means private.
The manifest declares `router: "Router.php"` and a `routes` object. The Hello manifest
is a complete example.

A single sidebar button uses `navigation.label` plus `navigation.route`. A dropdown
uses `navigation.label` plus `navigation.items`, each containing `label` and `route`.
Use `{"separator": true}` between groups; consecutive/leading/trailing separators and
public routes in navigation are rejected. Navigation titles are translated by the
plugin dictionary. Literal package name/description metadata is not translated.

For content-management plugins, use Overview/New/History/Settings/Trash only when those
screens have a real function. Keep History above Settings, separated into its own group;
Settings belongs above the Trash separator. Activation stays in the plugin catalog.
Do not add empty configuration screens or locations pages whose choices belong in an editor.

`PluginContext` contains validated settings, configuration revision, base URL, language,
route and CMS services. Use its `url()` helper to build private operation URLs. Session,
CSRF and activation checks belong to the core; operation-specific validation still
belongs to your plugin. A write requires POST and the current `csrf_token`; validate
its own inputs and revision before touching persistent data.

## 7. Share the admin design

When `integrated` is true, return a fragment inside the CMS workspace. Do not return
html/head/body, another global h1, a new sidebar, an iframe or global theme overrides.
Use admin components and variables such as `--ink`, `--surface`, `--line` and `--accent`.
Scope custom CSS to a unique root class, as the Hello example does.

Keep action icons accessible with localized title/aria-label text, support keyboard
focus, narrow screens and reduced motion, and render SVGs only through local addressed
files. Plugin CSS/JS must not depend on a public site's template assets or external CDNs.

## 8. Dictionaries and functional settings

Each plugin owns `languages/<locale>.json`. The baseline `pt-br.json` and other locales
must share the same message keys and placeholders. A dictionary identifies `locale`,
`name`, `direction` and `messages`. Add new locale files manually and verify compatibility
with the admin's selected language. Keep source text out of route handlers when it is
an admin control; look it up through `$context->language->text()` and escape the result.

If a plugin has no useful settings, declare `configuration: false` and omit that menu
item. For an actual settings screen, `configuration` is the ID of a declared private
route with `requiresEnabled: false`. The route should use the same field groups, help,
revision checks and save bar as Global Settings. Refer to the bundled Files Manager
settings implementation and `CMS2JSON\PluginOptions`; do not invent an activation field.
Old settings schemas remain subject to type/enum/translation validation.

## 9. Public plugin routes

Implement `CMS2JSON\PublicPluginInterface` and its
`publicPage(PublicPluginContext $context): string` method. Register only the routes that
are intentionally public with `visibility: "public"`, `requiresEnabled: true` and allowed
`methods` (`GET` and/or `POST`). Page files stay private. A public POST may also declare a
bounded `maxBodyBytes`; the core enforces method, body limit, CSRF, origin and rate checks.

The public entry is `/plugins/<plugin>/<route>`, dispatched through one central controller.
It cannot use private routes or administrative API operations. A disabled plugin returns
404. Use `$context->url()` for its public URL and `$context->csrfToken` in public forms.

PublicPluginContext deliberately excludes Config/JsonStore/credentials. `publicSettings`
is an explicit list of schema keys that may be exposed; all other settings stay private.
The Hello example exposes only its harmless message. Never put credentials, filesystem
paths, private download links or unfiltered admin data into that allowlist. This limited
context does not sandbox plugin PHP code: source trust remains mandatory.

## 10. Persist content safely

Use `<configured contents root>/<plugin-id>/`, normally
`src/system/storage/contents/<plugin-id>/`. Menus and Slides demonstrate independent,
revisioned repositories with schema validation, recovery and atomic publication.
Use the configured roots, not absolute machine paths. Do not store user documents in
your plugin's code directory or public asset tree.

For a new repository, validate IDs and data, reject symlinks/traversal, compare the
submitted revision with current bytes, hold the shared content transaction, create a
recoverable history record and publish by a complete same-filesystem atomic rename.
`$context->store->transaction(...)` coordinates content writes; inspect MenuStore/SlidesStore
for the complete pattern. Keep public/private file scope separate. A delete should be
recoverable; do not purge data as a side effect of uninstalling code.

## 11. Verify and package

Run `npm run verify`, install the example in a synthetic copy, and test activation,
private access, public access, invalid CSRF, stale revisions, errors and recovery.
Check both admin languages, responsive layout, keyboard operation, console and CSP.
Verify that a disabled plugin and an anonymous private request cannot execute operations.

Export explicitly with `npm run export:production` and test the package as well as the
source. Declare correct semantic compatibility, keep licenses/notices and exclude real
configuration, content, uploads, sessions, history and private development material from
a shared extension package. The public site receives only validated, published data;
plugin code must never transform JSON strings into executable templates or PHP.
