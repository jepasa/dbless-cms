# Use and develop templates

This guide uses the complete Essencial template shipped in this repository. Public
presentation belongs to templates; the administration layout is fixed and independent.
A template is trusted PHP code. Install only packages whose source you trust.

## 1. Run the example site

Follow [Get started](../README.md#get-started). `npm run dev` initializes the versioned
examples only when no content exists, then serves `src/public_html` on loopback.
Open the site and `/recursos` to explore pages, menus, slides and example media.
Use `/cms-admin/` to sign in with the development account you configured in `.env`.

The account dropdown contains Settings, Plugins and Templates. Global Settings defines
site information and the default admin/site locales. Admin dictionaries translate admin
controls; the site locale identifies editorial language and sets HTML/HTTP metadata.
It does not translate page text. Edit example text through Pages.

## 2. Select and configure a template

Open the account dropdown → Templates. The table is discovered from installed manifests.
Use each icon's title to identify Activate, Deactivate, Configure or Uninstall. Invalid
or content-incompatible templates cannot be activated.

Only one template is active. Activating a new one replaces the previous selection.
Deactivating the current one selects Essencial. Essencial is the protected fallback
and cannot be uninstalled. Configure Essencial to change palette, light/dark/system
mode, font size, alignment, text direction and content width. Changes affect the public
site without restyling the admin panel.

## 3. Install a template manually

A template is a pair of directories with the same ID:

```text
src/system/templates/studio/
  template.json
  schema.json
  settings.schema.json
  languages/en-us.json
  languages/pt-br.json
  partials/layout.php
  partials/header.php
  partials/footer.php
  pages/home.php
  pages/404.php
  forms/contact.php
src/public_html/templates/studio/
  studio.png
  css/style.css
  js/theme.js
  images/
  icons/
```

The private PHP tree is never a DocumentRoot. The public tree contains CSS, JS, images
and SVGs directly—there is no intermediate `assets/` directory. Copy both trees, inspect
the manifest, then open Templates to check discovery/compatibility before activation.
Uninstalling a template archives private code; referenced public assets and editorial
content are retained. Back up the installation before changing packages.

## 4. Create a working template from Essencial

Start from a complete implementation rather than guessing the runtime contract:

```bash
cp -R src/system/templates/essencial src/system/templates/studio
cp -R src/public_html/templates/essencial src/public_html/templates/studio
```

In `studio/template.json`, change `id` to `studio`, set a distinct `name`, `description`,
`author` and semantic `version`. Keep `schema_version: 2`. IDs must match the directory;
use letters, digits and hyphens, beginning with a letter. Keep the copied content schema
until the new design supports every existing page's data.

Capture a genuine 16:9 PNG preview of the template (recommended 1280×720), save it
as `src/public_html/templates/studio/studio.png`, and set
`assets.files.thumbnail` to `"studio.png"`. When copying Essencial, update both
the preview and this manifest field so the new template presents its own design.


Open Templates and confirm Studio is available. Activate it, review every example page,
then customize its own CSS and PHP. All manifest entries must point to existing regular
files inside the matching tree. Symlinks, traversal and arbitrary paths are rejected.

## 5. Understand the manifest

The complete [Essencial manifest](../src/system/templates/essencial/template.json) is the
reference. It identifies pages, forms, partials, schemas, assets and optional locations.
The following fields are central:

| Field | Purpose |
| --- | --- |
| `id`, `name`, `description`, `version`, `author` | Literal package identity |
| `contract` | Private content schema file, normally `schema.json` |
| `assets.files.thumbnail` | Required PNG preview relative to this template's public directory, conventionally `<template-id>.png`; custom filenames are resolved from this field |
| `pages` | Page-model key → file under `pages/`; `home` and `404` are required |
| `partials` | Partial key → file under `partials/`; layout/header/footer are required |
| `forms` | Form key → private file under `forms/` |
| `assets.styles`, `assets.scripts` | Ordered relative files in the public template tree |
| `assets.files` | Named image/icon/other regular assets |
| `configuration` | Settings schema and validated defaults for the theme |
| `menuLocations`, `slideLocations` | Static placement and format contracts |

Manifest descriptions are literal, not translation keys. Dynamic menu items, slide
content and associations are managed through the panel, not embedded in the manifest.
Administrative theme option labels use the template's dictionaries.

## 6. Render validated content safely

The layout receives `$page`, `$siteContent`, `$settings`, `$route`, `$currentPage`,
`$contentFile`, `$base_url`, `$store`, `$id`, `$manifest`, `$templateSettings`,
`$publicLocale`, `$navigation` and `$slides`. Inspect Essencial's layout for the complete
working example. Resolve registered scripts and assets through the CMS:

```php
<?php
/** Example layout fragment: escape editorial text and resolve a local template asset. */
use function DBLESSCMS\e;
?>
<h2><?= e($page['meta']['title']) ?></h2>
<img src="<?= e($store->templates->asset($id,$manifest['assets']['files']['brandSymbol'],$base_url)) ?>"
     alt="" width="32" height="32">
```

`DBLESSCMS` is the PHP namespace; the product name is DBLESS-CMS. Escape text
and attributes with `e()`. Use validated URLs, never raw HTML from JSON, dynamic PHP
includes or untrusted CSS. Respect `lang` and `dir` from `$publicLocale`; do not couple
the public locale to the admin dictionary.

Production resolves `.css`/`.js` to their exported `.min.css`/`.min.js` counterparts.
Use the asset helper rather than constructing production filenames manually. SVG icons
must be local files addressed by URL, not inline SVG markup or remote icon fonts.

## 7. Define page models and theme options

`schema.json` describes the allowed content for each page model. The page envelope
contains identity, status, metadata and a `content` object. Start from a versioned example
in `src/system/resources/contents/pages/` and the copied schema. Add your field to the
appropriate contract before trying to save it. Unknown or invalid data is rejected.

Use Pages → New Page, choose a model, edit the generated fields and save as a draft.
Review the preview before publishing. Existing page revisions protect against stale
saves. Home and 404 must remain published. Trash and History provide recovery.

Theme preferences belong to `settings.schema.json`, `configuration.defaults` and the
template's `languages/<locale>.json`. Every locale uses the same keys and placeholders.
Use closed enums for palettes/sizes/modes; render validated choices as predefined CSS
classes. Do not accept arbitrary CSS, executable code or raw style attributes. Saved
options arrive as `$templateSettings`, with compatible defaults merged during reads.

SCSS is optional in a custom template. Compile it to the public `css/style.css` during
development and declare that CSS in the manifest. Keep Sass/SCSS tooling private to
build-time; PHP production must serve compiled CSS. The production exporter excludes
`.scss` and `.sass` files and minifies the compiled CSS.

## 8. Place menus

Declare only location identity and formatting, for example:

```json
"menuLocations": {
  "header-primary": {
    "name": "Main navigation",
    "description": "Site header and mobile navigation",
    "types": ["navbar", "dropdown"],
    "defaultType": "navbar",
    "maxDepth": 3
  }
}
```

Menu → New Menu lets an editor create the items and choose compatible locations from
the currently active template. Groups, submenus, page links, anchors and external links
are configured there. The manifest does not contain menu items or user selections.

Resolve `$menuData = $navigation->location('header-primary')`. It returns validated
menu data or `null`. Render it with your own markup; Essencial's `partials/menu.php`
shows escaped hierarchical rendering and native accessible submenus. A template switch
can leave an association unavailable: the editor warns and preserves it instead of
silently deleting data. Provide a useful fallback when there is no assigned menu.

## 9. Place slides and dynamic page blocks

A location declares format and content type:

```json
"slideLocations": {
  "home-hero": {
    "type": "slider",
    "contentType": "banner",
    "description": "Homepage feature banners"
  }
}
```

Types are `slider`, `carousel` and `rotator`; content types are `banner`, `product`,
`testimonial`, `notice` and `logo`. Register a `slides` partial. Resolve
`$slideData = $slides->location('home-hero')`, check for `null`, then render data with
the template's own HTML/CSS/JS. No plugin-generated document is injected into your layout.

Create and publish a collection in Slides, then associate it with the location. The
example site demonstrates all five content types. Disabled, draft, deleted or incompatible
collections do not appear publicly. Missing locations produce editor warnings, not broken pages.

For a dynamic page, use a typed block such as `{"type":"slides","location":"page-showcase"}`
or a whole paragraph `{{slides:page-showcase}}` in a supported model. This is a controlled
marker, not an unrestricted Mustache engine: no expressions, PHP, arbitrary HTML or file
includes are evaluated. The location must exist in the active template. Inspect
Essencial's `slide-blocks.php` and `page-detail.php` for the complete rendering path.

## 10. Deploy a site with your template

Run `npm run verify`, review drafts and media, then explicitly export:

```bash
npm run export:production
php build/system/bin/hash-password.php
```

The export sanitizes content, minifies code/assets and retains readable JSON. It excludes
`.env`, configured `app.json`, sessions, audit logs, backups and uploaded files. Copy your
required public/private uploads separately and preserve their paths and permissions.

Install `build/` into a private directory and point the server **only** at its
`public_html/`. Copy `system/config/app.example.json` to private `system/config/app.json`;
set your chosen username and the generated Argon2id hash, then restrict permissions.
There is no default password. Create the configured private upload directory
(`system/storage/files/media` by default) and grant the PHP user only the required access.
Keep runtime JSON/storage private; do not expose `system/` through an alias or second root.

Set the canonical site URL to HTTPS before exporting your final site. Configure the web
server's TLS, HTTPS redirection and PHP limits. Only the three central public controllers
may execute PHP; uploads must never execute scripts. For Nginx, provide equivalent routing
and denial rules because `.htaccess` is not read. Match the File Manager limit with
`upload_max_filesize`, `post_max_size` and the web server's request-body limit.

Run `php system/bin/operations.php doctor` as the PHP service user in the installed package.
An unconfigured or invalid installation must not be treated as ready. For operational
copies, the CLI also supports maintenance, cleanup, backup, restore and upgrade; inspect
its usage before running it. Restore/upgrade require new real directories, never overwrite
the active installation, and require review before changing the server's active path.
Keep private external backups and validate a restore before relying on them.

## 11. Check your template before sharing it

- Validate every current page model and all manifest paths.
- Exercise desktop/mobile layouts, keyboard navigation, focus, 404 and empty locations.
- Test theme choices, locale metadata, RTL and readable contrast.
- Check console/CSP, broken media and unexpected network requests.
- Verify escaping with synthetic markup, and ensure no credentials/state enter your package.
- Keep a no-JavaScript fallback for essential navigation and published content.
- Preserve applicable licenses and notices for code, fonts, SVGs and images.

## Template preview contract

Every template ships a real PNG preview at an exact 16:9 aspect ratio. The manifest
field `assets.files.thumbnail` takes precedence over naming conventions: a value
like `"preview/home-wide.png"` is permitted inside the same template's public tree.
The admin Templates catalog displays that preview beside the package identity.
Only regular, confined files are accepted; corrupt PNG chunks/CRCs, trailing bytes,
missing files and other proportions reject the template. Previews are limited to
4 MiB and 4096×2304; 1280×720 is recommended. Keep the subject generic for public
packages and do not capture credentials, private content or the admin interface.
Existing templates must receive the PNG and updated manifest together during upgrade.

## Site and admin favicons

Ship `favicon.ico` in each template's public directory and declare it as
`assets.files.favicon`. It provides the default for older site documents and a fallback
if a user-managed icon is removed.

Global settings → Site favicon stores `settings.favicon`: a **path and filename relative
to the hosting public root**, such as `media/favicon.ico`, `images/brand.ico` or
`favicon.ico`. It is not relative to the active template or a fixed uploads directory.
Upload the ICO through Images in its configured public root, then enter its resulting
public-relative path. Private files, external URLs, traversal and symbolic links are rejected.
Saving a new or changed reference requires an existing valid ICO. An unchanged
reference to a subsequently removed upload does not block other saves or template fallback. The supported ICO format contains PNG frames
(16/32/48/64/256 px, up to 1 MiB); legacy DIB/BMP-frame ICO files are rejected.
Templates must use `DBLESSCMS\SiteFavicon::url($store, $base_url)` for their icon link.
An existing setting survives template changes; the admin favicon never follows it.
Production export copies only the referenced favicon, not its surrounding uploads.

The admin has a fixed addressed favicon at `cms-admin/assets/favicon.ico`, declared
in the admin document header for both login and authenticated screens.


## Blog integration

Blog provides published data independently of the template. Register an optional
`partials.blog` entry in `template.json`, for example `"blog": "blog.php"`. The private
partial receives `$blogModel`, `$blogSettings` and the standard layout context. A custom
view may render the validated data directly; the bundled implementation delegates to
`$store->config->root . '/plugins/blog/pages/public.php'` and owns its surrounding layout
and stylesheet. Both bundled template integrations use addressed raster media and
escaped typed blocks. Without a custom partial, the plugin uses its private default
fragment with local fallback reading CSS.

For correct metadata, consume the optional `$contentMetadata` supplied by Blog:
`canonical`, `robots`, `type`, `publishedAt`, `updatedAt` and `feed`. Escape every value
in its HTML attribute. Keep the existing page defaults when this variable is absent.
Articles use type `article`; previews and searches request noindex. The article fragment
contains BlogPosting microdata, so executable inline scripts are unnecessary.

Use the plugin URL builder for subdirectory-safe links:

```php
<?php
/** Trusted template integration: obtain read-only published article examples only when Blog is enabled. */
$registry = new \DBLESSCMS\Plugins($store);
if (isset($registry->all()['blog']) && $registry->state('blog')['enabled']) {
  $registry->instance('blog');
  $latest = \DBLESSCMS\BlogView::latest($store, 3);
  $archiveUrl = \DBLESSCMS\BlogView::url($base_url);
  // Escape editorial values and URLs when rendering your own HTML composition.
}
```

Do not render the raw private catalog as public JSON or load preview content without an
authenticated private route. Article slugs are query parameters on a declared plugin
route; template page slugs remain a separate namespace. Essencial demonstrates public
cards, full articles, category/tag/byline archives, search, feeds, reading time and
related content. New installations also include private draft, schedule and trash
examples for the administration panel. The [plugin guide](plugins.md#12-run-the-bundled-blog)
describes authoring, moderation and storage limits.

Public Blog template models contain only currently visible articles, including the catalog projection used for related reading. Drafts, future schedules and trash are removed before the public template executes; only authenticated saved-preview context may contain a draft.

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

### Global settings v2 field reference

Template rendering receives `$settings` from the validated site document. Per-page
metadata may override the site defaults. Do not persist duplicate identity values
in template options. `template` is the catalog-managed site preference; the theme
label is derived from `themeFields`, not a second editable preference.

| Settings path | Value and purpose |
| --- | --- |
| `siteLocale` | Public BCP 47 content tag, HTML lang/dir, Open Graph locale and Content-Language |
| `adminLocale` | Installed administrator dictionary locale |
| `name`, `slogan`, `description` | Unique site presentation values |
| `baseUrl` | Absolute HTTP(S) base URL, or empty for local discovery |
| `canonical` | Absolute HTTP(S) canonical site root |
| `favicon` | Public-root-relative ICO path; template fallback if the chosen file is missing |
| `logo`, `featuredImage` | Public-root-relative identity image paths, or empty |
| `author`, `keywords`, `type` | Site author, comma-separated keywords and Open Graph site type |
| `image`, `imageAlt`, `imageType` | Public-root thumbnail path, description and selected MIME type |
| `imageWidth`, `imageHeight` | Integer pixel dimensions, 0–16384 |
| `contact.country`, `contact.state`, `contact.city` | Site origin/address components |
| `contact.phone`, `contact.email`, `contact.responsible` | Contact telephone, email and responsible person |

Phone values remain strings to preserve `+` and formatting. Identity raster images
are limited to 12 MiB/40 megapixels during export, with matching extension/MIME;
SVG must be static and at most 64 KiB. Export copies only the referenced files and
rejects links, traversal, executable payloads and missing images. A manifest may
declare up to 100 widget positions; dynamic records stay in plugin storage.
