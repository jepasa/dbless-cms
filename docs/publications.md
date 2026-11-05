# Unified publications

Posts is the sole bundled editorial plugin, with one localized Publications workspace for pages, articles and custom post types. Pages and Blog packages and their public assets are removed in 2.21.0; their preserved URLs, provider identifiers and administrative APIs are internal compatibility adapters owned by Posts. They are not installed extensions and have no independent activation, installation or removal. Updating code never rewrites installation content or configuration. New installations enable Posts; older settings/activation are combined in memory until an explicit owner setting is saved. The content remains JSON; this is not WordPress API/theme/plugin compatibility.

## Types and URLs

Open Settings. The Publication types card uses the standard table, with New publication type on the right and an X of 20 capacity counter in its footer. The 20-type limit includes Pages and Articles. Creation and editing open a keyboard-accessible modal; a successful save reloads the settings list, while validation or revision errors retain the modal fields. At capacity, creation is disabled and rejected by the server, but editing and permitted removal remain available. IDs remain immutable and creating an already existing ID is rejected.

A type has an immutable ID, editable singular/plural labels, page/article rendering behavior and optional groups, tags, hierarchy/order and article discussion. The rendering behavior cannot change while the type is used. Built-in Pages keep discussion disabled. Referenced types/terms cannot be removed.

Default Page addresses remain `/slug`; default Article addresses retain `/plugins/blog/article?slug=slug`. Additional types default to `/posts/type/slug`. Root mode uses `/slug` with global collision protection. Custom mode accepts a literal prefix (up to three safe lowercase segments), such as `news` or `publications/news`; each record supplies its own slug. An available nonempty prefix also provides a type archive. No PHP paths, expressions, route callbacks or generated entrypoints are accepted.

Changing a prefix validates static/reserved routes, every draft/publication/schedule and existing alias before writing. Former public friendly paths remain bounded aliases and redirect to the current published address. Legacy article query links remain supported. An unpublished/deleted/future-only publication never becomes public through an alias. A URL that exists only in a working draft or unreleased schedule returns 404 even when the same record already has a different published URL; an earlier published address remains a redirect after the scheduled snapshot becomes due. Configure the separate public search path (default `/search`) here too. Deployments must adopt the supplied Apache/Nginx equivalent controller routing; the development router already supports nested paths and keeps the `page` pagination query separate from `content_path`.

Apache uses the distributed `src/public_html/.htaccess`. For Nginx, retain the existing exact front-controller/FPM locations, plugin dispatcher and PHP/upload execution restrictions. The public `location /` equivalent tested with Nginx/FPM is:

```nginx
location / {
  rewrite "^/([A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+){0,3})/?$" /index.php?content_path=$1 last;
  try_files $uri $uri/ =404;
}
```

This fragment assumes the public document root and a site installed at `/`; installations under a base path must adjust the location/rewrite accordingly. Query arguments are preserved, so `/news?type=article&page=2` keeps pagination separate from the friendly route. Do not replace the complete server configuration or relax its private/PHP restrictions with this routing fragment.

## Editing and legacy sources

Opening a page/article is a read-only projection with a deterministic unified ID. The first explicit save/lifecycle operation adopts it into `system/storage/contents/posts/catalog.json`. Native source files/IDs/history remain intact; old source writers reject adopted identities. Use the unified editor for subsequent changes. Original source recovery is available as a draft; history never silently republishes older content. Home, 404 and Homepage retain their dedicated contracts.

The editor reuses the Core visual/code runtime and synchronized JSON envelope `{html, bindings, content}`. Native content and original Blog blocks are retained, while published snapshots stay independent from working drafts. Invalid input remains available for correction. Conflicting bindings across legacy blocks fail explicitly rather than flattening different scopes silently.

Save draft, Preview, Publish and Schedule are independent actions. Dates are UTC; the due snapshot is chosen during reads without writes or cron. Pending review stays private. Trash, recovery, duplication and history preserve current publication isolation. All mutations require an authenticated session, CSRF, catalog/site/source revisions and the shared lock.

## Groups, tags, authors and discovery

Groups reuse hierarchical Blog categories; tags are flat and authors are editorial profiles. They do not create administrator accounts. Assignments belong to individual snapshots. Different filters combine with AND; comma-separated tag IDs combine with OR. Selecting a group includes its descendants. Public search uses literal text in published titles, summaries and authored plain text, without evaluating dynamic providers to build an index. Private working text, pending comments, history and future-only publications are excluded. Page size follows `settings.contentPageSize` and immutable IDs break date ties.

The public search view has independent public template copy. `adminLocale` controls only administrative UI/tutorials. Essencial demonstrates navigation to search and provides a `partials.posts` receiver; other templates can declare their own receiver or use the plugin's default fragment. Article rendering/comments preserve the existing template integration; the global discussion switch and publication options now reside in Posts → Settings, and moderation resides in Posts → Comments. Comments remain pending until explicitly approved.

## Public data and export

`posts:records` accepts bounded `q`, `type`, `group`, `tags`, `limit` and `page` parameters and returns only public summary fields plus pagination. Automatic `posts.latest` uses the same query. Single selectors accept a unified immutable ID or a unique slug; ambiguous slugs return unavailable. Existing `pages.*`, `blog.*`, document sources and Menu links remain compatible.

```mustache
{{#posts.latest.type.article.page.1.items}}<article><h2><a href="{{url}}">{{title}}</a></h2><p>{{summary}}</p></article>{{/posts.latest.type.article.page.1.items}}
```

`/plugins/posts/feed` and `/plugins/posts/sitemap` contain only currently visible indexable records. Existing Blog feeds/sitemap/POST comment endpoints remain supported. Public export includes own published/scheduled snapshots and their literal public media, replacing exported working copies with an authorized snapshot; drafts, trash, history and comments do not ship. Adopted native records are suppressed in legacy exports to prevent resurrection. The catalog is capped at 700 owned records/8 MiB, types at 20, groups at 100, tags at 200 and author overrides at 50. These are finite bounds, not an unlimited-scale search service.

Posts owns all page editor/article assets, discussion settings and moderation. Legacy `pages`/`blog` aliases preserve bookmarked administrative routes, CSRF/CAS-protected APIs, content/document services, native history and public URLs without retaining either package. The plugin manager refuses alias lifecycle operations. Existing source catalog paths remain private compatibility storage, not installed code. Legacy article activation remains respected; page visibility continues to follow the Core publication state. A pre-consolidation Posts configuration inherits enabled source activation and legacy settings in memory; an explicit save of the complete Posts configuration makes subsequent owner activation choices authoritative. No read migrates configuration or editorial records. `php src/system/bin/install-posts.php --enable` explicitly enables the owner under lifecycle/content locks; it never replaces content or credentials. Search-path settings can be adjusted while Posts is inactive to resolve an existing `/search` page before activation. Recovery from trash returns a draft and requires explicit republication.

The unified History screen can also restore validated original JSON/Page-publication/Blog backups directly into the unified draft. It keeps the current publication and native files unchanged. Unused original Blog blocks remain recoverable privately; they are excluded from authored publication/export snapshots. Literal media references using the reserved `{{cms.base}}/` prefix are exported under the same containment/MIME checks as ordinary public references.

Native page schedules retain an optional `releaseAt` gate independently from the preserved editorial `publishedAt` timestamp. Adoption/export preserve both; due-time selection never makes an unreleased scheduled body visible merely because its editorial timestamp is older. The Special pages screen and route are removed from Posts in 2.21.1; existing home/404 documents, their public rendering and legacy compatibility contracts are preserved. Homepage retains its own composition workflow.

Adopting a record from a disabled legacy source keeps it private until explicit publication. Saving a working draft or changing a type prefix cannot confer public availability on that source; original files and history remain unchanged.

If an inherited default search path conflicts with a legacy page or reserved URL, the existing destination is preserved and public search uses `/plugins/posts/search` until a valid friendly path is configured. Changing that setting does not create an alias that captures the legacy address.
