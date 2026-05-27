# License Rationale

The two longer-form decisions that the README and `NOTICE.md` link out
to: why the project ships under the Hippocratic License 3.0 instead of
an OSI-approved permissive license, and why it ships without paid
code-signing certificates.

## Why Hippocratic License 3.0?

beatrax handles a person's full banking history, their email receipts,
and the funding chains between their accounts. That class of code earns
trust by being readable — by shipping its full source so the people who
run it can audit what it does on their own machine. Closing the source
would have been the simpler legal choice; it would have been the wrong
product choice.

Three things had to be true at once:

1. **The source has to be visible.** The whole privacy story of a
   local-only personal-finance app collapses if the user has to take the
   maintainer's word for "nothing leaves your machine." Shipping the
   source means the claim is auditable.

2. **The source has to be redistributable in some form.** Without
   redistribution rights, users can't fork their own copy, can't pin a
   specific version, can't ship a patched build to a friend. A
   completely closed license blocks the kind of community contribution
   that small open-development projects depend on.

3. **The license has to express that the code is not a tool for harm.**
   beatrax is a finance product. Finance products show up in
   surveillance and rights-abuse contexts. Adopting a license that names
   that risk explicitly is a low-cost way to say "we made this for
   personal use; don't repurpose it to hurt people."

OSI-approved permissive licenses (MIT, BSD, Apache-2.0) satisfy
requirements 1 and 2 but cannot satisfy 3 — the Open Source Definition
forbids restrictions on fields of endeavor. OSI-approved copyleft
licenses (GPL, AGPL) satisfy 1 and 2 but turn distribution into a viral
copyleft event for anyone who builds on the code; that's the wrong
trade-off for a single-user dashboard. Closed-source licenses fail
requirement 1.

The Hippocratic License 3.0 satisfies all three. It is **source-available**
(not OSI-approved): the source is visible, copies and modifications are
permitted for individual use, but the license binds licensees to a set of
ethical-use clauses drawn from international human-rights frameworks. The
trade-off — losing OSI approval, losing the ability to bundle this code
under a permissive umbrella — is acceptable for a single-user product
that has no aspirations of becoming a permissively-licensed library
dependency for other projects.

If you need an OSI-approved license for procurement, downstream
relicensing, or bundling reasons, beatrax is not the right project for
your use case. The license text at the repo root in `LICENSE` is the
authoritative document; this rationale is just the human explanation
behind it.

## Why no paid signing certificates? {#no-paid-signing}

beatrax ships installers for macOS, Windows, and Linux. On macOS and
Windows, the first-launch experience for unsigned (or ad-hoc-signed)
apps is a security dialog that asks the user to confirm they want to
run software the operating system can't tie to a paid developer
identity. The two ways to make that dialog go away are:

- **Apple Developer ID** — a USD 99 / year subscription tied to an
  individual or organization. Apps signed with a Developer ID get an
  "identified developer" badge that Gatekeeper accepts on first launch.
  Notarization (also Apple-controlled) goes a step further and removes
  the dialog entirely.
- **Azure Trusted Signing** — Microsoft's hosted signing service,
  currently priced at USD 10 / month plus identity-verification
  requirements. Windows-signed builds skip the SmartScreen "Windows
  protected your PC" dialog.

beatrax does not subscribe to either. The reasoning is small and
specific:

1. **Both gate shipping on a recurring subscription.** If the
   subscription lapses for any reason — payment method expires, billing
   email goes to spam, identity-verification renewal misses a deadline —
   builds stop being signable and the project can't cut a release until
   the subscription is restored. For a project that aspires to be
   shippable in any month a maintainer has an hour spare, that's a
   fragile gating mechanism.

2. **Neither provides binary integrity that the auto-update path doesn't
   already provide.** Every release publishes SHA-512 hashes inside an
   Ed25519-signed update manifest. The shipped app verifies the manifest
   signature against a public key embedded in the bundle, then verifies
   the SHA-512 of every downloaded binary against the manifest. That
   chain catches a tampered update payload regardless of whether the
   installer was code-signed by the OS vendor.

3. **The first-launch dialog is a one-time cost the user can bypass
   themselves.** The README install section walks through the specific
   click sequences on macOS (Right-click → Open → "Open Anyway") and
   Windows (More info → "Run anyway"). Linux has no equivalent
   friction — `chmod +x` for AppImage, `sudo dpkg -i` for the deb.

The trade-off is real but bounded: users see one OS-level dialog on
first launch, with a brand-aware footnote in the README explaining what
they're seeing and why. From that point forward, both platforms launch
beatrax normally. Subsequent updates ride the auto-update path, which
carries the Ed25519 + SHA-512 integrity check on every binary — so the
auto-update mechanism is more thoroughly verified than a typical
code-signed app whose update payload often relies only on the OS vendor
signature.

For users who want to verify a release manually, every published
release ships a SHA-256 checksum file and the Ed25519-signed manifest.
The verification recipe lives in [`.docs/runbooks/verify-release.md`](../runbooks/verify-release.md).
