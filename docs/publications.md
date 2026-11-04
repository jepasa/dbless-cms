# Unified publications

Posts adds a localized Publications workspace for Pages, Blog articles and custom post types. Activate it explicitly in the plugin catalog. It replaces the two original sidebar entries while retaining their endpoints/providers and restoring their navigation when disabled. Updating code never edits installation activation, content or configuration. The content remains JSON; this is not WordPress API/theme/plugin compatibility.

## Types and URLs

Open Types and settings. A type has an immutable ID, editable singular/plural labels, page/article rendering behavior and optional groups, tags, hierarchy/order and article discussion. The rendering behavior cannot change while the type is used. Built-in Pages keep discussion disabled. Referenced types/terms cannot be removed.

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

The public search view has independent public template copy. `adminLocale` controls only administrative UI/tutorials. Essencial demonstrates navigation to search and provides a `partials.posts` receiver; other templates can declare their own receiver or use the plugin's default fragment. Article rendering/comments preserve the existing Blog integration, global discussion switch and moderation; comments remain pending until explicitly approved.

## Public data and export

`posts:records` accepts bounded `q`, `type`, `group`, `tags`, `limit` and `page` parameters and returns only public summary fields plus pagination. Automatic `posts.latest` uses the same query. Single selectors accept a unified immutable ID or a unique slug; ambiguous slugs return unavailable. Existing `pages.*`, `blog.*`, document sources and Menu links remain compatible.

```mustache
{{#posts.latest.type.article.page.1.items}}<article><h2><a href="{{url}}">{{title}}</a></h2><p>{{summary}}</p></article>{{/posts.latest.type.article.page.1.items}}
```

`/plugins/posts/feed` and `/plugins/posts/sitemap` contain only currently visible indexable records. Existing Blog feeds/sitemap/POST comment endpoints remain supported. Public export includes own published/scheduled snapshots and their literal public media, replacing exported working copies with an authorized snapshot; drafts, trash, history and comments do not ship. Adopted native records are suppressed in legacy exports to prevent resurrection. The catalog is capped at 700 owned records/8 MiB, types at 20, groups at 100, tags at 200 and author overrides at 50. These are finite bounds, not an unlimited-scale search service.

Posts keeps the existing Pages/Blog packages installed as compatibility dependencies; their independent activation still controls unadopted source availability and global Blog discussion. Package dependencies do not install code or enable features automatically. The manager rejects removal of a package required by an installed plugin. Search-path settings can be adjusted while Posts is inactive to resolve an existing `/search` page before activation. Recovery from trash returns a draft and requires explicit republication.

The unified History screen can also restore validated original JSON/Page-publication/Blog backups directly into the unified draft. It keeps the current publication and native files unchanged. Unused original Blog blocks remain recoverable privately; they are excluded from authored publication/export snapshots. Literal media references using the reserved `{{cms.base}}/` prefix are exported under the same containment/MIME checks as ordinary public references.
