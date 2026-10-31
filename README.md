# dbless-cms

**Your content. Your code. No database to manage.**

Build polished websites with a focused PHP CMS, human-readable JSON content, and a
responsive administration panel. Start with a complete example site, make it your own,
and extend it with templates and plugins that fit your projects.

![Essencial template preview](docs/images/essencial-preview.png)

[Get started](#get-started) · [Build a template](docs/templates.md) · [Build a plugin](docs/plugins.md) · [Leia em português](LEIAME.md)

## A practical foundation for your next website

- **Skip database provisioning.** Pages, articles, menus, slides and settings live in structured
  JSON files. Keep hosting straightforward and content easy to inspect and move.
- **Give editors a dedicated workspace.** Manage pages, drafts, revisions, trash,
  menus, media, articles and slides in a responsive admin interface independent of the public theme.
- **Own the presentation.** Templates receive validated content and control their
  HTML, CSS and JavaScript. Add your design without adopting a frontend framework.
- **Extend only what you need.** Plugins declare their routes, navigation, dictionaries
  and settings. Private admin routes and public plugin routes have separate contracts.
- **Start with something useful.** Essencial includes configurable palettes, dark mode,
  typography, text direction and examples of every bundled plugin.
- **Build on explicit safeguards.** The CMS includes session/CSRF protection, input
  contracts, revision checks, recoverable deletion and private content storage.

Choose dbless-cms for business websites, portfolios, landing pages and content-focused
projects where a file-based CMS fits the job. PHP 8.2+ powers the application; Node.js
22 is a development/export tool, not a production server dependency.

## Explore a complete example

The distribution includes the **Essencial** template and seven ready-to-use plugins:

| Plugin | What it brings to your project |
| --- | --- |
| Blog | Articles, scheduled publication, authors, topics, SEO, RSS and moderated comments |
| Widgets | Positional values, reusable widgets and ordered sets inserted through components |
| Homepage | Compose sections, reuse components and review private drafts before publishing |
| Pages | Structured pages, drafts, publishing, history and trash |
| Menu | Hierarchical navigation assigned to locations declared by your template |
| Slides | Banners, carousels, testimonials, product displays and rotating content |
| Files Manager | Public/private uploads, folders, previews and recoverable deletion |

The example site connects pages, blog articles, menus and slides so you can learn by changing a
working site. Its three original example images are included under Apache-2.0.
The admin supports English and Brazilian Portuguese; sample editorial content is
Brazilian Portuguese, with English blog examples, and can be edited independently of the admin language.

## Get started

Requires PHP 8.2+ with JSON, sessions, fileinfo, OpenSSL and Argon2id, plus Node.js 22
for the development commands.

```bash
git clone https://github.com/jepasa/dbless-cms.git
cd dbless-cms
npm ci --ignore-scripts
cp .env.example .env
```

Set your development administrator in `.env`: choose a username and a unique password
of at least 16 characters using `DBLESS_CMS_ADMIN_USER` and `DBLESS_CMS_ADMIN_PASSWORD`.
These are the canonical development variable names. No working password is supplied.

```bash
npm run dev
```

Open **http://127.0.0.1:8080/** and **http://127.0.0.1:8080/cms-admin/**.
The initial example content is installed once; existing content is preserved.
Development runs directly from `src/`. For production, follow the deployment section
of the [template guide](docs/templates.md#10-deploy-a-site-with-your-template): HTTPS,
private hash-based configuration and an explicit export are required. `.env` is
ignored by production packages.

## Publish a journal that belongs to you

The bundled [Blog](docs/plugins.md#12-run-the-bundled-blog) brings an editorial workflow
to your website: structured articles, visual covers, scheduled publishing, searchable
archives and RSS. Enable moderated discussion when your project needs it. Keep articles
in portable JSON and let your template own the reading experience.

## Make it yours

- [Use and develop templates](docs/templates.md): installation, manifests, content
  contracts, theme options, menu/slide locations, assets and deployment.
- [Use and develop plugins](docs/plugins.md): installation, private/public routes,
  integrated admin UI, dictionaries, settings and safe content persistence.

Run `npm run verify` before proposing changes. The repository includes quality and
security workflows; report vulnerabilities through [the security policy](SECURITY.md).

## Help more developers discover a simpler CMS

If dbless-cms fits your next project, **[give the repository a star](https://github.com/jepasa/dbless-cms)**.
A star helps others discover the project and signals interest in its continued development.
Try the examples, build a template, share a plugin, or report a reproducible issue—your
experience can help shape a more useful tool.

## License

**Apache-2.0**: use, modify and distribute dbless-cms, including in commercial projects,
subject to the license terms. Keep the applicable notices. Third-party components retain
their own licenses. See [LICENSE](LICENSE), [NOTICE](NOTICE) and [THIRD_PARTY.md](THIRD_PARTY.md).

**Widgets** turns template-defined positions into reusable text and profile links. Update values in the admin panel, preserve them across template changes, and recover changes through history or trash. Global settings keep your identity and contact information consistent across the site.

### An operational overview without extra infrastructure
Open the admin panel to a focused Dashboard: content, active extensions and measured
cache health in one place. Configure the optional private page-data cache in Global
Settings, and clear it safely from the Dashboard. No database, Redis or background
service is required. [Plugin integration guide](docs/plugins.md#core-dashboard-indicators).

Discover the [Homepage and shared component workflow](docs/components.md). Each plugin also includes its own English presentation and usage guide, available from the Plugins manager.
