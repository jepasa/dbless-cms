# Security policy

## Supported versions

Security fixes target the latest stable 2.x release. Use a supported PHP version,
keep the application and its extensions updated, and review release changes before
upgrading. Extensions are trusted PHP code, not isolated or sandboxed applications.

## Report a vulnerability privately

Use [GitHub private vulnerability reporting](https://github.com/jepasa/dbless-cms/security/advisories/new).
Include the affected version, a minimal reproduction using synthetic data, expected
and observed behavior, and the potential impact. Do not open a public issue containing
exploit details, credentials, customer content or personal data. Do not test a site
without its owner's permission. Coordinate disclosure in the private advisory.

## Safe template and plugin operation

Serve only `src/public_html` in development and `build/public_html` from an explicit
production export. Keep configuration, JSON documents, sessions, audit logs and backups
outside the public root. Production requires HTTPS, private configuration and a password
hash; `.env` is development-only. Never commit a configured app.json or production state.

Install templates/plugins only from trusted sources. Escape output, validate inputs,
use the CMS session/CSRF and revision contracts, and keep private operations inside admin
routes. See the security sections of [Templates](docs/templates.md) and
[Plugins](docs/plugins.md). Do not enable external email until its transport is configured.

## Repository controls

GitHub configuration and workflows complement application testing; they do not certify
that arbitrary extensions are safe. The workflows use explicit permissions, immutable
Action references and no deployment secrets. CodeQL covers JavaScript and Actions;
PHP checks run separately with Semgrep. Changes require passing repository checks.
