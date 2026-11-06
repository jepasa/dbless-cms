# Core email delivery

Global Settings contains a separate **Email delivery** card. It selects PHP
`mail()` or an authenticated SMTP client for Users password links/security alerts,
Contact and enabled Forms. The card has its own save button and revision; saving
the public site document does not change mail credentials.

## Configure a transport

1. Sign in as an administrator with system-management permission and open Global
   Settings. Complete required authenticator enrollment first.
2. Choose **PHP mail()** for the host's configured local MTA, or **SMTP client**
   for a mail submission provider. There is no automatic fallback between them.
3. Set the sender address/name and the trusted CMS HTTPS base URL used for recovery
   links. Include the installation subdirectory, but do not append `/cms-admin`.
   HTTP is accepted only on loopback for local qualification. Request Host and
   editorial settings never supply the recovery origin.
4. For SMTP, enter a DNS name or IP (without a scheme/path), port, encryption,
   username and password. STARTTLS commonly uses port 587; implicit TLS commonly
   uses 465. Use the provider's actual requirements. A provider app password may
   be required. OAuth and client-certificate authentication are not implemented.
5. Save **Email settings**. A saved confirmation means configuration persistence,
   not a successful connection or inbox delivery. Verify delivery with your own
   authorized deployment workflow; saving never sends a test message.

SMTP requires verified TLS 1.2 or newer. The client verifies both the certificate
chain and peer name with the host's OpenSSL trust store. STARTTLS must be advertised
and negotiated before AUTH, followed by a new EHLO. No insecure certificate switch,
plaintext SMTP, downgrade or fallback to `mail()` is available. Native PHP streams,
OpenSSL and Sodium are used; no new framework or third-party runtime is installed.
Without a configured client FQDN, EHLO uses the connected client's address literal
as allowed by [SMTP](https://www.rfc-editor.org/rfc/rfc5321.html); no example domain
or request Host is submitted as the client's identity.
See [PHP's SSL context](https://www.php.net/manual/en/context.ssl.php),
[STARTTLS](https://www.rfc-editor.org/rfc/rfc3207),
[peer identity](https://www.rfc-editor.org/rfc/rfc7817.html) and
[the TLS minimum](https://www.rfc-editor.org/rfc/rfc8997.html).

## Credentials and compatibility

Private state lives below the configured storage root at `mail/settings.json`;
the independent encryption key is `keys/mail.key`. Both files use mode 0600.
New private directories use 0700. These paths are outside `contents` and public
HTML, and are excluded from content history, production exports and Git. Back up
private configuration and its encryption key together using the operations runbook.
Missing/corrupt keys or ciphertext fail closed; reads never regenerate keys.

The SMTP password must be recoverable by the mail client. It is therefore protected
with authenticated XChaCha20-Poly1305 encryption, independently of Users' password
hashes and TOTP key. Neither the password nor ciphertext is sent back to the form.
An empty password field keeps the stored credential; **Remove the stored SMTP
password** explicitly deletes it. Select PHP mail() before deleting a password that
an active SMTP configuration requires. Other SMTP options remain stored when
switching transports, so changing method does not silently erase them.

Before the first card save, PHP mail() remains the default and existing private
`app.json` values `users.origin`/`users.from` are combined in memory only. After
saving, the private mail card is authoritative. It never modifies `app.json`, the
public site JSON, form enablement, collection notices or recipient selection.
The existing contact-enabled flag still controls Contact/Forms independently.

## Delivery semantics and qualification

One request submits one bounded text message, using MIME UTF-8 and protected
headers. SMTP responses, credentials, recipient addresses, bodies and reset links
are never written to a transcript by the client. Connection/read/write operations
are bounded; host DNS resolution additionally follows the host resolver policy.
There is no queue, attachment handling or automatic retry.

A positive final DATA response means server acceptance, not inbox delivery. If
the connection is lost after DATA without confirmation, the outcome is uncertain;
Forms preserves its uncertain receipt and refuses automatic replay. A confirmed
acceptance remains successful even if closing the connection fails. Users recovery
keeps its generic public reply, including private configuration/transport failures.
SMTP does not bypass reset tokens, MFA, CSRF, permissions or rate limits.

`doctor` checks selected-transport prerequisites without connecting or claiming
delivery. Local tests use generated certificate authorities and SMTP servers on
loopback, never real credentials or external destinations. They cover STARTTLS/TLS,
AUTH LOGIN/PLAIN, certificate/name failure, downgrade refusal, timeout, private
CAS/redaction, header injection and uncertain receipts. Hosting, provider access,
DNS policy and mailbox delivery remain deployment checks.

The local certificate fixtures require the OpenSSL command-line tool. The runtime
client requires only native PHP extensions. The pentest catalog includes PT-MAIL
in its relevant source/browser/package phases; a catalog entry alone is not a
claim that a full/release profile was executed.
