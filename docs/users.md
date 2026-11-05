# Users, access groups and authentication

Users is a Core module, fixed immediately below Dashboard. It uses the existing
administrative identity and JSON persistence. There are no public accounts or
external authentication services. Fixed controls follow the administrative locale.
Editorial authors and publication groups are separate from accounts/access groups.
Core [email delivery settings](mail.md) support PHP mail() or authenticated SMTP.

## Workspace and navigation

The Core sidebar uses the same native dropdown, separators and visual components
as Posts, fixed below Dashboard: Overview, New user, Access groups; History;
Settings; Presentation; Trash. Links follow account capabilities and each route
and mutation also checks authorization on the server. My profile remains in the
account menu. Presentation is a localized, read-only operational guide.

Overview and access-group catalogs use the shared searchable, sortable, paginated
Core tables. History projects the latest 500 audit entries with UTC date, actor,
action and target identifier. It does not retain or restore previous passwords,
tokens, authenticator seeds or credential snapshots.

Trash lists removed accounts. Removal erases password hashes, factors/recovery
codes and reset tokens and revokes access. Restoration requires an explicitly
selected existing group, rejects duplicate login/email and returns the same
immutable identity as pending, preserving publication ownership. It never revives
erased credentials or sends mail automatically. Select the restored account in
Overview to send a new password link; mandatory MFA enrollment applies again.
Non-protected empty groups are removed directly from Access groups and do not
enter the account trash. There is no destructive purge action.

## Authentication settings

Users → Settings requires `system.manage` and stores validated policy in the
private Users catalog under the same lock and byte revision as account changes.
It is excluded from site JSON, editorial history and exports. Reading an older
catalog resolves defaults without rewriting it; an invalid stored policy fails
closed instead of reverting to defaults.

| Parameter | Default | Allowed range |
| --- | ---: | ---: |
| Login attempts per origin | 5 | 1–20 |
| Login attempts per account | 10 | 1–30 |
| Login sliding window, minutes | 15 | 1–60 |
| Idle session timeout, minutes | 30 | 5–120 |
| Absolute session timeout, minutes | 480 | 30–1440 |
| Minimum password length, Unicode code points | 16 | 16–128 |
| Password lifetime, days | 0, disabled | 0–365 |
| Password-link lifetime, minutes | 30 | 5–60 |
| MFA attempts per origin and account | 5 | 1–10 |
| MFA sliding window, minutes | 5 | 1–15 |
| Pending authentication/enrollment lifetime, minutes | 5 | 1–10 |

The absolute timeout must be at least the idle timeout. Both are enforced on the
server, including locked identity mutations. Activity cannot extend the absolute
lifetime. A shorter configured duration applies on the next authorization check;
relaxing policy cannot revive a session already invalidated. PHP session garbage
collection is configured for the largest permitted lifetime; hosting-level session
cleanup may still terminate sessions earlier.

Login windows count all submissions, including successful authentication. Origin
and account quotas are independent and release as attempts leave the window; no
permanent account lock is introduced. Account quotas use the immutable identity
across username/email and origins. Unconfigured proxy headers cannot supply a new
client address. Recovery request/consumption limits remain independently bounded.

The password floor, 200-code-point/800-byte maximum, offline common-password list
and Argon2id hashing remain mandatory. A higher minimum applies when a password
is next defined, including a final policy check under the catalog lock. Unicode
and spaces are allowed; control characters are not. No arbitrary regular
expression, mandatory character composition or configurable hash algorithm is
accepted. The offline list is not a comprehensive breached-password database.

Periodic expiry is optional and disabled by default. When enabled, the reference
is `passwordChangedAt`, or `createdAt` for an older account until its next reset;
editing a profile does not renew password age. Enabling a lifetime can immediately
expire older credentials and sessions. A correct expired password yields recovery
instructions without full or partial private access or automatic mail. Use the
ordinary recovery link. Successful reset records the new timestamp and preserves
the account's existing MFA. Increasing a token/challenge lifetime never extends
its original expiry; lowering it also limits live proofs under current policy.
No credential is migrated or rehashed merely by reading or saving settings.

The optional catalog members are understood by 2.24.0 and later. Once a policy,
credential timestamp or issued-token timestamp has been saved, older runtime
versions with the former closed schema cannot read that catalog. Do not downgrade
code alone; use a compatible version or the documented whole-installation recovery
procedure with its corresponding private state. Reading an untouched older catalog
does not introduce those members.

The defaults and ranges above are project policy. Optional periodic expiry and
passphrases follow [OWASP Authentication guidance](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html);
idle and absolute server-side limits follow
[OWASP Session Management guidance](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).

## Initialize an existing installation

1. Back up the private installation using the operations runbook. Use HTTPS and an
   accurate system clock. PHP 8.2+ needs native Argon2id and Sodium support.
2. Open Global Settings → Email delivery and configure the transport, sender and
   trusted CMS base URL. These private options have their own save button. Existing
   installations may keep the private application's legacy optional `users` section
   until the first explicit save of that card:

   ```json
   {
     "users": {
       "origin": "https://cms.example.com",
       "from": "cms@example.com"
     }
   }
   ```

   Merge this section into `system/config/app.json`; retain its existing editor
   and other settings. For a subdirectory installation include that subdirectory
   in `origin`. Only HTTP loopback is accepted for local qualification. Never use
   a request Host, a public editorial setting or an untrusted forwarding header.
3. Configure and verify the chosen [Core transport](mail.md): the host's PHP
   `mail()`/local MTA or authenticated SMTP with verified TLS. Contact-form enablement is independent.
   Transport acceptance does not prove mailbox delivery; verify real delivery in
   the deployment separately. Tests use an isolated capture transport only.
4. Sign in with the existing credential and open Users. Confirm the current
   password, owner name/email, and a code from the newly enrolled authenticator.
5. Store the ten recovery codes privately, outside this installation. They are
   displayed once. Protect the installation's separate encryption key and backup.

Before this explicit initialization, the existing single-credential mode remains
compatible. GET requests do not create accounts, migrate credentials or publish
content. Initialization preserves the effective legacy password hash. A configured
development plaintext override is hashed because it is the effective credential.
After initialization, Users is the sole authentication authority. A missing/corrupt
catalog or missing key fails closed; legacy configuration cannot bypass MFA.
Keep the Core code and its private catalog/key together across upgrades. Removing
both identity state and its migration marker manually is an operator security
change, never a supported account recovery procedure.

## Accounts and responsibilities

Each account has an immutable ID, unique case-insensitive login/email, display
name, status, and exactly one access group. User management has no password input
or generated-password function. Creating an account sends an invitation to choose
its password. If transport fails, the account remains pending and the manager
receives the failure; configure transport and send another link from the list.
Select up to 25 eligible accounts to request password links in one action. Blocked
accounts, including accounts without a password, remain blocked and receive no
invitation. If the default Contributor group was removed, creation requires an
explicit access-group choice; it never falls back to Administrator.

| Initial group | Responsibility | MFA |
| --- | --- | --- |
| Administrator | All permissions; protected group | Required at every sign-in |
| Editor | All publications, publication taxonomies, moderation and public media | Optional until enabled |
| Contributor | Create and edit only personally owned, unpublished drafts; select public media | Optional until enabled |

Up to 20 groups are supported, including the initial groups. An access group stores
its responsibility description, a closed permission list and its MFA policy.
The new/edit form separates group details and permissions into two cards, followed
by an actions-only card. Back returns to Access groups without saving; Save changes
submits the complete form. Both controls use the panel's right-aligned actions
(the logical end in RTL), with Back before Save changes.
The Administrator group always retains every permission. Creating a group with
an existing identifier is rejected; editing retains the original identifier. Security-management
permissions force MFA even in custom groups. An occupied group cannot be removed:
reassign its live/blocked/pending accounts first. Deleted accounts are ineligible
for authentication and retain only stable ownership/audit references; deleting
an obsolete group reassigns these inert tombstones to the protected group.
At least one active Administrator with a password and enrolled factor must remain.

| Permission | Scope |
| --- | --- |
| `users.manage`, `groups.manage` | Accounts/reset links and access groups, respectively; require MFA |
| `system.manage` | Trusted technical administration, configuration and extensions; also grants extension operations; requires MFA |
| `posts.read`, `posts.create` | Publication workspace entry and new working drafts |
| `posts.edit` | Global editorial access, including unclaimed legacy content |
| `posts.own` | Only the actor's currently unpublished and unscheduled managed drafts |
| `posts.publish` | Publish/schedule/unpublish; also requires global editorial access |
| `posts.taxonomies`, `comments.moderate` | Editorial groups/tags/authors and moderation, respectively |
| `media.read`, `media.manage` | Public read-only selection and public file management, respectively |
| `media.private` | Private media, combined with the relevant read/management permission |
| `plugin.homepage`, `plugin.menu`, `plugin.slides`, `plugin.widgets`, `plugin.forms` | Their complete editorial/submission workspace, excluding technical configuration |

Publication ownership is a private Core map assigned by the server at creation or
duplication. It is never inferred from editable author names. Existing content
without an owner requires global editing permission. After an authorized global
editor explicitly unpublishes a record and removes its schedule, its owner may
edit the resulting draft again; historical publication alone is not an access flag. Contributors cannot change
published/scheduled records, use retired package mutation aliases or view another
account's private drafts/history/previews. Technical configuration and native mutations of home/404 stay restricted. A
disabled legacy route never grants access through its technical-catalog fallback.
Unsaved template previews require global editorial access; saved own-draft preview
uses the publication's object authorization. Unknown extension operations are
restricted to trusted system management. Trusted PHP plugins are not sandboxed.

Authorization is enforced for Core routes, plugin APIs/aliases, private previews,
media streams and individual publications. Hidden navigation is only presentation.
The profile edits only its own display name and authentication settings. Email,
status and group changes belong to authorized account management. Sensitive changes
and group-version changes invalidate active and partial sessions on subsequent
validation. No individual permission overrides are stored on accounts.

## Password and factor flows

The login form always displays “Forgot my password” below its submit button,
including before Users initialization. Opening that link does not migrate or
create accounts. Recovery delivery requires an eligible registered account and
the configured mail transport; an unregistered legacy credential receives the
same generic confirmation and cannot be reset through an invented email mapping.

“Forgot my password” returns the same public confirmation for known and unknown
addresses. Origin/account rate limits and a 500 ms response floor reduce automated
abuse and obvious timing disclosure. A slow/failing MTA or a host under load can
still affect latency; this is not a constant-time transport guarantee.

A password link contains a random 256-bit token bound to its account. Only its
SHA-256 hash, issuance time and configured expiry (30 minutes by default) are stored. A later request invalidates previous
links. GET does not consume it. Password submission checks CSRF, confirmation and
the configured minimum and 200 Unicode-character ceiling (up to 800 bytes and the offline common-password
list), then uses native Argon2id with a fresh salt. Consumption is single-use under
the catalog lock, including concurrent requests. Password reset revokes sessions,
keeps existing MFA, does not sign in, and sends a security notification.

An authenticator uses RFC 6238 SHA-1, six digits, 30-second periods, ±1 period clock
window, and a persisted monotonic last-successful counter. Already accepted codes
cannot be replayed. Each account may use one of ten high-entropy recovery codes
once in place of its authenticator. Password authentication creates only a
policy-bounded partial session (five minutes by default) when a factor is required. The first login of a new
Administrator must enroll and confirm its authenticator before any private access.

QR encoding runs locally with no remote image service. Manual enrollment works
without QR support. The secret is temporarily held in the server-side enrollment
session and rendered only to that private no-store/no-referrer page. Enrollment
expires according to the challenge policy (five minutes by default). Seeds are encrypted with native XChaCha20-Poly1305,
bound to the immutable account ID. Recovery codes are stored only as hashes.
Replacement/optional disabling require the current password and an existing factor
when enabled. Required MFA cannot be disabled from the profile. Replacing a factor
revokes old sessions and recovery codes. Security notifications are best effort;
the security change remains committed if notification transport fails.

## Private files, backups and recovery

| Path under private storage | Contract |
| --- | --- |
| `users/catalog.json` | Versioned schema, groups, identities, hashed credentials/tokens, encrypted factors, ownership and bounded audit |
| `users/write.lock` | Exclusive identity transaction lock |
| `keys/users.key` | Independent random 256-bit installation encryption key, mode 0600 |
| `sessions/`, `rate/` | Private authentication/challenge/CSRF/limit state |

The catalog is bounded to 8 MiB, groups to 20 and secret-free audit to 500 events.
It uses CAS for administrative writes, validated JSON and atomic replacement.
Audit contains IDs/action/timestamps, without email, passwords, tokens, QR or codes.
Previous credential snapshots are not added to editorial history. JSON has no
comments; this guide and the strict `UserStore` validator define its schema.

Production exports/public distributions contain none of these files. Durable
private operational backups intentionally preserve the catalog and encryption key
as protected installation state; protect backup access separately and restore into
a new private destination through the existing operations workflow. Do not copy a
live catalog/key independently without maintenance and a consistent backup.

For loss of an authenticator, sign in with a saved recovery code and replace the
factor in the profile using another unused recovery code plus the current password.
Another enrolled Administrator can send a password link, but cannot silently remove
another account's MFA. Maintain more than one enrolled administrator where possible.
A lost key or damaged catalog requires a known, consistent private backup and
the operator runbook. A storage backup does not replace access to a user factor:
if every factor/recovery code is lost, recovery requires privileged host
administration outside the web manager. There is no web bypass or unprotected
automatic reset of the Administrator factor. Revoke existing sessions
when restoring older credential state. The read-only operator doctor validates the
authoritative Users catalog/key, an active enrolled Argon2id administrator, private
file permissions, native Sodium and configured mail capability after migration.
It never approves credentials by falling back to the bootstrap account. A failed initial write that left only the
key requires operator inspection/recovery, never an automatic legacy fallback.

Serve only `public_html`. Keep storage/configuration/backups outside the public
root. Configure access logs to omit sensitive query strings (for example Nginx
`$uri` rather than `$request_uri`, or Apache `%U` without `%q`), including reset
links. Do not send these URLs or enrollment screens to analytics. Verify HTTPS,
clock, filesystem ownership, email delivery and log policy on the actual host.

## Local qualification and limits

`tests/users.test.mjs` covers RFC vectors, encrypted seed integrity, migration,
limits/CAS, revocation and concurrent token/factor consumption. The HTTP suite
covers CSRF, generic recovery, partial-session bypasses, private media, aliases,
privilege escalation and cross-account object access. `tests/browser/users.mjs`
uses actual forms, independent libzbar QR decoding, synthetic captured mail,
optional MFA controls, supported locales, keyboard, mobile and synthetic RTL.
The independent decoder requires local Python/Pillow/libzbar for QA only.
For group-form-only changes, `DBLESS_CMS_USERS_GROUPS_ONLY=1 npm run test:browser:users`
uses a synthetic enrolled owner and checks the three cards, create/edit/save,
Back without writes, keyboard, both locales, mobile and RTL. This focused mode
does not rerun QR enrollment or the unchanged authentication/pentest journeys.

The existing local pentest catalog includes these controls. Core/full/release
profiles retain their previous scopes and distinguish missing tools/failures from
passes. Automated results do not certify complete ASVS/NIST compliance, hosting,
external delivery or resistance to real-time phishing. TOTP remains phishable;
no WebAuthn/passkey claim is made.

References: [RFC 6238](https://www.rfc-editor.org/rfc/rfc6238),
[PHP password_hash](https://www.php.net/manual/en/function.password-hash.php),
[OWASP password storage](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html),
[forgot password](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html),
[MFA](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html)
and [authorization](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).
