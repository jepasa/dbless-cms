# Homepage and shared content components

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

Each bundled plugin declares `"presentation":"about.json"`. The private UTF-8 JSON document has `language:"en"`, `title`, `lead` and `sections` of `title`/`body` string arrays plus optional `code`. The manager's localized presentation action opens it inside the fixed admin layout, even when the plugin is inactive. The core reads/escapes the document without executing the plugin entrypoint. Third-party plugins may omit the declaration for API 1 compatibility; declare it to provide their own presentation. Public documentation and presentation examples contain no credentials or private site content.
