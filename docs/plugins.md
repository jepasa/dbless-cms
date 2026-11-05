# Use and develop plugins

Plugins are trusted PHP extensions with independent manifests, routing and admin
dictionaries. They share the fixed admin design and the CMS security contracts.
They are not isolated processes or safe containers for untrusted code.

Shared CMS core, bundled plugins and administrative layout changes are developed
and tested upstream before entering this distribution. Consumer installations
should update shared code from a validated distribution commit, record its
version/inventory hash and verify the copied files. Keep proprietary templates,
content, uploads and private configuration separate; do not patch the shared
administrative layout directly inside a consumer installation.

## 1. Use the bundled plugins

Open the account dropdown → Plugins. The table lists discovered packages, descriptions,
status and icon actions. Activate/deactivate a plugin there; activation is not a setting
inside its configuration page. A Configure action exists only when the plugin has a
functional configuration screen.

The sidebar is built from each enabled plugin's manifest. Dropdown items open integrated
pages, not nested iframes. Posts, Menu and Slides provide editor/history/trash workflows.
Files Manager additionally provides upload-root settings. Open it from its sidebar
dropdown; the topbar contains the public-site link and account menu. History is its own route.
Uninstalling archives private code and retains content/assets for recovery; take a backup
before installing, removing or replacing any extension.

### Pages compatibility inside Posts

The `pages` identifier below is a preserved internal adapter, not an installed plugin. New authoring uses [Posts](publications.md); old APIs retain their guarded contracts.

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
  presentation.json
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


## 12. Manage publications with Posts

Posts (`posts`, API 1) is the single bundled editorial plugin. Pages and Blog packages were retired in 2.21.0 after incorporating their functionality. See [Unified publications](publications.md) for post types, friendly routes, groups/tags, authoring, scheduling, search, discussion and preserved legacy URLs/providers/APIs.

New installations enable Posts. Existing configuration is projected without GET migration; an explicit owner settings save records subsequent choices. `php src/system/bin/install-posts.php --enable` offers optional explicit CLI activation and preserves content/history/comments/credentials. Publication and discussion options belong to **Posts → Settings**. Comments require CSRF, consent and server-side moderation, and remain pending until approval. No independent Pages/Blog activation or uninstall operation is available.

## Global settings and Widgets

The global editor contains three cards: Global, Identity and Contact. `site.json`
uses schema version 2 with a single `settings` map. Site name, slogan, description,
base/canonical URLs, locale, identity images, author/keywords/Open Graph image data
and contact country/state/city/phone/email/responsible person have one source of truth.
Public image paths are relative to `public_html/`; do not include an origin or leading
slash. The admin locale is discovered from installed JSON dictionaries. Template and
selected theme are read-only here; manage them through the Templates catalog.

Existing installations: back up the application, run
`php src/system/bin/migrate-global-settings.php --check`, then `--apply`. The CLI
uses maintenance/content locks, preserves the original site backup and imports old
social links into the Widgets plugin without replacing existing values. Retry safely
after interruption. Unknown legacy positions remain available for reassignment.

Templates declare only static positions, for example:

```json
"widgetLocations": {
  "footer-note": "Optional footer notice",
  "social-website": "Website profile link"
}
```

The private Widgets editor discovers keys from the active template. Each record
has Key, Value and Description, with one non-deleted record per template/key.
Values are literal UTF-8, not executable HTML or a general template engine. The
repository is `system/storage/contents/widgets/widgets.json`, with optimistic
revisions, soft-delete trash and twenty private history snapshots. Limits are 1000
records, 512 KiB per document, 12000 bytes per value and 500 per description.
Changing templates preserves values and warns about missing positions. Missing,
deleted or disabled values return the caller's fallback without public errors.

```php
<?php $notice = $widgets->value('footer-note'); ?>
<?php if ($notice !== ''): ?><p><?= \DBLESSCMS\e($notice) ?></p><?php endif; ?>
<?php $href = $widgets->link('social-website'); ?>
<?php if ($href !== ''): ?><a href="<?= \DBLESSCMS\e($href) ?>">Profile</a><?php endif; ?>
```

Always escape output contextually; `link()` additionally limits URL protocols and
rejects credentials, traversal and executable destinations. Do not insert widget
values into scripts, CSS or raw HTML. There is no public mutation endpoint.
Essencial demonstrates profile links and a footer notice. Export omits widget trash,
history and audit logs. Updates preserve existing installation state; examples are
installed only for a new installation.

A theme-capable template may declare `"themeFields":["appearance.palette",
"appearance.mode"]` to identify the selected options shown in global settings.
Paths refer to its validated configuration. Templates without this declaration
display an empty theme field. Theme options remain in the template configuration.

## Core Dashboard indicators

Dashboard and Cache are core services, not plugins. The Dashboard is the default
admin landing page and its link stays above plugin navigation. Cache has no
standalone screen: the fourth Global Settings card configures it, while the
Dashboard displays measured counters and offers an authenticated CSRF-protected purge.

An active trusted plugin may implement `DBLESSCMS\DashboardProviderInterface` in
addition to `PluginInterface`:

```php
/** Return actual, read-only administrative counts; receives private context, performs no writes, propagates source errors. */
public function dashboard(\DBLESSCMS\PluginContext $context): array {
  return [['label' => 'myplugin.dashboard.items', 'value' => $this->countItems($context)]];
}
```

All indicators from the same plugin are rendered inside one Dashboard card, grouped
by the manifest plugin ID rather than its display name. Labels and measured values
remain distinct within that card. Posts groups published publications, drafts and pending comments. Disabled, empty or failed providers
do not create empty cards or partial metrics. Providers return data, never card HTML.

Declare the label in every plugin dictionary. Return at most eight records with
`label` followed by a nonnegative integer `value`. No HTML, personal data, network
calls or writes; no placeholder statistics. The core escapes labels/values and
isolates provider failures with an unavailable warning. Providers are trusted PHP,
not sandboxed or forcibly time-limited. Posts demonstrates published articles and
comments awaiting moderation; scheduled articles are counted only when released.

`settings.cache` is optional and accepts `enabled` (false), `development` (false),
`ttl` (300 seconds, 1–86400), `maxMiB` (16, 1–256) and `maxEntries` (500, 1–2000).
Production detection remains operational configuration. Cache initially stays off;
development requires both booleans. Since 2.5.1 it stores only the bounded structural
JSON parse of page source, keyed by source bytes, contents-root identity and parse
format. Every read, including hits, reapplies current sanitization, schemas,
template contracts and plugin binding validation. No validation decision is
persisted, so a new plugin contract cannot be bypassed by a warm page entry.
Authored markup is private source data; rendered HTML, CSRF tokens, previews,
editor sessions, private responses and provider results are never cached.

Publishing schedules, linked-document publication/withdrawal and plugin/template
activation are resolved on every request. Optional provider failures do not return
a stale successful result. Per-composition memoization is not a cross-request
cache: build a fresh ContentContext/Components for each rendering. New providers
need only the documented manifest/interfaces, with no cache registration, custom
fingerprint or purge on save. Use JsonStore for CMS page documents and the shared
ContentMarkup/Components/ContentDocuments paths for dynamic content. Do not store
provider results, authorization, full HTML or selection decisions in Cache::remember.

Keep `system/storage/cache/` private and writable by PHP; exclude it from editorial
backups, source control and exports. Versioned/checksummed entries detect accidental
corruption and regenerate on misses; the checksum is not authentication against a
local plugin/code owner. JSON round trips preserve floating-point types. Existing
older entries expire/evict normally; no content migration or required purge.

Counters describe structural hits/misses in a rolling window (up to 24 hours),
including hits later rejected by changed live rules; rejected producers are not stored. They are not
traffic, validation-success counts or measured time saved. With no collection the
UI shows “No data”. Purging resets counters. Quotas exclude bounded metrics/lock
metadata. I/O failure or lock contention uses dynamic data; validation errors still
propagate. Validation deliberately runs on hits; no performance gain is promised.

Core/plugin HTML and error responses use `Cache-Control: no-store`, with no ETag,
Last-Modified or conditional 304 path. This service does not configure OPcache,
static assets, a CDN or reverse-proxy policy. Independently forcing HTML caching
can break freshness/privacy and is outside this contract. A rendered-output cache
would require a separately reviewed dependency, scheduling and privacy design.
These rules constrain integration; trusted PHP plugins are not sandboxed and must
not override response policy or retain request objects across requests.

Portable regressions: `node --test tests/cache-rendering.test.mjs`; add
`DBLESS_CMS_PACKAGE_ROOT` for an exported package. They exercise warm/cold changed
contracts, numeric types, corrupted/obsolete records, exact scheduling boundaries,
linked Pages withdrawal/recovery, all current public data providers, a newly
installed synthetic provider, optional failures/recovery and no-store HTTP headers.

## Local security regression laboratory

Extension authors can run `npm run pentest -- core` for synthetic password,
session, CSRF and evidence checks. `npm run pentest -- full` also runs the available
CMS contracts, real Chromium framing/repeated-write/XSS probes and three focused
PHP Semgrep rules. Set `PLAYWRIGHT_MODULE` to an already installed local Playwright;
Chromium defaults to `/usr/bin/google-chrome` (`CHROMIUM_EXECUTABLE` overrides it).
Docker must already contain the Semgrep image pinned in `scripts/pentest.mjs`.
No tool, image or dependency is downloaded by the laboratory. No target URL is
accepted. All HTTP fixtures bind to loopback on ephemeral ports; mail is disabled.

The CLI prints the location of private JSON/Markdown reports and sanitized logs.
A missing tool, empty/incomplete test output, skipped test or deadline cannot pass.
`release` additionally exports versioned examples and repeats package tests/browser;
its TLS qualification requires the private deployment laboratory. A public checkout
without those fixtures reports BLOCKED, rather than claiming release qualification.
Keep evidence outside the public document root. Review `manual` and `limitations`.
Run `npm run security:audit` separately when registry access is authorized.

The catalog references OWASP ASVS 5.0.0, WSTG 4.2 and final NIST SP 800-63B-4;
these selected tests do not certify full compliance or hosting security. Plugins
are trusted local code and still require source review. Session CSRF tokens are
reusable by design; revision checks prevent conflicting writes. Do not replace
those checks with button disabling or a purported single-use CSRF policy.

New passwords are provisioned through private `system/bin/hash-password.php` via
STDIN using Argon2id, 16–200 Unicode code points and independent salts. The offline
whole-value common-password list is a limited baseline, not a complete compromised
password corpus. Existing credentials are not rewritten or silently normalized.
Legacy bcrypt verification rejects inputs exceeding 72 bytes; affected operators
must explicitly reprovision an Argon2id credential. Never transmit a password in
CLI arguments, query strings, logs or public configuration.

Official references: [OWASP Password Storage](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html),
[OWASP CSRF](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html),
[NIST authenticators](https://pages.nist.gov/800-63-4/sp800-63b/authenticators/).

The source distribution bundles scanner rules and its non-executed positive-control
fixture under `scripts/pentest/`. Consumer installations can copy the CLI directory
with its inventory without installing CI workflows. These developer files must
remain outside `public_html` and are excluded from the production runtime export.

## Homepage and shared components

See [Homepage and shared content components](components.md) for manifest service declarations, provider interfaces, template presentations, reusable Widget sets, private drafts and plugin-owned English presentation pages.


For shared raw-data Mustache, authored HTML, document-source contracts and Homepage publications, see [Components](components.md). PHP ext-dom is required for authored HTML. Plugin About presentations document each public schema in English.

On a miss, the producer validates the source before it can be stored. Its fresh normalized result is returned directly. A hit reapplies all current rules to the original parsed source rather than to an already transformed result. Rejected new origins are not stored or counted as completed preparation misses.

See [Shared authoring and global customizations](authoring-customizations.md) for typed Mustache bindings in Pages/Blog. The public front controller adds published global assets to complete HTML only; providers should return data or fragments without injecting global assets themselves.

The public response boundary accepts only a declared `PublicPluginInterface` implementation and its validated, read-only public context. Returned HTML is trusted plugin-owned view markup; the core does not turn raw request HTML into safe content. Escape each request/editorial field with `e()` in the appropriate HTML context, validate URLs, and use `ContentMarkup` for authored fragments. Never return raw query/form values as an HTML document. The controller owns status/headers and the optional global-asset decoration; plugin views retain responsibility for contextual escaping.

Privacy controls, interactive Forms, public image credits and their boundaries are documented in [Privacy, Forms and image provenance](privacy-forms-media.md).

See [Shared HTML/Mustache and PHP/CSS/JS editor](shared-editor.md) for the reusable core authoring profiles.

## Images explorer (2.10)

Images offers a connected folder tree, sortable Name/Type/Size/Added-on columns,
thumbnail grid, current-folder search, type filters and a shared icon-only action
bar/context menu. Every action has a localized tooltip and accessible name. Use
right-click, Shift+F10, or the visible item actions button. On narrow screens the
table scrolls inside its container, preserving all column headings.

The optional `browser.pageSize` setting accepts 25, 50 or 100 (default 25). Legacy
settings saves preserve the current preference. Sorting/filtering precede paging;
the server still scans the bounded directory and calculates revision hashes.
Existing limits remain 1,000 entries, 250 MiB per directory and 10 MiB per file.

MP4 videos are checked for MIME, executable markers and bounded ISO-BMFF framing;
codec playback is browser-dependent. No transcoder or external service is used.
Image/video previews use authenticated, no-store endpoints in both storage scopes.
Added dates are recorded privately for new managed items, preserved through moves
and replacement, and shown as unavailable for untracked legacy files.

Replace requires confirmation, a valid session/CSRF token and a current directory
revision. It retains the destination name/path and matching extension, validates
new bytes and archives the old bytes in private trash before atomic publication.
Free the original destination before restoring an archived version: recovery never
silently overwrites a current file. Changed bytes invalidate the existing public
legacy rights resolution. Existing public references remain readable; image-rights administration and its metadata/derivative endpoints have been removed. No copyright approval is inferred.
Public URLs keep their names; external browser/CDN caches are outside this action's
control. No live data, date indexes or replacement archives belong in distribution.

Action icons come from the core `Icons` mapping, using addressed local SVG files.
New admin actions must reuse that mapping and localized title/aria-label labels.

### Images history

History describes the latest recorded file operations (up to 100 from a bounded
private-log tail), in the selected admin language, with UTC timestamps and storage
area. Old logs contain hashed targets; the UI does not invent filenames. Restorable
configuration copies carry a description and explicitly identify that their
originating action was not recorded. Reads never migrate or create history.
Image-rights administration is removed, including its route, toolbar/context
action and write endpoints. Existing private records and read-only public asset
references remain available for compatibility; no stored data is deleted.

## Required presentation and sidebar order

Every plugin, including data-only plugins, declares `"presentation": "presentation.json"`. Store that English tutorial beside `plugin.json`; see [the presentation contract](components.md#plugin-owned-presentations) and the Hello example. Older packages must rename their presentation and add a nonempty `steps` array to each section before upgrading. Missing or malformed tutorials reject discovery.

Mark the private trash navigation item with `"role": "trash"`; route IDs remain plugin-owned. The Core places Presentation before Trash, with separators, or last when there is no trash role. It automatically promotes a single-item menu to a dropdown. Presentation uses the authenticated Core route and requires no executable plugin page.

## Localized presentation documents (2.19)

Every plugin still declares `"presentation": "presentation.json"` and keeps that canonical English document beside `plugin.json`. Do not duplicate an identical English edition under languages. Optional translated tutorials are separate from operational message catalogs:

```json
{
  "presentation": "presentation.json",
  "presentationLocales": {
    "pt-br": "languages/presentation-pt-br.json"
  }
}
```

Use the project's normalized lowercase locale identifiers with hyphens, not underscores. A map supports at most 20 entries; each path must match exactly `languages/presentation-LOCALE.json`. The Core never scans request-supplied paths, includes files as PHP or fetches remote documents. Language discovery reserves these filenames and does not parse them as `locale/name/direction/messages` dictionaries. Declare every offered translation in the manifest; unlisted documents are not selected.

The canonical document retains `language: "en"`, title, introductory lead and 1–20 sections with title, body paragraphs, nonempty ordered steps and optional inert code. Each file is at most 64 KiB; existing text/array limits remain. Opting into presentationLocales requires a unique stable `id` on every canonical section. A translated document uses its exact locale as `language`, supplies the translated title/lead/sections, and preserves the ordered section IDs. For example:

```json
{
  "language": "pt-br",
  "title": "Example",
  "lead": "Introdução ao recurso.",
  "sections": [
    {
      "id": "start",
      "title": "Começar",
      "body": ["Descrição do recurso e seus requisitos."],
      "steps": ["Abra o plugin e siga o procedimento indicado."]
    }
  ]
}
```

Use the same `start` section ID in the corresponding English document. Translations may omit code: the Core inherits the canonical example by section ID. If code is included, its bytes must match the English example; technical identifiers and source programs are not translated. This avoids copying examples into every language and detects stale outlines. Menu and the Hello example demonstrate both files.

The Core selects the exact `settings.adminLocale` entry. A missing translation falls back to canonical English with a localized administrative notice (English regional locales may use the base English source without a notice). A missing, malformed, linked, oversized or structurally stale declared translation also produces a visible notice and safe English fallback, without disabling a plugin whose canonical source is valid. Invalid canonical English or unsafe declaration metadata remains a package error. English fallback keeps LTR direction even in an RTL panel. Text and code remain escaped; reading a tutorial never invokes the plugin entrypoint or writes editorial state.

Older plugins without presentationLocales or section IDs remain compatible, including the legacy optional dictionary projection for a matching locale. A message-catalog fallback in a different locale is not used to replace the English document. New/migrated plugins should keep only controls, navigation and diagnostics in their dictionaries. This release migrates Menu and supplies a Hello example; other bundled plugins keep English until optional documents are supplied, with explicit fallback diagnostics. Returning to an older Core that does not accept section IDs requires restoring matching plugin packages; no content migration is performed by this feature.

## Unified Publications (Posts)

Posts unifies the Pages/Blog workspace with configurable publication types, literal URL prefixes, groups/tags and public search. It uses the optional `replacesNavigation` manifest list (at most eight plugin IDs), effective only while the replacement plugin is enabled. This hides sidebar entries without deleting routes/providers. See [Publications](publications.md) for activation, explicit adoption, capabilities, filters, compatibility and export.

Optional `dependencies` declares at most eight installed plugin package IDs. Dependency declarations do not enable packages or fetch/install code. Uninstalling a package required by another installed plugin is rejected under the shared lock; remove the dependent plugin first. Posts owns its editor integration, rendering and moderation. Pages and Blog are retired package identifiers resolved through validated internal `compatibilityAliases`, without separate installation, activation or catalog entries. The distribution contains no Pages/Blog plugin directories. Shared article/comment data readers remain in the Core so content reading does not depend on an installed administrative package.

## Source documentation

New or changed code documents its Structural Elements with the language's native DocBlocks/docstrings: File-level/Page-level, Namespace/Module-level when declared, Class/Interface/Trait-level, Function/Method-level and Property/Constant-level. Keep documentation next to the element and describe its purpose, responsibility, types/contracts, parameters, return value, effects and errors as applicable. Promoted properties also require property documentation. Keep comments accurate when behavior changes.

Use Inline/Internal Comments, Inline Comments and Block Comments for decisions, invariants, compatibility, security boundaries and non-obvious flow. Explain why the code exists or why an alternative is unsafe; avoid restating obvious statements. This documentation supports human maintenance without requiring unrelated mass rewrites. JSON and other formats without comments use their schema or associated guide; never insert comments into those documents.
