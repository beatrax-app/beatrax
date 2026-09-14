# Security Policy

Beatrax handles a person's complete banking history on their own
machine. That class of data carries real consequences if something goes
wrong, so we take security reports seriously and want them routed
through a private channel that lets us patch before the issue is
public.

## Supported versions

Beatrax ships as a desktop application that updates itself. Security
fixes go into the next release on each channel; there are no backports
to earlier versions.

| Version | Security fixes |
|---------|----------------|
| Latest stable release | Yes |
| Latest preview (`-rc`, `-beta`) release | Yes |
| Anything older | No — update to the latest release |

If you are not on the latest release, the first thing to do about any
security report is update. The in-app updater verifies the release it
installs; see [Verifying a release yourself](#verifying-a-release-yourself).

## Reporting a vulnerability

Use GitHub's **Private vulnerability reporting** feature, not the
public Issues tab. The form opens directly at:

<https://github.com/beatrax-app/beatrax/security/advisories/new>

Or reach it by hand: the repository's **Security** tab, then **Report a
vulnerability**. Fill in the form with as much detail as you can —
reproduction steps, affected versions, and impact analysis are all
helpful.

The report stays private between you and the maintainers until a fix
ships and we publish a coordinated disclosure.

If private vulnerability reporting is unavailable to you, **do not put
the details in a public issue**. Open a public issue that says only that
you have a security report and nothing about what it is — no component,
no version, no reproduction — and we will open a private advisory and
invite you to it. GitHub documents the feature and its prerequisites in
[Privately reporting a security
vulnerability](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability).

### What not to send us

Beatrax parses real bank exports, so the natural way to demonstrate a
parsing or import bug is to attach the file that triggered it. **Do not
send us your own financial data.** A report is not a safe place for it:
advisory threads are readable by everyone invited to them, and we do not
want your statements any more than you want us to have them.

Instead, send the smallest synthetic file that reproduces the problem —
redact account numbers, counterparties and amounts, or rebuild the file
by hand from the format's structure. If a bug genuinely cannot be
reproduced without real data, say so in the report and we will work out
how to narrow it without you sending any.

The same goes for screenshots, logs and database copies: redact before
attaching. If you have already sent something you would rather not have,
tell us and we will delete it and say when we did.

## Scope

**In scope:**

- The Beatrax application code in this repository.
- The bundled PHP dependencies declared in `composer.json` (Laravel,
  Livewire, Flux, genkgo/camt, brick/money, etc.) when the vulnerability
  is reachable through Beatrax's specific usage.
- The bundled Electron/NativePHP shell layer in the released
  installers.
- The auto-update path (Ed25519 manifest signing + SHA-512 binary
  verification).
- Local data-at-rest assumptions (SQLite database file, OAuth-token
  encryption at rest).

**Out of scope:**

- Vulnerabilities in third-party services Beatrax integrates with
  (Gmail API, Microsoft Graph, your OS) unless they are triggered
  exclusively by a flaw in Beatrax's own handling.
- Operating-system-level security on the user's machine. Beatrax assumes
  the host OS is uncompromised; OS-level compromise is outside what a
  user-space application can defend against.
- Social engineering of the maintainer, GitHub itself, or any
  GitHub-managed infrastructure.
- Issues that require a user to actively grant Beatrax destructive
  permissions on their own machine.
- Theoretical risks that have no demonstrable reproduction.

## Safe harbor

Good-faith security research is welcome. If you act in good faith — you
report through the private channel, you don't exploit beyond what's
needed to demonstrate the issue, you don't access data that isn't
yours, and you don't disclose publicly before the coordinated date —
the maintainers will not pursue legal action.

Coordinated disclosure timeline: by default, **90 days from
acknowledgment to public disclosure**. If we need more time to ship a
fix safely, we'll ask. If you need to disclose sooner because of an
ongoing exploit in the wild, tell us and we'll work out an accelerated
timeline together.

## Response timeline

| Stage | Target |
|-------|--------|
| Acknowledgment of report | within 7 days |
| Triage decision (in scope, severity, planned fix window) | within 14 days |
| Patch ships or detailed status update | within 60 days |
| Public disclosure (coordinated with reporter) | 90 days from acknowledgment, unless extended |

These are targets, not guarantees — Beatrax is maintained by a small
team in their spare time. If something slips, we'll tell you why.

## Verifying a release yourself

You do not have to take a download on trust, and you do not need us to
verify it for you. Every release publishes, beside the installers, the
auto-update manifest for each platform and a detached Ed25519 signature
under the same name plus `.sig`.

The public half of that key is committed to this repository, in
`config/auto_update.php` as `publisher_public_key_hex`. A public key is
not a secret, and pinning it in the source is deliberate: it is the
trust anchor, so it must not be something a runtime `.env` can swap.

To check a manifest against it, with PHP and libsodium:

```php
php -r '
$key = hex2bin("PASTE publisher_public_key_hex HERE");
$sig = hex2bin(trim(file_get_contents("latest-linux.yml.sig")));
var_dump(sodium_crypto_sign_verify_detached($sig, file_get_contents("latest-linux.yml"), $key));
'
```

`true` means the manifest is the one the release pipeline produced.
`false` means it is not, and you should not install what it points at —
tell us through the reporting flow above.

The manifest carries the installer's SHA-512, so verifying the manifest
and then checking the installer's digest against it covers the whole
chain from the committed key to the bytes on your disk.

What this proves is provenance, not absence of bugs: a correctly signed
release is one we built, which is exactly what it claims and nothing
more.

## Credit

Reporters who follow this policy and want public credit will be named
in the release notes for the patched version (or in a security
advisory). Reporters who prefer anonymity will be respected.
