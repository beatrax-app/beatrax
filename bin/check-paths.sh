#!/usr/bin/env bash
# bin/check-paths.sh — standalone grep gate. Exit 1 on any offender.
#
# PKG-01 / Phase 13 invariant: every filesystem path flows through
# Modules/Core/Public/Services/UserDataPathService.php so a NativePHP
# build can retarget the storage root. This script mirrors the
# noStoragePathHardCodedOutsideUserDataPathService arch test but is
# runnable without booting Pest, so CI can fail fast. The arch test
# remains the authoritative in-suite check; this is the fast pre-flight.
#
# public_path is intentionally NOT a banned helper — it is an abstracted
# accessor on UserDataPathService (public/ serves bundle assets, not
# user data), consistent with the arch test's regex.
set -euo pipefail

ALLOW="Modules/Core/Public/Services/UserDataPathService.php"

# Raw helper calls (bare function form) in production code.
helpers=$(grep -RInE '(^|[^>:_a-zA-Z])(database_path|storage_path|base_path)[[:space:]]*\(' \
    --include='*.php' --exclude='*.blade.php' \
    Modules app config 2>/dev/null \
    | grep -v "/tests/" | grep -v "/Database/Migrations/" \
    | grep -v "$ALLOW" || true)

# Hard-coded storage-layout string literals.
literals=$(grep -RInE "['\"](database\.sqlite|storage/app/)" \
    --include='*.php' --exclude='*.blade.php' \
    Modules app config 2>/dev/null \
    | grep -v "/tests/" | grep -v "/Database/Migrations/" \
    | grep -v "$ALLOW" || true)

if [[ -n "$helpers" || -n "$literals" ]]; then
  echo "FAIL: raw path helpers / storage literals outside UserDataPathService:"
  [[ -n "$helpers" ]]  && echo "$helpers"
  [[ -n "$literals" ]] && echo "$literals"
  exit 1
fi
echo "OK: no raw path helpers or storage literals outside UserDataPathService."
