# Privacy, accessible Forms and image provenance

DBLess-CMS 2.7 adds technical privacy controls, declarative forms and image-rights
metadata. These features do not certify legal compliance, ownership, accessibility
conformance or the truth of a website's claims. Configure them for the actual
operator, audience, suppliers and business activity before collecting data.

## Configure the site

Global Settings still has four cards. Contact includes an optional Privacy and
legal information fieldset: controller/legal name, public commercial registration
and address when applicable, privacy email, the legacy contact notice, and slugs
for published privacy, terms, cancellation/refund and accessibility pages.

Create the documents in Pages and publish them after appropriate review. Only
published valid references appear in the shared public legal footer. The CMS does
not publish a generic privacy or refund policy on your behalf, expose a residential
address by default, or infer that an institutional site is exempt from rules
applicable to its actual commercial offers.

An institutional first release can use a contact channel, rights-request channel,
image attribution and accessibility controls without installing payments. Assess
additional conditions before offering services, products, recurring subscriptions
or digital access. Transaction/order/payment engines require separate integration;
Forms is not a checkout or contract-management system.

## Consent-managed optional resources

Open Global Settings → Privacy and optional resources. The core registry contains
editable public notice text, preference lifetime and a list of explicitly declared
resources. New installations have no optional resources and no consent banner.
The preference lifetime is a technical setting, not a statutory retention period.

A resource entry looks like this; add it to `resources` only after configuring a
published privacy page, controller and privacy channel:

```json
{
  "id": "optional-measurement",
  "name": "Your assessed analytics provider",
  "category": "analytics",
  "type": "script",
  "url": "/tracking/measurement.js",
  "origins": {
    "connect": ["https://metrics.example.com"]
  }
}
```

Supported categories are `analytics`, `marketing` and `media`. Resource types are
`script`, `image` and `embed`. A leading slash is relative to the CMS public root;
the renderer resolves the configured site base path. Remote resources require
HTTPS. CSP origins are explicit HTTPS origins, separated by `script`, `connect`,
`img` and `frame`; wildcards and general remote-script allowances are rejected.
The example domain is not an installed provider or a recommendation to use it.

Necessary session/CSRF functionality is separate from optional resources. Managed
resources requiring a choice are not requested before a current affirmative
category selection. Accept, reject and save controls use equivalent styling.
Preferences remain nonmodal and in document flow so they do not cover site controls.
Visitors can reopen choices from the public footer; Escape closes the panel without
implying consent. With JavaScript unavailable, optional resources stay unloaded.

Choices are kept in the visitor's local browser storage, scoped to the public
installation and versioned against the registry, privacy identity and referenced
policy revisions. No visitor ID or IP is added to the core preference record.
Blocked browser storage means the choice lasts for the current document only.
Withdrawal reloads the document with optional resources disabled. This cannot
unsend data already transmitted or erase cookies controlled by another origin.

Plugins, template PHP and complementary site JavaScript are trusted code. This
loader is not a sandbox against malicious owner-installed code or an exhaustive
scanner of tracking by images, infrastructure or external services. Review actual
network traffic and suppliers. The browser preference is not a complete server-side
proof of identified marketing consent.

## Create a form

Forms has its own manifest, icon, English presentation and administrative dictionaries.
Activate it in the Plugins manager. New installations include a clearly marked
noncollecting example. Existing installations can run this additive command:

```sh
php src/system/bin/install-forms.php --enable
```

The command preserves existing plugin settings, definitions, pages and content. It
does not create a live collecting form. Use Forms → New form to define a stable
lowercase key, name, description, status and fields. The visual editor supports
text, email, telephone, long text, select, radio and checkbox controls, with visible
labels, required flags, limits, choices and suitable autocomplete.

Define purpose, assessed legal basis, public notice/version and a published privacy
page. New live forms do not receive an inferred legal basis. A specific consent text
is required only when the selected basis uses consent; consent is not a universal
condition for contact or exercise of data rights. Choosing a legal basis in the
editor does not establish its validity for the operation.

Keep Demonstration enabled while configuring a form. It prevents delivery. Before
collecting, select the published privacy page, disable Demonstration, configure the
global contact or privacy destination and enable delivery in private application
configuration. Visitor input never selects recipients, mail headers or executable
validators. No public attachments or external CAPTCHA are added.

### Insert a functional form

Use a complete block in Homepage, Page or Blog authored HTML:

```mustache
{{forms:form id="contact-example"}}
```

The core discovers declared `formServices` independently of read-only
`contentServices`. Authenticated editors show available form providers and stable
keys. The core prepares opaque slots, validates authored HTML and data Mustache,
then mounts only a narrow trusted interactive fragment with a declared public POST
route and current visitor tokens. Form rendering is bounded per request.

Ordinary authored HTML still cannot create `form`, `input`, `select` or `textarea`
elements; raw HTML variables/triple braces, executable helpers and recursive value
expansion are not enabled. Private previews disable interactive forms rather than
sending production messages. Definitions and rendered form/token data are never
stored in the public parse cache.

### Read definition data

Bind `forms:definition` with parameter `id` and an alias such as `inquiry` using the
shared editor. It returns published labels, choices and notice information:

```mustache
<h2>{{inquiry.name}}</h2>
{{#inquiry.fields}}<p>{{label}}</p>{{/inquiry.fields}}
```

This data contains no answers, recipients, private evidence, CSRF tokens or tickets.
The functional marker is separate because public data providers remain read-only.
Custom trusted form-provider plugins implement `ContentFormProviderInterface` and
declare a `formServices.form` description and public POST route in their manifest.
Their renderer must satisfy the narrow fragment/endpoint/CSRF contract; data
providers never acquire submission privileges.

## Delivery, minimal retention and data-rights requests

Public submissions use the core method, origin, body-limit, CSRF and rate controls,
plus server field validation, an accessible honeypot and a session-bound form-version
ticket. A repeated accepted ticket returns the existing receipt without sending
again. Uncertain transport is reported as uncertain, not successful or automatically
resent. A transport acceptance message is not proof of arrival in the inbox.

Public JavaScript preserves fields on errors and clears them only after verified
acceptance. Without JavaScript, successful requests use POST/303/GET with a minimal
session flash; failures re-render escaped fields. Core/session and ticket timeouts
remain security constraints; readers must be able to use the published alternative
contact channel. Full assistive-technology/time-limit assessment is not implied by
the included browser checks.

Answer retention defaults to zero. Private receipts record reference, form/notice
revisions, public notice/consent text and transport status, without visitor IP or
email unless the operator explicitly enables retaining submitted fields. Receipts
have a bounded operational lifetime; determine appropriate purpose and retention
for your operation rather than treating a default as a legal requirement. Mailboxes
and their backups still process the message even when the CMS stores no answers.

For a rights-request form, choose the privacy destination, request only necessary
contact/request details and assess the legal basis. The reference supports manual
handling by the operator. There is no public lookup exposing whether a person has
records, no default demand for identity documents, and no automatic deletion of all
site data. Identity checks, response obligations, exemptions and legal holds require
an operator workflow.

```sh
php src/system/bin/privacy-audit.php
php src/system/bin/privacy-audit.php --strict
php src/system/bin/privacy-retention.php --dry-run
php src/system/bin/privacy-retention.php --apply
```

The audit outputs aggregate technical findings and explicit unresolved human-review
items; even an empty technical issue list is not a legal certificate. Strict mode
fails when required technical privacy setup is missing. Retention defaults to a
simulation and is scoped to expired Forms receipts/answers. Schedule it through
private operational tooling; it does not remove legal access logs, contracts,
incident records, mailboxes or backup snapshots.

Erasing retained answers creates a private tombstone containing only an opaque
receipt ID/time. Supported CMS restore merges current tombstones and redacts
matching old receipts before publishing the restored installation. Keep the current
ledger with recovery materials; manually replacing storage from an old copy outside
the CMS cannot recover newer deletion decisions. Document backup expiration, legal
retentions and external-system erasure separately.

## Image rights and public labels

Images is the existing `files-manager` plugin. Select a managed image in Overview
and choose Metadata, or open Image rights from its sidebar dropdown. Record actual
author, source URL, license/permission, public license URL, credit, private permission
reference, suggested description and rights-review status.

Unknown authorship is not public domain. Credits do not replace permission or
permission from identifiable people; the operator must assess the actual license,
commercial use and transformation restrictions. Approval requires author/license
information and a rights reference where applicable; it is not an automated legal
verification. Private evidence references remain outside public provider data and
public distribution.

A stable asset ID follows managed rename, move, trash and restore. File changes
outside the manager invalidate byte approval and require review. Approved local
images receive visible author/source labels in complete public HTML across templates
and public plugin pages. JSON/XML/downloads/private previews are not decorated.
CSS backgrounds and external images need explicit template/editorial attribution.
Alternative text remains contextual: a suggested description is not forced into
every use, and decorative images may use an intentional empty `alt`.

Bind `files-manager:asset` with an approved public asset ID and alias `photo`:

```mustache
<figure>
  <img src="{{photo.src}}" alt="Describe the image in this particular context">
</figure>
```

The shared public renderer places the approved visible credit beside this image; avoid adding a duplicate figcaption. The result also includes public source/license metadata and dimensions, not evidence,
private files or private parent identities. Only currently approved bytes are used.

### Publish an explicitly licensed sanitized copy

Uploaded originals are not silently rewritten and may contain EXIF/GPS/C2PA. For a
private JPG/PNG/WebP with reviewed derivative permission, use Publish sanitized copy
from its metadata screen. Optional PHP GD decodes/encodes a new public image, strips
technical metadata and keeps provenance/parent linkage in the private catalog. The
original stays private and unchanged. The public copy retains visible rights labels.

No new GD dependency is installed automatically. Missing GD, unsupported animated
content, GIF/ICO conversion, excessive pixel budgets and filename collisions fail
explicitly. The workflow does not authenticate or re-sign C2PA or certify ownership.
Review visual output, color/profile effects and license conditions before publication.

## Templates, accessibility and distribution

The public decorators operate on full HTML documents. Keep public media as addressed
assets, escape data and preserve semantic markup. Style shared controls carefully
within a proprietary template's content region; do not use overlays as an
accessibility substitute. Test keyboard order, focus, labels/errors, contrast,
responsive reflow, zoom, reduced motion and carousels. Automated checks cover selected
scenarios and do not replace screen-reader or content-quality review.

Production export includes only published form definitions, public configuration
and approved rights metadata for media actually exported. It excludes submissions,
receipt history, erasure ledger, private originals and permission evidence. Explicitly
registered local optional JavaScript is parsed/minified without remote fetching.
Updates preserve existing operator data and do not publish example legal policies.

Local consent-managed resources are confined to `/tracking/` to prevent exported resources from replacing core or template code. Use `.js` for scripts and PNG/JPEG/WebP/GIF for images; local embedded documents are not supported. Remote embeds require HTTPS and declared origins.

New installations also include `privacy-request-example`: a noncollecting minimal request model with email, request type and optional details, addressed to the privacy contact. It deliberately has no legal basis selected, no identity-document upload and no retained answers. Adapt the notice, verification and applicable response process before enabling it; the CMS does not decide a person’s rights or a legal deadline. Existing installations are never populated with these examples automatically.
