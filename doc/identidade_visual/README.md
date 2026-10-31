# DBLess-CMS visual identity

The project owner approved this original identity on October 30, 2026. The
canonical documentation directory is `doc/identidade_visual/`. The original SVG
geometry and lettering are distributed under Apache-2.0; no fonts, third-party
artwork or external services are embedded.

## Assets and palette

| Asset | Use |
| --- | --- |
| [Symbol](../../src/public_html/cms-admin/assets/brand/dbless-cms-symbol.svg) | Rounded mint square, JSON brace and D/content strokes |
| [Primary signature](../../src/public_html/cms-admin/assets/brand/dbless-cms.svg) | Navy lettering on light backgrounds |
| [Inverse signature](../../src/public_html/cms-admin/assets/brand/dbless-cms-inverse.svg) | Light lettering in the dark administrative sidebar |
| [Administrative favicon](../../src/public_html/cms-admin/assets/favicon.ico) | Seven PNG frames: 16, 24, 32, 48, 64, 128 and 256 pixels |

Brand mint is **#78E4CA**, navy is **#112438**, inverse lettering is **#F1F6FC**,
and the sidebar background is **#111E32**. Preserve geometry, aspect ratio,
transparency and contrast. The sidebar signature has a maximum width of 188 px
and alternative text `DBLess-CMS`. Use addressed SVG files; never inline SVG or
SVG data URLs. The minimal sign-in screen keeps its person-circle avatar.

## Plugin icons and public templates

Plugins keep their own SVG icons and manifest-defined paths. In the sidebar,
`.sidebar .plugin-icon` uses the local SVG filter
`brand/plugin-icon-color.svg#mint` to preserve source alpha and apply exact mint
in sRGB. Do not rewrite plugin icons or recolor catalog actions and semantic
statuses. The filter is included with source and production CSS.

The administrative favicon is hardcoded at `cms-admin/assets/favicon.ico`.
The public website keeps its own favicon, selected through global settings;
this administrative identity does not replace public template branding.

## Rebuilding the favicon

With Inkscape installed locally, run:

```bash
node scripts/generate-admin-favicon.mjs
```

This optional offline utility regenerates the ICO from the canonical symbol and
writes 16/32/48/64-pixel previews into this directory. It is not a server or build
dependency. Keep the generated artwork under the same license and verify SVG/ICO
validation and actual desktop/mobile rendering after changes. Template and plugin
authors should also follow the [template guide](../../docs/templates.md) and
[plugin guide](../../docs/plugins.md).

## Account menu icons

The administrative account dropdown uses independent semantic icon names, resolved
to the existing local Bootstrap Icons SVG files:

| Destination | Semantic name | Local SVG |
| --- | --- | --- |
| Global settings | `settings` | `gear.svg` |
| Plugins | `plugins` | `grid.svg` (module grid) |
| Templates | `templates` | `palette.svg` |

Keep these navigation defaults distinct from catalog action names such as
`configure` and `activate`. Icons remain decorative addressed images alongside
translated link text; their destination routes and existing MIT notices are preserved.
