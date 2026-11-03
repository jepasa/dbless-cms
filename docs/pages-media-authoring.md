# Pages, shared media and data authoring

Pages creates independent drafts from Blank, Institutional, Content or Landing models. Duplication is a separate command and starts unpublished. New records receive an immutable ID; their public URL can change. Publishing a changed URL retains old addresses as redirects. Parent relationships are cycle-checked; selecting a parent does not change the URL structure automatically.

Save draft preserves the live snapshot. Publish promotes the saved version; Schedule authorizes a saved version for an explicit UTC instant. Public reads resolve that instant without writing storage or running cron. Unpublish cancels current/future publication. Trash and revision recovery never republish a deleted page. Existing ordinary pages are adopted only on an explicit editorial mutation; original files are retained. Home and 404 remain protected. Legacy administrative APIs remain available for unmanaged pages; managed pages require the draft workflow.

The catalog offers search, status filters, ordering, hierarchy and pagination. Pages settings accepts 25, 50 or 100 items per page. Metadata separates editorial title/description/image from SEO and social overrides. Empty overrides inherit the editorial values; image fallback reaches global settings. Canonical URLs are validated and robots can request noindex. The illustrative search preview does not predict search-engine output. Existing keywords are preserved but not promoted as a ranking feature.

## Shared media

Global Settings → Identity defines `settings.media.public` relative to `public_html`, and `settings.media.private` relative to `storage/files`. Omitted settings use the legacy Images roots in memory. Saving global roots does not move existing files; paths already embedded in content keep their meaning. Empty roots remain supported for legacy installations, with code directories excluded. Symbolic links, traversal and reserved code locations are rejected.

Images owns upload, replacement and file administration. The Core picker only lists and selects. Pages, Homepage, Blog, Slides and global image fields use the same paginated modal, list/grid views, folder navigation, search, type filter and sort. Selection remains available when Images is disabled.

For public content, declare `data-media-field="image"` on an input, or call:

```js
const selected = await DBLESSMedia.open({ type: 'image' });
// selected.value is a public-root-relative storage reference.
// selected.url is an addressed preview URL; do not persist session URLs.
```

Trusted private plugin PHP can issue `MediaLibrary::configuration($store, $language, $base, 'private')`, and pass that configuration to `DBLESSMedia.open({config, type:'all'})`. Public callers must use the default public capability. The server binds capabilities to session, visibility and roots; a client-side scope flag cannot grant private access. Private selections return `reference: {scope, path}` and `value: null`; their temporary authenticated stream URL must not be saved or published. A private consumer must implement its own authenticated document/storage contract. Public HTML editors reject administrative/private URLs even if pasted manually.

The shared WYSIWYG supports image and video insertion from the picker or a remote HTTPS address. Local insertion stores a portable relative URL. Arbitrary iframe embeds, scripts and styles remain forbidden in editorial HTML. Remote assets are loaded by the browser only; the server never downloads them. Rendered HTTP(S) links receive `noopener noreferrer`, retaining existing rel tokens; images use `referrerpolicy="no-referrer"` and public responses use `Referrer-Policy: same-origin` (no referrer to remote origins, preserving same-origin form checks). These attributes do not replace URL or HTML validation.

## Data contracts and layout responsibility

`contentServices` API 1 declares bounded parameters and a closed result schema. Providers supply plain data and never presentation HTML. `formats: []` declares a binding-only service; legacy paragraph markers require a registered format. The receiver owns HTML/CSS, escaped variables, sections and inverted sections. Arbitrary code, helpers, recursive expansion and unescaped interpolation remain forbidden.

Pages adds `records`, `details`, `children` and `breadcrumbs`. Records/children accept bounded limit/page and title/updatedAt ordering; other queries accept an immutable page ID. Their result is `{items,total,page,pages}`. Each item contains `id,title,summary,url,image:{url,alt},parentId,createdAt,updatedAt,isCurrent`. Only published snapshots appear; unknown legacy dates are empty strings. The older `pages:list` contract remains unchanged. Blog, Menu, Slides and Widgets keep their established public data contracts.

Example binding:

```json
{"plugin":"pages","service":"records","alias":"directory","parameters":{"limit":6,"page":1,"sort":"title","direction":"asc"}}
```

Receiver-owned layout:

```html
{{#cms.sources.directory.available}}
<ul>{{#directory.items}}<li><a href="{{url}}">{{title}}</a><p>{{summary}}</p></li>{{/directory.items}}</ul>
{{#cms.sources.directory.empty}}<p>No published pages.</p>{{/cms.sources.directory.empty}}
{{/cms.sources.directory.available}}
{{^cms.sources.directory.available}}<p>Content temporarily unavailable.</p>{{/cms.sources.directory.available}}
```

The field catalog inserts typed variable/section snippets. Preview reports unavailable providers without exposing provider data or filesystem paths. Empty and unavailable are separate states. Essencial's `mustache-example` demonstrates this contract for new installations; no example is installed over existing content.

## Storage, export and integration

Managed Pages lives in `contents/page-publications/catalog.json`; private history is under `storage/page-history`. A single atomic catalog publication and site/catalog revisions prevent partial transitions and stale saves. Limits are 200 managed records, 8 MiB catalog, 20 recovery entries per page, hierarchy depth 12 and 50 retained URL aliases. Pagination does not remove these limits.

Content export includes authorized published/scheduled snapshots, replacing their working copies in staging. It excludes deleted/unpublished managed pages and private history. Literal local image/video references in exported authored content are copied explicitly from the configured public root after media validation; unrelated uploads and private repositories are never mirrored. Dynamic media comes from the exporting provider's own contract. Missing or unsafe literal media fails export. Remote URLs are retained without fetching.

Templates receive optional `pageMetadata` containing title, description, canonical, robots, socialTitle, socialDescription, image and imageAlt. Essencial implements it. Proprietary templates must implement this presentation contract in their owning repository. Shared changes follow development → validated public distribution → pinned consumer, preserving runtime content and configuration. A public PR awaiting protected-main review is a candidate, not a stable release.

## Pages editorial workspace (2.11.1)

Pages creation and editing share a WordPress-style workspace: a compact action
bar, prominent title, continuous writing surface and attached properties inspector.
Geometry and controls follow that editorial organization; colors retain the
DBLESS-CMS palette. The Core WYSIWYG remains the content editor. This first
evaluation applies only to Pages; Blog and Homepage retain their existing layouts.

Write content before the first save. Page properties group publication, address
and parent, featured image, summary, SEO and advanced options. The inspector can
be toggled from the toolbar and closes with Escape; it starts closed on narrow
screens. Models require an explicit apply action and confirmation before replacing
nonempty content. Dynamic bindings and native controls remain available in advanced
sections. Saving creates/updates a private draft; publishing and scheduling use
the saved draft and are disabled while the working form has unsaved edits.

The **JSON** and **HTML/Mustache** tabs above the editorial body select its format.
The latter contains the Core Visual/Code/Preview controls. Switching tabs retains
both working buffers without converting one format into the other; saving uses
the active format. Reopening selects the saved format. Arrow keys, Home and End
move between the format tabs.

Both editorial formats fill the available content column on wide screens, with
normal control gutters and the separate properties inspector.
