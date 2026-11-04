# Homepage and shared content components

Version 2.5 supports two distinct authoring contexts: existing colon markers use template-owned component partials; authored HTML in Homepage, Pages and Blog uses explicit raw-data bindings plus escaped Mustache variables/loops. The initial sections below document the compatible legacy marker contract. See “Authored HTML and raw public data” for the current data-authoring workflow.


Homepage is a plugin. Component discovery, marker parsing and service dispatch are core services, shared with Pages. This is a bounded marker syntax inspired by Mustache, not a general-purpose Mustache engine. No dependency or runtime template execution is installed from content.

## Author content

1. Open Homepage → Edit Homepage. Edit native content/metadata with Fields or JSON.
2. Add content sections, write a title and separate paragraphs with blank lines. Arrange sections with the accessible arrows; optional native sections can be hidden.
3. Use Insert component. Select a plugin, service, typed parameters and a supported presentation. Reusable Widget keys and sets come from Widgets, not template positions.
4. Save draft. The published homepage remains unchanged. Preview opens the last saved draft with authentication and noindex headers.
5. Publish from Overview. Concurrent homepage/global-setting/draft changes are rejected. Explicitly start from the published version to archive a stale draft; History restores published revisions.

```text
{{blog:latest limit="3" category="" layout="cards"}}
{{widgets:set key="example-highlights" layout="list"}}
{{slides:location key="home-hero" layout="cards"}}
{{menu:location key="footer-links" layout="list"}}
A literal inline value: {{widgets:value key="example-message" layout="text"}}
```

Block markers occupy their own paragraph. Brace text without a plugin/service namespace remains literal for compatibility; it is not variable interpolation. Text services may be inserted inline in paragraph text, never into HTML attributes, scripts or CSS. Widget/provider values are not parsed again. Quote values with double quotes; parameters cannot contain quotes, braces or line breaks. There are at most ten parameters per marker, 2,048 bytes per marker, twenty components per document/request and 128 KiB per provider response. Limits are enforced independently of the editor.

Legacy `{{slides:location-key}}` markers and typed Slides blocks remain compatible. Existing positional Widgets remain supported. Reusable records and ordered sets are additive to the Widgets catalog; trash/history/revisions apply to all three catalogs. Unknown or inactive component references are preserved, diagnosed privately and omitted publicly. A provider failure logs a bounded code/class diagnostic and omits that component; it does not fabricate data.

## Register a plugin service

A trusted API 1 plugin optionally implements `DBLESSCMS\ContentProviderInterface`:

```php
/** Return bounded public data for the declared read service; do not write or make network calls. */
public function content(string $service, array $parameters, \DBLESSCMS\ContentContext $context): array {
  return ['items' => [['title' => 'Example', 'text' => 'Public value', 'href' => '/', 'image' => '']]];
}
```

Declare `contentServices` in `plugin.json`. Each service has exactly `api` (1), `description`, `context` (`block` or `text`), `parameters`, `result` and `formats`. Parameter/result schemas use the core's explicit JSON Schema subset: object/string/integer/boolean/array, closed properties, required fields, bounded strings/lists/numbers, enum and optional scalar defaults. Marker parameters are scalar; `layout` is reserved for the presentation format. Text services return a string `value` and declare only the `text` format.

```json
{
  "contentServices": {
    "featured": {
      "api": 1,
      "description": "Public featured items.",
      "context": "text",
      "parameters": {"type":"object","additionalProperties":false,"required":[],"properties":{}},
      "result": {"type":"object","additionalProperties":false,"required":["value"],"properties":{"value":{"type":"string","maxLength":200}}},
      "formats": ["text"]
    }
  }
}
```

Discovery validates manifests without executing entrypoints. Resolution calls only the interface's fixed method after checking service registration, activation, parameter schema and template support. It validates the returned data before rendering. Providers are trusted local PHP with CMS permissions, not sandboxed code. A renderer service is never an authorization mechanism for mutations.

An optional `ContentOptionsInterface::contentOptions(service, context)` supplies `parameter => [['value'=>string,'label'=>string]]` choices after authentication. This private editor step is separate from metadata discovery and public resolution. It returns at most 100 choices per parameter, no content bodies or credentials.

## Add template presentations

Declare `componentLayouts` as `plugin:service → format → registered partial key`. Example:

```json
{"componentLayouts":{"blog:latest":{"cards":"component-cards"},"widgets:set":{"list":"component-list"}}}
```

The keys must reference existing manifest `partials`; content never supplies a PHP path. The partial receives `$componentData`, `$componentParameters`, `$base_url`, `$store`, `$id`, `$manifest` and `$code`. Escape every editorial value for its context. The bundled Essencial `component-cards.php`, `component-list.php` and `css/components.css` are working examples under Apache-2.0. Templates can adapt their markup/styles independently.

Declare optional `homepage` metadata with `sections` (key → `{name, partial, required}`) and `sectionPartial` (registered partial key). Iterate `HomepageLayout::entries($manifest, $page)` from the template's home page. Native section definitions are static; the optional page `homepage` object stores `order`, `hidden` and custom `sections` (`key`, `title`, `body`, `visible`). Custom keys start with `section-`; required native sections cannot be hidden. Do not copy site identity/contact values into a second source.

Essencial implements native sections as registered partials. A legacy template keeps its native homepage intact and receives appended portable sections; the editor warns that native ordering needs an adapter. A template can wrap its existing page in one required native partial to adopt composition without rewriting its design. Missing old native section references are preserved and reported after a template switch. The fallback Essencial contract remains validated at publication.

## Installation and operational state

Published content remains `system/storage/contents/pages/home.json`. Private per-template drafts use `system/storage/contents/homepage/<template>.json`. Preview and editor are private routes, never public mutation endpoints. Publication is one core page commit under shared lock/CAS with existing revision backup. A failed save does not change the live homepage. After a successful publish the saved draft may remain as the last working copy, recognized as equal to the publication; the next editor session explicitly uses the current revision. Draft resets archive the prior draft privately.

New installations enable Homepage in the versioned examples. On an existing installation, activate it in Plugins or run the additive local command `php src/system/bin/install-homepage.php --enable`. The command preserves existing settings/content and uses the revisioned plugin registry. Do not copy example content over an existing installation.

Production exports include only published page/composition and live Widget records/sets. Drafts, private reset archives, history and trash never ship. The production build remains explicit; development uses `src/`.

## Plugin-owned presentations

Every plugin must declare `"presentation": "presentation.json"` and ship that regular private JSON file beside `plugin.json`, including data-only or disabled plugins. Missing declarations/files or invalid documents reject catalog discovery; older custom plugins must migrate their `about.json` and add tutorial steps before upgrading. The document contains English `language: "en"`, nonempty `title`, introductory `lead` and 1–20 `sections`. Each section has a `title`, explanatory `body` string array, a nonempty ordered `steps` string array and optional literal `code` example. Arrays are bounded to 20 entries, strings to 12,000 bytes and the document to 64 KiB. No arbitrary HTML/Markdown rendering, includes or executable links are supported.

The Core escapes every value, renders headings, paragraphs, numbered steps and code, and generates a local contents navigation. The tutorial fills the available workspace and remains available when its plugin is disabled, without executing the entrypoint. Presentation text stays English; sidebar/control labels follow the administrative locale. Each dropdown ends with a separated Presentation link, followed by a separator and Trash only when an item declares `"role": "trash"`. The Core supplies the presentation destination; plugins must not duplicate it as a route. At most one menu item may have the trash role, with any valid private route ID. Keep existing operational routes and translations in the plugin manifest/dictionaries.

Maintain each tutorial alongside functional changes: describe the purpose first, then give the actual sequence for creating, editing, configuring, publishing, recovering and reusing data where available. Include runnable public-data examples with explicit binding/parameter prerequisites and no credentials or private content. Bundled presentations and the Hello example illustrate the complete schema.


## Authored HTML and raw public data (2.5)

PHP 8.2+ with **ext-dom** is required for authored HTML. Run the doctor before deployment. No browser editor CDN or additional runtime package is used.

Homepage, Pages (optional root `markup`) and Blog (typed `markup` blocks) share `ContentMarkup`, `Mustache` and `Components`. Bindings are explicit JSON:

```json
{"html":"<section>{{#news.items}}<h2><a href=\"{{href}}\">{{title}}</a></h2>{{/news.items}}{{^news.items}}<p>No posts.</p>{{/news.items}}</section>","bindings":[{"plugin":"blog","service":"posts","alias":"news","parameters":{"limit":3,"category":""}}]}
```

Aliases identify service data, not arbitrary PHP methods. Discover supported schemas and native typed parameters from each enabled plugin manifest. Supported raw services: `blog:posts`, `pages:list`, `menu:tree`, `slides:data`, `widgets:records`; existing `blog:latest`, `menu:location`, `slides:location`, `widgets:set` and `widgets:value` remain compatible. See each plugin's English About presentation for its fields and examples. Private Images listings are deliberately not a public service.

Variables are HTML-escaped. Sections iterate arrays or conditionally expose objects/true scalars; inverted sections handle empty/false/missing values. Dotted paths and `{{.}}` are supported. Zero is truthy. Returned strings are literal and never recursively parsed. Triple braces, partials, helpers, lambdas, dynamic includes and delimiter changes are rejected. Semantic HTML is allowlisted; scripts, events, inline CSS, forms, frames, SVG, PHP and unsafe URLs are rejected both before and after interpolation. Templates own CSS and trusted scripts. URL interpolation must resolve to a valid public URL.

Limits: 128 KiB source, 1,024 tokens, eight section levels, 100 items per loop, 10,000 work units, 512 KiB output per fragment, 1 MiB accumulated output and 20 uncached provider fetches per request. A binding list has at most 20 distinct noncolliding aliases. Provider JSON is at most 128 KiB and must match its closed schema. Failed optional providers yield empty data and bounded diagnostics; malformed authored syntax is an explicit error. These are resource bounds, not a sandbox for locally installed PHP plugins.

## Homepage publications

The private catalog is `system/storage/contents/homepage/publications/catalog.json`. Draft and published snapshots are separate. Saving a draft never publishes it. UTC scheduling is resolved per request: newest due enabled snapshot wins; ID breaks equal timestamps. No cron or catalog write is performed on a public request. A valid prior publication, then the protected native homepage, provides fallback. A missing source never exposes unpublished content. Global settings provide homepage metadata. Local time entry is converted to UTC; nonexistent local times are rejected. For ambiguous clock transitions, use UTC to choose an exact instant; local entry otherwise follows the browser time-zone convention.

`documentSource: {"api":1,"description":"Published document bodies."}` opts a plugin into `ContentDocumentInterface`. `documents()` lists stable published IDs privately; `document()` returns only `{name,description,html}` with validated body HTML. No arbitrary routes/functions execute from a reference. Pages and Blog implement it. The Pages common semantic adapter does not duplicate all template-specific extras. Cycles/depth are bounded. Disabling/uninstalling the Homepage administrative plugin preserves the core publication reader and existing published catalog.

Export includes only published nondeleted records (including scheduled snapshots), replacing each exported draft with its published snapshot. Private drafts, history and trash never ship. Back up the private catalog before upgrades; no live content is migrated automatically. The original per-template 2.4 drafts remain accessible in History and old markers retain their parser.

The local textarea editor adds line numbers and inert highlighting; Tab capture is opt-in and Escape releases it. Saved snippets are inserted manually. Public data is refreshed on the next uncached service resolution/request; this is not a background live-update subscription.

The legacy section editor edits only the native `pages/home.json` fallback; a newer effective catalog publication retains precedence. Saving a legacy draft returns to its editor, where authenticated preview and explicit legacy publication remain available.

## Cache and freshness

A warm page cache stores only parsed source JSON. Current validation, Homepage
selection, linked documents and public service results are resolved on every
request; a 24-hour TTL does not delay scheduled publication. Providers do not
register cache fingerprints or purge entries after mutations. Only composition-local
memoization is used for repeated calls during one render. Never reuse a Components
instance across requests. Optional failures yield empty data or the prior valid
Homepage, with no stale successful response. HTML responses remain no-store.
See the Cache contract in the plugin development guide for extension obligations.

## Editorial field parity (2.13)

Pages stores optional editorial author, numeric order and private review state per snapshot. Raw records/details/children/breadcrumbs expose only the published author/order; records and children accept `sort: "order"`. Page layouts are limited to declared active-template scripts whose native content contract matches. Publication dates support backdating and future scheduling without changing the global theme. Missing metadata is defaulted in memory, with no GET migration.

Blog adds pending review and per-article `allowComments`, defaulting to true for old catalogs. The global discussion switch and existing moderation remain authoritative; closing an article preserves approved comments while blocking new submissions. Existing author, categories, tags, featured sorting, metadata, revisions and trash remain unchanged. Private/password content, Page comments and pingback/trackback features are separate scope. Blog displays the active rendering template; alternate post formats are conditional on declared template support.

## Automatic references (2.14)

Write references directly in Core HTML/code editors. No plugin/service/alias picker or bindings JSON field is needed. Existing saved bindings are preserved invisibly. Standard sections iterate finite arrays or test presence; inverted sections handle absence. Zero is present. Arbitrary expressions, SWITCH, WHILE, functions and recursive evaluation are not supported.

- `{{blog.my-post.title}}`, `{{pages.about.summary}}`: one published record by ID or slug.
- `{{#blog.latest.limit.20.page.2.items}}<a href="{{url}}">{{title}}</a>{{/blog.latest.limit.20.page.2.items}}`: newest publication first, ID tie-break. Pages supports the same query. Limit caps total results; page is one-based; global `settings.contentPageSize` (default 12, range 1–100) caps each page. Omit limit for all available items. Out-of-range pages are empty. Metadata: items/total/page/pages/pageSize/hasNext/hasPrevious. Multiple collections use independent explicit page parameters.
- `{{forms.contact-example}}`: securely rendered published form, configured in Forms. Private previews remain inert; tokens are request-scoped.
- `{{#slides.highlights.items}}<img src="{{image}}" alt="{{alt}}">{{/slides.highlights.items}}`: published set. The editor owns maximum items, interval, autoplay and transition.effect/duration/orientation. The template implements appearance and motion, respecting reduced motion.
- `{{#menu.main.items}}<a href="{{href}}">{{label}}</a>{{/menu.main.items}}`: public tree. Nested children sections provide finite submenu levels. Menu defines its navigation type; existing location contracts remain compatible.
- `{{widgets.contact}}`: text or validated HTML according to the reusable widget type. `.value` accesses text; `.data.email` accesses a JSON field. JSON is not implicitly serialized. Provider strings/HTML are never evaluated again as Mustache.

IDs are technical plugin IDs, not translated labels. IDs/slugs contain no dots. Named parameters are unique and precede fields. Explicit `plugin.id.<value>` / `plugin.slug.<value>` disambiguate query names and identity collisions. Menu/Slides/Forms use one slug-shaped immutable identifier; Widgets uses ID/key; Pages/Blog use ID/slug. Immutable keys cannot be renamed; stale mutable slugs produce preview diagnostics. JSON widget keys start with a letter and use letters/digits/underscore/hyphen; arrays support numeric indexes.

Plugins declare `mustache` with api=1, kind=data|widget|form, closed query parameter schemas and top-level fields, and implement ContentReferenceInterface::reference. Core validates bounded JSON and parameters. No caller selects a PHP method, file or executable expression. Data must exclude drafts/private values. Missing fields/providers produce private preview diagnostics. Maximum 20 distinct queries per request, 100 entries per collection, bounded depth/tokens/output; no persistent rendered cache.

Template positions use `{{template.footer-note.value}}` and require an explicit declaration:

```json
{"mustacheSlots":{"footer-note":{"plugin":"widgets","location":"footer-note","description":"Footer note"}}}
```

The matching widgetLocation/menuLocation/slideLocation must exist. Existing source-plugin editors assign content to it. Trusted PHP templates pass a literal fragment to `ContentMarkup::render(['html' => '{{template.footer-note.value}}'], $contentContext)`; Essencial's footer demonstrates it. Never run another pass over assembled HTML or provider output. Direct editorial references require no position declaration.

Widgets HTML uses the editorial allowlist, without scripts/styles/forms. Forms retain their separate interactive renderer, CSRF and request isolation. Remote links receive noopener/noreferrer. Invalid source remains available for correction. Reads never migrate real data. Rolling back after saving new metadata requires matching code and content snapshots; older code may reject newer fields.

## Pages: one document in JSON and HTML

Pages uses a single editing document: `{"html":"<p>Content</p>","bindings":[],"content":{...}}`. JSON edits and the Core visual/code editor share exactly the same `html` source. `content` preserves the original native data; editing it does not reconstruct the authored HTML. Metadata remains in the properties sidebar. Invalid JSON blocks switching/saving without replacing either saved data or the invalid buffer. Native standard editorial fields are initially projected as portable semantic HTML; layouts may need styling in their template. Native Slides blocks and legacy component markers remain live source integrations. Registered providers/layouts render them per request through bounded slots; rendered provider output is never persisted. Unsupported custom block types fail explicitly. Opening never writes storage. Save adopts the authored document; publication uses that saved snapshot.

## Shared administrative overview tables

Overview catalogs use Core `AdminTable`: each module supplies fixed route parameters, translated column labels and read-only scalar projection callbacks, allowed state labels, and an optional state/search projection. Core validates bounded scalar query controls, filters, sorts with a stable ID tie-break and slices only matching rows. No query value selects a method, callback or storage path. Existing administrative sizes are preserved; previously unpaged catalogs default to 25. This does not change public/Mustache pagination.

`toolbar()`, `headings()` and `footer()` share styling and accessible labels. The footer always has seven positions: previous page, earlier-page menu, previous number, current number, next number, later-page menu, next page. Ellipses open upward, scrollable keyboard-accessible menus. Empty/out-of-range controls remain disabled. Totals and displayed range/count appear below the centered pager. The complete table/grid and footer remain in normal document flow: vertical scrolling belongs to the page, and the pager follows the last result without covering it. Wide tables retain horizontal scrolling. `AdminTable::pager()` and `DBLESSPager.render()` reuse the same presentation for asynchronous file results; specialized media navigation remains intact.

Pages is one flat list with Parent and Order columns. Every matching page is counted and paginated independently. Clicking Parent filters direct children; Clear removes that filter. Creation/editing still supplies Parent and Order; URL/menu/public hierarchy contracts are preserved. There is no hierarchy-view selector.

## Exclusive Menu and Slides delivery (2.16)

Menu and Slides records may declare `insertionMode: "template"` or `"mustache"`. Template mode resolves only through assigned positions, for example `template.slides-home-hero.items`; direct ID/slug aliases are unavailable. Mustache mode resolves direct `slides.promotion.items` or `menu.navigation.items` data and disables all location-based access. The layout still owns markup and finite section iteration. The editor persists this mode and reads the active template name dynamically. It offers only `mustacheSlots` of the matching plugin whose existing locations accept the record type.

Records without the field retain legacy reads until explicitly saved. Existing assignments remain stored when inactive or outside the active template; GET never migrates them. Old page-marker selection is retired; existing published markers remain readable through the location contract. Invalid/stale/undeclared new selections are rejected atomically.

Unresolved qualified simple references remain escaped literal source, such as `{{slides.missing.name}}`, with private diagnostics. Valid null/false/zero/empty fields are preserved. Missing collections do not iterate; inverted sections can render alternatives. Invalid syntax/parameters/undeclared template slots still fail validation. No arbitrary expressions, recursive expansion or provider-output evaluation is introduced.

## Portable Menu keys (2.17)

Menu editor v2 stores optional `templateKeys: [{"key":"primary-menu","type":"navbar"}]` on the record. Keys do not contain a template ID. Any receiving template declaring that Menu key in mustacheSlots can map it to its own typed menuLocations entry. Format/depth incompatibility or absent keys returns unavailable data and private admin notices without preventing save. Malformed contracts and stale menu revisions still fail. One active portable owner claims a key atomically; inactive keys and old per-template assignments remain recoverable. Legacy reads remain available only for records without portable keys. Inactive/deleted portable claims do not resurrect shadowed legacy menus.

Use `template.primary-menu.items` for a declared position or `menu.navigation.items` in Mustache mode. Known saved Menu keys absent from the active manifest resolve as unavailable through the Core, retaining literal simple references and empty-section semantics; unrelated undeclared template keys keep their validation boundary. Native PHP location consumers use the same portable resolution. Conflicting aliases for a native physical location return no ambiguous menu and produce admin notices.

The affected Menu editor and tutorial use plugin dictionaries in the selected admin locale. Menu presentation JSON remains canonical English; dictionary projection translates its fixed text and preserves inert code examples. Other plugins retain their existing presentation contract until separately adapted.
