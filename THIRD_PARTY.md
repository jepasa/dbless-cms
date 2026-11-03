# Third-party notices

The original dbless-cms code, Essencial template, example content and newly generated
example images are licensed under Apache-2.0. See LICENSE and NOTICE.

- Bootstrap Icons 1.11.3: selected SVG files, MIT. Full upstream license and integrity
  manifests are retained in the admin and Essencial `icons/` directories. SVGs are
  addressed by URL; no icon font or embedded SVG markup is required.
- Node development tools: clean-css, html-minifier-terser and terser, with their
  transitive dependencies. Exact versions, integrity data and license identifiers
  are recorded in package-lock.json. They are not server runtime dependencies.
- Example images: newly generated for this distribution with the built-in image_gen
  tool on 2026-10-30. Prompts, dimensions and hashes are recorded in
  `src/public_html/templates/essencial/images/examples/provenance.json`. These assets
  are offered under Apache-2.0 with the distribution; no third-party photographs
  or proprietary template assets are included.

Plugin identity icons are original static SVG geometry supplied under Apache-2.0. The Essencial package preview is a real 1280×720 capture using public demonstration resources, under the same license.

The DBLess-CMS administrative symbol, geometric wordmark and derived favicon are original artwork licensed under Apache-2.0. Usage and palette are documented in `doc/identidade_visual/README.md`.

## Terser browser parser

The admin customization editor uses the locally vendored browser bundle from the pinned Terser development dependency (BSD-2-Clause). Source: https://github.com/terser/terser. License: src/public_html/cms-admin/assets/vendor/TERSER-LICENSE.txt. It parses administrator-authored JavaScript without executing it; no CDN or production Node dependency.

The shared core editor is original DBLess-CMS code under Apache-2.0. Quillit ME was evaluated as a product reference; no source code, WPM presets or installers from that package are redistributed.

The additional upload, download, folder, folder-plus, refresh, replace, restore,
move, info, video, more, sort and chevron action SVGs in `cms-admin/assets/icons/`
are original DBLess-CMS geometry distributed under this project's Apache-2.0
license. Existing third-party icon notices remain applicable to their files.
