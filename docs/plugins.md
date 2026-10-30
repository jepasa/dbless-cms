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
  hello.svg
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
`entry: "Plugin.php"` and a namespaced class such as `DBLESSCMS\\HelloPlugin`.
The directory and ID must agree; IDs are lowercase letters/digits/hyphens, beginning
with a letter. Use `DBLESSCMS` as the PHP namespace and `DBLESS_CMS_*` for environment variables.

`assets.css` must point to `css/<name>.css`; `assets.js` to `js/<name>.js` inside the
matching public tree. Both files must exist even when JavaScript behavior is unnecessary.
Production resolves exported minified files automatically.

`assets.icon` is required and identifies a static SVG relative to the plugin's own
public directory. The default naming convention is `<plugin-id>.svg`, for example
`"icon": "slides.svg"`. A different filename, such as `"icon": "branding/slider.svg"`,
is resolved from the manifest; the core never guesses a global icon by the plugin's
translated label. IDs remain stable even when labels say Images or Sliders.
The sidebar and plugin catalog use addressed `<img>` URLs, never embedded SVG.
Missing icons, external/absolute paths, traversal, symbolic links and active SVG
markup make the package invalid. Use static geometry and retain its license.
The old `navigation.icon` global alias is deprecated and does not override `assets.icon`.

When upgrading an existing custom plugin, install its SVG and updated manifest together.
The API remains 1; extensions must comply with this media requirement to be discovered.


`defaults` must validate against the local `settings.schema.json`. Use bounded types,
required fields and `additionalProperties: false`. Settings fields must provide valid
translation keys for their titles. Keep defaults additive and compatible with older
stored settings; reading defaults must not silently rewrite user data.

## 5. Implement the plugin interface

`Plugin.php` implements `DBLESSCMS\PluginInterface`:

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
settings implementation and `DBLESSCMS\PluginOptions`; do not invent an activation field.
Old settings schemas remain subject to type/enum/translation validation.

## 9. Public plugin routes

Implement `DBLESSCMS\PublicPluginInterface` and its
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

## Managed ICO uploads

Images accepts ICO files containing PNG frames, up to 1 MiB, in addition to the
previous upload formats. Container offsets, frame bounds, PNG framing/CRCs, dimensions
and final bytes are checked; MIME validation and PHP/HTML/SVG rejection remain active.
DIB/BMP-frame ICO is not supported. ICO files can be previewed and selected as the
global site favicon by their path relative to public_html, regardless of the public
root configured for Images. Private storage never supplies a public favicon.


## 12. Run the bundled Blog

The Blog plugin (`blog`, API 1, version 1.0.0) is included with DBLESS-CMS 2.1.0.
New installations include generic example articles and editorial profiles. Existing
installations keep their content; install the plugin code and addressed public assets,
then enable it in the plugin catalog. The optional CLI equivalent is:

```bash
php src/system/plugins/blog/install.php --enable
```

This command merges only Blog activation/defaults under the installation's lifecycle
and content locks. It never installs examples over existing content, changes credentials
or enables other plugins. An existing installation can start with an empty blog.

### Write and publish

Open **Blog → New article**. Set a descriptive title and URL slug, summary, author,
category/tag references and optional cover with meaningful alternative text. Create
editorial profiles and topics under **Categories, tags and authors**. Author profiles
are bylines, not administrator accounts. Categories support up to five levels without
cycles; an entry cannot be removed while any article or child category references it.

Build the body using paragraph, h2/h3 heading, quotation, local image/caption, ordered
or unordered list, link and code blocks. The Images selector reuses the authenticated
public library and its configured upload root. Private files never become image choices.
Images may also be entered as paths relative to `public_html`; UTF-8 names and spaces
accepted by Images are preserved and URL-encoded when rendered. External media is not
fetched. Code is displayed as escaped literal text; raw HTML, scripts and embeds are
not article markup. The editor supports keyboard reordering and preserves entered
values after network, validation or revision errors.

Save as a draft, or select Published. An empty publication date publishes immediately;
a future **UTC** time schedules publication. Visibility is calculated on each request,
so no cron task is necessary. The server clock must be correct. Saved previews require
an authenticated session and return `noindex`; they are never public token links.
Duplicating an article produces a new draft. Soft deletion reserves its slug for recovery.
Changing a slug keeps up to ten previous slugs and redirects visible aliases with 301.

### Read and discover

The public surface uses the existing plugin front controller:

| Route | Purpose |
| --- | --- |
| `/plugins/blog/list` | Search, category/tag/author filters and bounded pagination |
| `/plugins/blog/article?slug=designing-with-clarity` | Article, topic links, author profile, contents index and related reading |
| `/plugins/blog/feed` | RSS 2.0 with stable article IDs and canonical URLs |
| `/plugins/blog/sitemap` | XML sitemap for the blog archive and visible indexable articles |
| `/plugins/blog/comment` | Protected comment POST only |

List parameters are `q`, `category`, `tag`, `author` and `page`. Category archives include
child categories; search is literal Unicode matching, not arbitrary regular expressions.
The newest articles are ordered deterministically, with featured articles first on the
unfiltered archive. Reading time uses the configured words-per-minute rate.

The public projection excludes drafts, future schedules and trashed articles. Feed and
sitemap also exclude articles marked noindex. Saved previews and searches emit noindex.
Article layouts include canonical, Open Graph article timestamps, Twitter metadata and
semantic BlogPosting microdata. Canonical URLs use Global Settings; no external request
is needed. Add the Blog sitemap URL to the hosting site's discovery configuration when
deploying; this plugin does not rewrite unrelated robots files or page sitemaps.

### Configure only functional preferences

**Blog → Settings** uses the same Fields/JSON editor as Global Settings. It controls
blog title/description, articles per page (1–50), RSS limit (1–100), reading speed (80–500
words/minute), moderated comments and maximum comment bytes (100–4000). Activation stays
in the main plugin catalog. **History** is a separate dropdown route above Settings;
it contains editorial, moderation and plugin-option recovery, all revision checked.

### Moderate discussion

Comments are **off by default**. When enabled, readers submit a name, plain-text message
and consent to their eventual publication. No email address or commenter IP is stored
inside the comment document. The CMS rate limiter maintains its separate private state.
Every comment starts pending; only approved comments on currently visible articles are
rendered. Administrators can approve, return to moderation, mark spam, move to trash
and restore to pending. Removing an article hides its comments without deleting them.

The form uses the core session/CSRF/origin/body controls, a separate quota of five
submissions per ten minutes, a honeypot and duplicate detection. These controls do not
promise complete spam prevention. There is no mail notification or third-party moderation
service. Review comments and privacy obligations appropriate to the actual installation;
comments and their histories are retained until deliberately managed, not silently purged.

### Storage, export and scale

`<contents-root>/blog/catalog.json` stores articles/categories/tags/authors;
`<contents-root>/blog/comments.json` is separate. Both are private, UTF-8, indented JSON
with schema version 1, closed typed fields and SHA-256 revisions. Writes use the CMS
transaction, secure same-directory temporary files, fsync/rename, a revision-only audit
and up to twenty private backups per document in `<storage-root>/blog-history/`.

The catalog is limited to 500 articles, 100 categories, 200 tags, 50 authors and 4 MiB;
an article has at most 100 blocks and 64 KiB of block JSON. Comments are limited to
1000 entries/4 MiB. Limits fail explicitly; they are not an unlimited-scale promise.
Queries run in memory. A materially larger workload needs a separately designed storage
adapter or retention strategy, rather than raising limits without measurement.

Production export keeps the validated non-trashed editorial catalog **private**, including
drafts/schedules for continued editing. Only the public projection is readable through
public routes. It copies explicitly referenced regular raster images with extension,
MIME and dimension checks; it rejects traversal, symlinks and forbidden payloads. It does
not copy arbitrary uploads, comments, histories, sessions, credentials or installed state.
Backups/upgrades must preserve existing private content and uploaded media as documented
in the template deployment guide. A first-install export must not overwrite an existing
site's editorial repository.

Portable verification is in `tests/blog.test.mjs` and `tests/browser/blog.mjs`. Run
`npm run verify`; the browser test accepts `PLAYWRIGHT_MODULE` pointing to an authorized
local Playwright installation and creates private unpredictable evidence directories.
All fixtures contain synthetic data; never run editor/upload tests on a live site.
