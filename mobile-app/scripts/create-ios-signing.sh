#!/usr/bin/env bash
#
# create-ios-signing.sh
# ---------------------------------------------------------------------------
# Creates + downloads the iOS **Apple Distribution certificate** (.p12) and the
# **App Store provisioning profile** (.mobileprovision) for Beatrax, using the
# App Store Connect API key already stored in 1Password (no manual Keychain /
# Apple-portal clicking).
#
# Output files land in a git-ignored `mobile-app/build-secrets/` folder, ready
# to upload into Bifrost (Credentials -> iOS).
#
# WHAT THIS DOES NOT DO (on purpose):
#   - It does not upload anything to Bifrost.
#   - Secret values (the .p8 key, the generated signing key/.p12) never leave
#     your machine: they are read from 1Password / your keychain by fastlane,
#     which you run. Nothing is echoed to stdout.
#
# PREREQUISITES (macOS):
#   brew install jq                  # 1Password CLI (op) is assumed present
#   fastlane runs via bundler (mobile-app/Gemfile) — Homebrew's fastlane is
#   broken under Ruby 4.0.x. `bundle install` is run automatically if needed.
#   - The App Store Connect API key must exist in the "Beatrax" 1Password vault
#     item "beatrax iOS signing config (com.beatrax.mobile)" with fields:
#       "ASC API key id", "ASC API issuer", "p8 location" (path to the .p8).
#   - The App ID (com.beatrax.mobile) must be registered in Apple Developer.
#     If it is not, step 2 fails with a clear message — register it in the
#     portal (Identifiers) or via `fastlane produce -a com.beatrax.mobile`.
#
# Override any of these via env, e.g. BUNDLE_ID=... ./create-ios-signing.sh
# ---------------------------------------------------------------------------
set -euo pipefail

BUNDLE_ID="${BUNDLE_ID:-com.beatrax.mobile}"
VAULT="${OP_VAULT:-Beatrax}"
ITEM="${OP_ITEM:-beatrax iOS signing config (com.beatrax.mobile)}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="${OUT_DIR:-$(cd "$SCRIPT_DIR/.." && pwd)/build-secrets}"

# --- prerequisite checks ---------------------------------------------------
for bin in op bundle jq; do
  command -v "$bin" >/dev/null 2>&1 || {
    echo "ERROR: '$bin' not found in PATH." >&2
    [ "$bin" = "bundle" ] && echo "       Ruby/bundler is required — fastlane runs via 'bundle exec'." >&2
    [ "$bin" = "jq" ] && echo "       Install with: brew install jq" >&2
    exit 1
  }
done

if [ "$(uname -s)" != "Darwin" ]; then
  echo "ERROR: run this on macOS — certificate creation needs the login keychain." >&2
  exit 1
fi

# fastlane runs through bundler (Homebrew's fastlane is broken under Ruby 4.0.x).
# The Gemfile lives in mobile-app/, one level up from this script.
export BUNDLE_GEMFILE="$(cd "$SCRIPT_DIR/.." && pwd)/Gemfile"
[ -f "$BUNDLE_GEMFILE" ] || { echo "ERROR: Gemfile not found at $BUNDLE_GEMFILE" >&2; exit 1; }
bundle check >/dev/null 2>&1 || { echo "-> Installing fastlane gems (bundle install)..."; bundle install; }

# --- output dir (never committed) ------------------------------------------
mkdir -p "$OUT_DIR"
printf '# Auto-generated signing artifacts — never commit.\n*\n!.gitignore\n' > "$OUT_DIR/.gitignore"

# --- temp App Store Connect API-key JSON for fastlane ----------------------
# Locked to 0600 and shredded on exit; the .p8 contents only ever live here
# and in fastlane's memory, never in this script or its output.
API_KEY_JSON="$(mktemp -t asc_api_key)"
chmod 600 "$API_KEY_JSON"
cleanup() { rm -f "$API_KEY_JSON"; }
trap cleanup EXIT INT TERM

echo "-> Reading App Store Connect API key IDs from 1Password (identifiers only)..."
# op:// path refs can't contain '(' (the item title does), so read via `op item get`.
CRED_JSON="$(op item get "$ITEM" --vault "$VAULT" --format json)"
KEY_ID="$(printf '%s' "$CRED_JSON" | jq -r '.fields[]? | select(.label=="ASC API key id") | .value // empty')"
ISSUER_ID="$(printf '%s' "$CRED_JSON" | jq -r '.fields[]? | select(.label=="ASC API issuer") | .value // empty')"
unset CRED_JSON

# The vault's "p8 location" field is a human note (not a clean path), so resolve
# the staged .p8 under mobile-app/.signing/ (git-ignored). Override with:
#   P8_PATH=/path/to/AuthKey_XXXX.p8 ./scripts/create-ios-signing.sh
P8_PATH="${P8_PATH:-$(ls "$SCRIPT_DIR/../.signing/"AuthKey_*.p8 2>/dev/null | head -1)}"

if [ -z "${KEY_ID:-}" ] || [ -z "${ISSUER_ID:-}" ]; then
  echo "ERROR: could not read 'ASC API key id' / 'ASC API issuer' from the Beatrax vault note." >&2
  exit 1
fi
if [ -z "${P8_PATH:-}" ] || [ ! -f "$P8_PATH" ]; then
  echo "ERROR: App Store Connect .p8 not found. Expected mobile-app/.signing/AuthKey_*.p8," >&2
  echo "       or set P8_PATH=/path/to/AuthKey_XXXX.p8 before running." >&2
  exit 1
fi

# jq --rawfile safely escapes the multi-line PEM into the JSON "key" field.
jq -n --arg kid "$KEY_ID" --arg iss "$ISSUER_ID" --rawfile key "$P8_PATH" \
  '{key_id:$kid, issuer_id:$iss, key:$key, in_house:false}' > "$API_KEY_JSON"

# --- 1) Apple Distribution certificate -------------------------------------
echo "-> Creating/downloading the Apple Distribution certificate..."
bundle exec fastlane run get_certificates \
  api_key_path:"$API_KEY_JSON" \
  development:false \
  output_path:"$OUT_DIR"

# --- 2) App Store provisioning profile -------------------------------------
# App Store is get_provisioning_profile's default — passing adhoc/development
# (even as false) trips fastlane's "can't enable both" conflict guard, so we
# pass neither.
echo "-> Creating/downloading the App Store provisioning profile for $BUNDLE_ID..."
bundle exec fastlane run get_provisioning_profile \
  api_key_path:"$API_KEY_JSON" \
  app_identifier:"$BUNDLE_ID" \
  output_path:"$OUT_DIR"

echo
echo "Done. Artifacts written to: $OUT_DIR"
echo "  - Provisioning profile:     *.mobileprovision  (App Store, for $BUNDLE_ID)"
echo "  - Distribution certificate: *.cer  (PUBLIC half only)"
echo
echo "NOTE: no .p12 is written when an existing distribution certificate is reused"
echo "      (the private key stays in your login keychain). Export the .p12 yourself:"
echo "      Keychain Access -> 'Apple Distribution: <you>' -> right-click -> Export -> .p12 + password."
echo "      (Apple Distribution certs are team-wide; the same one signs every app in the team —"
echo "       the per-app scoping is the provisioning profile above.)"
echo
echo "Next (your hands):"
echo "  1. Export the .p12 (above)."
echo "  2. In Bifrost -> Credentials -> iOS, upload the .p12 (+ password) and the .mobileprovision,"
echo "     plus the App Store Connect API key (.p8 + Key ID + Issuer) for TestFlight/App Store upload."
echo "  3. Save the .p12 into the Beatrax 1Password vault, then delete $OUT_DIR."
