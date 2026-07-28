# Verifying a release

How to confirm, by hand, that a downloaded beatrax installer matches the bytes the
release pipeline published. Two checks: a SHA-256 checksum that ensures the file is
intact, and an Ed25519 signature that ensures the manifest itself was produced by the
release pipeline rather than a man-in-the-middle.

The auto-update path performs both checks automatically on every update. This recipe
exists for users who want to verify a manually-downloaded installer before launching
it.

## Quick check — SHA-256 only

Every published release includes a `beatrax-{version}-checksums.txt` file alongside the
installer binaries. Download both:

```sh
VERSION=0.1.0
PLATFORM=mac   # or 'win' or 'linux'
EXT=dmg        # or 'exe', 'msi', 'AppImage', 'deb'

# macOS / Linux
shasum -a 256 -c beatrax-${VERSION}-checksums.txt --ignore-missing

# Or for a single file
shasum -a 256 beatrax-${VERSION}-${PLATFORM}.${EXT}
# Expected: the hex string in beatrax-${VERSION}-checksums.txt
```

On Windows (PowerShell):

```powershell
$Version = "0.1.0"
Get-FileHash "beatrax-$Version-win.exe" -Algorithm SHA256
# Compare against the matching line in beatrax-$Version-checksums.txt
```

A matching checksum means the file was downloaded intact. It does **not** prove the
file came from the release pipeline — only the Ed25519 signature can do that.

## Full check — Ed25519 manifest signature

The `latest.yml`, `latest-mac.yml`, and `latest-linux.yml` files published with each
release carry SHA-512 hashes for every binary in that release. The signature on those
manifest files is what proves the release was produced by the pipeline and has not been
tampered with in transit.

### Step 1 — Get the publisher public key

The Ed25519 publisher public key is committed in `config/auto_update.php` (top of the
file, as a 64-character hex string). It is the same key the shipped app embeds. You can
also read it from the release page directly:

```sh
gh release view v${VERSION} --json body --jq '.body' \
  | grep -A 1 'Ed25519 publisher public key' \
  | tail -n 1
```

If the project ever rotates the publisher key, both the in-bundle constant and the
release-page footer move in lockstep — see the [Architecture Decision Records](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/)
for the related publisher-trust decisions.

### Step 2 — Download the manifest and signature

For a macOS release:

```sh
gh release download v${VERSION} --pattern 'latest-mac.yml' --pattern 'latest-mac.yml.sig'
```

For Linux: `latest-linux.yml` + `.sig`. For Windows: `latest.yml` + `.sig`.

### Step 3 — Verify the signature

The recommended path is the small `verify.sh` script shipped on the release page:

```sh
gh release download v${VERSION} --pattern 'verify.sh'
chmod +x verify.sh
./verify.sh latest-mac.yml latest-mac.yml.sig
# Expected output: "Signature OK"
```

The script wraps `sodium_crypto_sign_verify_detached` via a one-liner PHP invocation —
it is a thin convenience over what the auto-updater does in-process on every check.

If you would rather not run the supplied script, the same check can be done with the
PHP CLI directly:

```sh
php -r "
\$pub  = hex2bin(trim(file_get_contents('publisher.pubkey.hex')));
\$msg  = file_get_contents('latest-mac.yml');
\$sig  = file_get_contents('latest-mac.yml.sig');
echo sodium_crypto_sign_verify_detached(\$sig, \$msg, \$pub) ? 'OK' : 'FAIL';
echo PHP_EOL;
"
```

`publisher.pubkey.hex` holds the public key string from step 1.

### Step 4 — Verify the binary matches the manifest

Once the manifest signature checks out, the SHA-512 entry inside the manifest binds the
binary to the signed manifest. Read the entry and compare:

```sh
# Extract the published SHA-512 for the macOS binary
grep -A 4 'beatrax-' latest-mac.yml | grep 'sha512:'

# Hash the file you downloaded
shasum -a 512 beatrax-${VERSION}-mac.dmg
```

A matching SHA-512 across both steps means the binary you have is exactly the binary
the pipeline produced — the manifest signature gives you the manifest's authenticity,
and the in-manifest SHA-512 gives you the binary's match to that manifest.

## What a failure means

| Symptom | What it means |
|---|---|
| SHA-256 mismatch | The download was corrupted or tampered with in flight. Re-download from the official Release page. |
| Manifest signature fails | The manifest was modified after being signed, or you are looking at the wrong publisher public key. Do not run the installer. Open an issue on the repo. |
| Manifest signature passes, in-manifest SHA-512 mismatches | The binary you have does not match the signed manifest. Re-download from the same Release page; if the second download also mismatches, do not run the installer. |

The combination of an in-bundle public key, an Ed25519-signed manifest, and a SHA-512
chain through to the binary is the project's sole binary-integrity signal in the
absence of paid OS-level signing. See
[`../legal/license-rationale.md#no-paid-signing`](https://github.com/beatrax-app/spec/blob/main/90-appendix/license-rationale.md#no-paid-signing)
for the trade-off and
[`../cicd/release-workflow.md`](https://github.com/beatrax-app/spec/blob/main/70-operations/releasing.md) for how the manifest gets
signed in the first place.
