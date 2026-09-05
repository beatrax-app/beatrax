# Verifying a release

How to confirm, by hand, that a downloaded Beatrax installer matches the bytes the
release pipeline published. Two checks: an Ed25519 signature over the auto-update
manifest, which proves the manifest was produced by the release pipeline rather than a
man-in-the-middle, and the SHA-512 hash inside that manifest, which binds one installer
to it.

The auto-update path performs both checks automatically on every update. This recipe
exists for users who want to verify a manually-downloaded installer before launching
it.

## What a release actually publishes

There is no checksum file on the release page. The hashes live inside the auto-update
manifests, and the manifests are what carry a signature:

| Asset | What it is |
|---|---|
| `latest-mac.yml` | Version, size and SHA-512 of the macOS `.dmg` |
| `latest.yml` | The same for the Windows `.exe` |
| `latest-linux.yml` | The same for the Linux `.AppImage` |
| `<manifest>.sig` | The Ed25519 detached signature over that manifest, hex-encoded |
| the installers | Whatever each platform job produced, plus the sideloadable Android `.apk` |

Each manifest covers exactly one installer — the one its `path:` field names. Anything
else on the page (`.msi`, `.deb`, `.apk`) has no manifest entry, so neither check below
applies to it. The `.apk` carries its own Android signature instead, and the build job
refuses to upload one that `apksigner` does not attribute to the release keystore.

The SHA-512 is stored the way `electron-updater` expects it: **base64 of the raw
digest**, not hex. Comparing it against `shasum -a 512` output will never match.

## Quick check — the manifest's SHA-512

This proves the file downloaded intact. It does **not** prove the file came from the
release pipeline — only the signature in the next section does that.

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

## Full check — Ed25519 manifest signature

The signature on a manifest is what proves the release was produced by the pipeline and
has not been tampered with in transit. It is the same check the auto-updater runs
in-process on every poll, and the same one the release workflow's `verify published`
job re-runs against the live release page immediately after publishing.

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

### Step 2 — Download the manifest and signature

For a macOS release:

```sh
gh release download "v${VERSION}" --pattern 'latest-mac.yml' --pattern 'latest-mac.yml.sig'
```

For Linux: `latest-linux.yml` + `.sig`. For Windows: `latest.yml` + `.sig`.

### Step 3 — Verify the signature

The `.sig` file holds the detached signature **as hex text**, so it has to be decoded
before `sodium_crypto_sign_verify_detached` sees it. Any PHP build with libsodium, which
core PHP bundles, can run the check directly:

```sh
PUBLIC_HEX="$PUBLIC_HEX" php -r '
$body   = file_get_contents("latest-mac.yml");
$sig    = sodium_hex2bin(trim(file_get_contents("latest-mac.yml.sig")));
$public = sodium_hex2bin(getenv("PUBLIC_HEX"));
echo sodium_crypto_sign_verify_detached($sig, $body, $public) ? "OK" : "FAIL";
echo PHP_EOL;
'
```

A `FAIL` here means the manifest and the key do not agree. Do not go on to install the
binary the manifest describes.

### Step 4 — Verify the binary matches the manifest

The signature covers the manifest, not the installer. The `sha512:` entry inside the
now-trusted manifest is what carries that trust across to the file on disk, so re-run
the comparison from the quick check above and treat a mismatch as fatal rather than as
a corrupt download.

Together the two steps give the whole chain: the signature gives you the manifest's
authenticity, and the in-manifest SHA-512 gives you the binary's match to that
manifest.

## What a failure means

| Symptom | What it means |
|---|---|
| SHA-512 mismatch, signature not yet checked | The download was corrupted or tampered with in flight. Re-download from the official Release page. |
| Manifest signature fails | The manifest was modified after being signed, or you are looking at the wrong publisher public key. Do not run the installer. Open an issue on the repo. |
| Manifest signature passes, in-manifest SHA-512 mismatches | The binary you have does not match the signed manifest. Re-download from the same Release page; if the second download also mismatches, do not run the installer. |

The combination of an in-bundle public key, an Ed25519-signed manifest, and a SHA-512
chain through to the binary is the project's own binary-integrity signal, independent of
the platform's. A release cut under the current workflow also carries OS-level
signatures on two of the three desktop platforms: the macOS job refuses to build without
the six Developer ID and notarisation credentials, the Windows job refuses without the
seven Azure Trusted Signing ones, and both then interrogate the produced artifact
because a green `native:build` does not prove a signature — an unsigned fallback exits
zero with a warning. Not every release on the page was cut under that gate, though: an
artifact from a tag old enough to predate it is genuinely unsigned at the OS level, and
the Ed25519 chain is the whole of what it has.

That is also why the Ed25519 chain stays the authoritative one here: it covers Linux,
which has no OS-level signing step at all, and it is what the auto-updater verifies on
every poll. See
[`90-appendix/license-rationale.md#no-paid-signing`](https://github.com/beatrax-app/spec/blob/main/90-appendix/license-rationale.md#no-paid-signing)
for the trade-off and
[`70-operations/releasing.md`](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md)
for how the manifest gets signed in the first place.
