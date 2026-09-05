# Verifying a release

How to confirm, by hand, that a downloaded Beatrax installer matches the bytes the
release pipeline published. Every check here is the same shape: a hash that binds a
file, inside a document that carries an Ed25519 signature proving the release pipeline
produced it rather than a man-in-the-middle.

The auto-update path performs both halves automatically on every update. This recipe
exists for users who want to verify a manually-downloaded installer before launching
it.

## What a release actually publishes

Two kinds of hashed document, both signed with the same publisher key:

| Asset | What it is |
|---|---|
| `beatrax-<version>-checksums.txt` | SHA-256 of **every** other asset on the page, one per line, in `shasum -c` format |
| `latest-mac.yml` | Version, size and SHA-512 of the macOS `.dmg` |
| `latest.yml` | The same for the Windows `.exe` |
| `latest-linux.yml` | The same for the Linux `.AppImage` |
| `<document>.sig` | The Ed25519 detached signature over that document, hex-encoded |
| the installers | Whatever each platform job produced, plus the sideloadable Android `.apk` |

The manifests exist for `electron-updater`, so each one covers exactly the single
installer its `path:` field names. The checksum file exists for a human with a
downloaded file, so it covers everything — including the `.msi`, the `.deb` and the
`.apk`, which appear in no manifest. It is written over whatever the publish job
downloaded rather than over a list of expected names, and the `verify published` job
reads the asset list back off the release page and fails if any of it is missing from
the file.

The `.apk` additionally carries its own Android signature, and the build job refuses to
upload one that `apksigner` does not attribute to the release keystore.

Watch the encoding: the manifests store SHA-512 the way `electron-updater` expects it,
**base64 of the raw digest**, not hex, so comparing one against `shasum -a 512` output
will never match. The checksum file is ordinary lowercase hex.

## Quick check — the checksum file

The one check that covers every asset. It proves the file downloaded intact; it does
**not** prove the file came from the release pipeline until you verify the signature on
the checksum file itself, two sections down.

```sh
VERSION=1.3.0
gh release download "v${VERSION}" --pattern '*-checksums.txt'

# Everything you have in this folder, checked against the published hashes.
# --ignore-missing skips the assets you did not download.
shasum -a 256 -c "beatrax-${VERSION}-checksums.txt" --ignore-missing
```

On Windows there is no `shasum`, so read the two hashes and compare them by eye —
`Get-FileHash` prints upper case and the file holds lower case:

```powershell
$Version = "1.3.0"
$Installer = "beatrax-$Version-win.exe"

(Get-FileHash $Installer -Algorithm SHA256).Hash.ToLower()
Select-String -Path "beatrax-$Version-checksums.txt" -SimpleMatch "  $Installer"
```

## Quick check — the manifest's SHA-512

The auto-updater's own path, for the one installer a manifest names. Same guarantee as
the section above, reached a different way.

```sh
VERSION=1.3.0
gh release download "v${VERSION}" --pattern 'latest-mac.yml'

# The manifest names the one file it covers. Read it rather than guessing:
# electron-builder derives the filename from the product name and version,
# and the release workflow globs for it rather than naming it.
INSTALLER=$(sed -n 's/^path: //p' latest-mac.yml)
gh release download "v${VERSION}" --pattern "$INSTALLER"

# What the pipeline published
sed -n 's/^sha512: //p' latest-mac.yml

# What you have. tr because GNU base64 wraps at 76 columns and the
# manifest holds one unbroken line.
openssl dgst -sha512 -binary "$INSTALLER" | base64 | tr -d '\n'; echo
```

For Linux read `latest-linux.yml`, for Windows `latest.yml`; the rest is identical.

On Windows, PowerShell's `Get-FileHash` prints hex, so hash and encode explicitly:

```powershell
$Sha = [System.Security.Cryptography.SHA512]::Create()
$Bytes = [System.IO.File]::ReadAllBytes($Installer)   # the manifest's path: value
[Convert]::ToBase64String($Sha.ComputeHash($Bytes))
# Compare against the sha512: line in latest.yml
```

## Full check — the Ed25519 signature

A hash on its own proves only that a file arrived intact from wherever it came. The
signature over the document holding that hash is what proves the release pipeline
produced it. The checksum file and every manifest carry one, from the same key: it is
the same check the auto-updater runs in-process on every poll, and the same one the
release workflow's `verify published` job re-runs against the live release page
immediately after publishing.

Verify whichever document you used above — the checksum file if you took the quick
check, the manifest if you took the auto-updater's path. The steps are identical apart
from the filename.

### Step 1 — Get the publisher public key

The Ed25519 publisher public key is `publisher_public_key_hex` in
`config/auto_update.php`, a 64-character hex string. It is a literal rather than an
env-overridable value precisely so a runtime `.env` write cannot swap the trust anchor,
and it is the same key the shipped app embeds and the same one the pipeline verifies
against. Read it from the tag you are verifying:

```sh
PUBLIC_HEX=$(gh api "repos/beatrax-app/beatrax/contents/config/auto_update.php?ref=v${VERSION}" \
  -H 'Accept: application/vnd.github.raw' \
  | grep -oE "'[0-9a-f]{64}'" | head -1 | tr -d "'")
echo "$PUBLIC_HEX"
```

If the project ever rotates the publisher key, that constant is where the rotation
lands — old bundles keep verifying the old key until they cross over. See the
[Architecture Decision Records](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/)
for the related publisher-trust decisions.

### Step 2 — Download the document and its signature

The checksum file, which covers every asset:

```sh
gh release download "v${VERSION}" --pattern '*-checksums.txt' --pattern '*-checksums.txt.sig'
```

Or a manifest, for a macOS release:

```sh
gh release download "v${VERSION}" --pattern 'latest-mac.yml' --pattern 'latest-mac.yml.sig'
```

For Linux: `latest-linux.yml` + `.sig`. For Windows: `latest.yml` + `.sig`.

### Step 3 — Verify the signature

The `.sig` file holds the detached signature **as hex text**, so it has to be decoded
before `sodium_crypto_sign_verify_detached` sees it. Any PHP build with libsodium, which
core PHP bundles, can run the check directly:

```sh
DOCUMENT=beatrax-${VERSION}-checksums.txt   # or latest-mac.yml
PUBLIC_HEX="$PUBLIC_HEX" DOCUMENT="$DOCUMENT" php -r '
$name   = getenv("DOCUMENT");
$body   = file_get_contents($name);
$sig    = sodium_hex2bin(trim(file_get_contents($name . ".sig")));
$public = sodium_hex2bin(getenv("PUBLIC_HEX"));
echo sodium_crypto_sign_verify_detached($sig, $body, $public) ? "OK" : "FAIL";
echo PHP_EOL;
'
```

A `FAIL` here means the document and the key do not agree. Do not go on to install
anything it describes.

### Step 4 — Verify the binary matches the document

The signature covers the document, not the installer. The hash entry inside the
now-trusted document is what carries that trust across to the file on disk, so re-run
the comparison from whichever quick check you took, and treat a mismatch as fatal rather
than as a corrupt download.

Together the two steps give the whole chain: the signature gives you the document's
authenticity, and the hash inside it gives you the binary's match to that document.

## What a failure means

| Symptom | What it means |
|---|---|
| Hash mismatch, signature not yet checked | The download was corrupted or tampered with in flight. Re-download from the official Release page. |
| Signature fails | The document was modified after being signed, or you are looking at the wrong publisher public key. Do not run the installer. Open an issue on the repo. |
| Signature passes, the hash inside it mismatches | The binary you have does not match the signed document. Re-download from the same Release page; if the second download also mismatches, do not run the installer. |
| An asset on the page appears in no checksum line | It was not published by the pipeline. The `verify published` job fails the release when that is true of anything, so on a release that went green it should be impossible. |

The combination of an in-bundle public key, an Ed25519-signed document, and a hash chain
through to the binary is the project's own binary-integrity signal, independent of the
platform's. A release cut under the current workflow also carries OS-level
signatures on two of the three desktop platforms: the macOS job refuses to build without
the six Developer ID and notarisation credentials, the Windows job refuses without the
seven Azure Trusted Signing ones, and both then interrogate the produced artifact
because a green `native:build` does not prove a signature — an unsigned fallback exits
zero with a warning. Not every release on the page was cut under that gate, though: an
artifact from a tag old enough to predate it is genuinely unsigned at the OS level, and
the Ed25519 chain is the whole of what it has.

That is also why the Ed25519 chain stays the authoritative one here. It covers Linux,
which has no OS-level signing step at all and deliberately none: no desktop equivalent
of Developer ID or Authenticode exists that a `.deb` or an AppImage carries and a Linux
desktop checks on launch, so a signing identity there would be one nothing verifies. It
covers the `.msi` and the `.deb`, which no publisher manifest names. And it is what the
auto-updater verifies on every poll. See
[`90-appendix/license-rationale.md#no-paid-signing`](https://github.com/beatrax-app/spec/blob/main/90-appendix/license-rationale.md#no-paid-signing)
for the trade-off and
[`70-operations/releasing.md`](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md)
for how the manifest gets signed in the first place.
