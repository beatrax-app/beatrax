# Phase 17: CI/CD Pipeline + Code Signing - Context

**Gathered:** 2026-05-27
**Status:** Ready for planning

<domain>
## Phase Boundary

Wire the full CI/CD pipeline that turns a `git tag` push into signed,
notarized desktop installers published to GitHub Releases. The existing
single-axis PR-gate skeleton (`.github/workflows/ci.yml`, Phase 15 PKG-07)
becomes a full multi-axis quality gate; a new `release.yml` runs the
sign + notarize + publish pipeline on three platform runners.

Phase 17 ships:

- `.github/workflows/ci.yml` widened to the **PHP 8.4 + 8.5 matrix** that
  Phase 15 deliberately left as a single-axis skeleton (`['8.4']` →
  `['8.4', '8.5']`). Larastan L10 strict + Pint + Pest still run with
  `TZ=Europe/Amsterdam` on ubuntu-latest.
- `.github/workflows/release.yml` — tag-triggered. **Two tag patterns:**
  `v*.*.*` → stable channel (draft release); `v*-rc.*` → preview channel
  (immediate publish). Three parallel platform jobs (macOS-14 +
  windows-2025 + ubuntu-24.04) feed a final publish job; all must succeed.
- **macOS signing**: a new `nativephp_inject_developer_id.php` prebuild
  hook (symmetric to the existing ad-hoc hook) reads `MAC_SIGNING_IDENTITY`
  from the `signing-prod` GitHub Environment and patches `mac.identity`
  explicitly. `apple-actions/import-codesign-certs v7.0.0` provisions the
  keychain; Apple notarytool `submit --wait --timeout 45m` blocks the job
  until notarized; stapling runs in the same job before publish.
- **Windows signing**: a new `nativephp_inject_windows_signing.php` prebuild
  hook (same pattern) reads `AZURE_*` env vars and patches `win.signtoolOptions`
  for Azure Trusted Signing via `Azure/trusted-signing-action v2.0.0`.
- **Ad-hoc-vs-Developer-ID switch**: the existing
  `scripts/nativephp_force_adhoc_signing.php` gets an env-var early-return
  (`NATIVEPHP_USE_DEVELOPER_ID=1` → exit 0 without patching). Local builds
  continue to ad-hoc-sign; release runs export the var and the Developer-ID
  hook takes over.
- **Linux installers**: unsigned `.AppImage` + `.deb`. Same publish job
  uploads them alongside the signed macOS/Windows artifacts.
- **Smoke tests** per platform: install → launch → wait for HTTP-ready →
  hit a new `/health` route in `Modules/Core` → exit. Smoke failure on any
  platform fails the whole release.
- **Versioning**: `config/nativephp.php` default flips from `1.0.0` to
  `0.0.0-dev`; the git tag (minus leading `v`) is exported as
  `NATIVEPHP_APP_VERSION` before `native:build`. Tag is source of truth.
- **GitHub Release publishing**: `softprops/action-gh-release v2`.
  Asymmetric mode — `v*.*.*` creates a **draft** release (human eyeballs
  before clicking Publish); `v*-rc.*` is **immediately published** to the
  preview channel so the Phase 21 beta cohort gets it via auto-update.
- **Secrets + safety**: every signing secret lives in the `signing-prod`
  GitHub Environment (with branch restrictions); CODEOWNERS protects
  `.github/workflows/`; `gitleaks-action@v2` scans every PR; release.yml
  triggers ONLY on tag push (never `pull_request_target`), so fork PRs
  cannot exfiltrate signing certificates.
- **CI-06 first-launch APP_KEY regeneration**: a sentinel-absent check in
  the Phase-15 `FirstLaunchBootstrap` triggers `php artisan key:generate
  --force`; first-launch encryption-key generation for `oauth_secrets`
  follows the same sentinel pattern. `.env.bundled` template contains zero
  real secrets — just the env-var names the bundle expects.

Phase 17 does NOT ship:

- **`electron-updater` runtime + manifest consumption** — Phase 18. Phase
  17 publishes the manifest (`latest.yml`, `latest-mac.yml`,
  `latest-linux.yml`) as a byproduct of `electron-builder`; Phase 18 wires
  the in-app updater that reads it.
- **Ed25519 manifest signing/verification** — Phase 18.
- **Final brand-asset polish** (`.icns` / `.ico` / `logo-512.png`) —
  Phase 19 (REL-04) is the canonical owner. Phase 17 uses whatever
  Phase 15 already committed in `resources/brand/` for installer icons;
  Phase 19 may re-export and replace.
- **README / LICENSE / SECURITY / CONTRIBUTING / CODE_OF_CONDUCT** — Phase 19.
- **`.planning/` leakage redaction sweep** — Phase 19 (REL-05). Phase 17
  produces no user-facing copy that would leak GSD codenames.

</domain>

<decisions>
## Implementation Decisions

### Tag Taxonomy + Release Channels

- **D-01: Tag patterns.** `v*.*.*` triggers the stable channel.
  `v*-rc.*` triggers the preview channel. (No alpha tier — RC covers the
  Phase 21 beta cohort use case cleanly.)
- **D-02: Asymmetric publish mode.** Stable tags create a GitHub Release
  in **draft** state — the human eyeballs the artifacts + changelog +
  installs one locally before clicking Publish. RC tags publish
  immediately so the Phase 21 beta cohort gets them via auto-update.
- **D-03: Tag is source of truth for version.** `config/nativephp.php`
  default changes from `'1.0.0'` to `'0.0.0-dev'`. `release.yml` strips
  the leading `v` from the pushed tag and exports
  `NATIVEPHP_APP_VERSION=<version>` before `native:build`. The action of
  "cutting a release" reduces to `git tag vX.Y.Z && git push --tags`.
- **D-04: Two electron-updater channels exist from day 1** — `stable`
  and `preview` — so Phase 18 has a clear consumption contract. The
  Phase 18 planner reads this as a locked input.

### Ad-hoc vs Developer ID Signing Switch

- **D-05: Env-var gate on the existing ad-hoc hook.** Add an early-return
  at the top of `scripts/nativephp_force_adhoc_signing.php`:
  ```php
  if (getenv('NATIVEPHP_USE_DEVELOPER_ID') === '1') {
      fwrite(STDOUT, "nativephp_force_adhoc_signing: skipping — Developer ID build (NATIVEPHP_USE_DEVELOPER_ID=1).\n");
      exit(0);
  }
  ```
  release.yml exports the env var before `native:build`. Local + dev
  builds remain ad-hoc-signed (no behavior change). One Pest test per
  branch (var set → no patch; var unset → patches as today).
- **D-06: macOS signing uses a symmetric prebuild hook.** New script
  `scripts/nativephp_inject_developer_id.php` reads `MAC_SIGNING_IDENTITY`
  env (e.g. `"Developer ID Application: <legal name> (<team-id>)"`) and
  injects `mac.identity: "<value>"` into the `mac: { ... }` block of
  `electron-builder.mjs`. **Never** rely on `electron-builder`'s
  auto-keychain-discovery — that is the exact partial-signing bug the
  ad-hoc hook was created to prevent (see comments in the existing
  script). Mirrors the existing hook's idempotency check + `/s` regex
  pattern + dual-path resolution (published vs vendor electron dir).
- **D-07: Windows signing uses a symmetric prebuild hook too.** New
  script `scripts/nativephp_inject_windows_signing.php` reads `AZURE_*`
  env vars from the `signing-prod` environment and patches
  `win.signtoolOptions` / `win.publisherName` / signtool path for Azure
  Trusted Signing. Same testable pattern, same early-exit-when-env-missing
  semantics as the macOS hook.
- **D-08: Hook composition rules.** Three signing-related prebuild hooks
  total: `nativephp_force_adhoc_signing` (default, exits when env set),
  `nativephp_inject_developer_id` (exits when env unset),
  `nativephp_inject_windows_signing` (exits when env unset). They compose
  with no ordering concerns. Each gets a Pest unit test mirroring the
  existing `ForceAdhocSigningScriptTest` pattern.

### Notarization + Release Job Topology

- **D-09: Single macOS job with `notarytool submit --wait`.** No
  build-then-await split. macOS job: checkout → setup PHP → composer
  install → build → import certs → sign → `notarytool submit --wait
  --timeout 45m` → staple → upload artifact. Simplest topology; matches
  the personal-velocity release cadence (a release every few weeks, not
  daily) so optimizing runner-time isn't worth the orchestration cost.
- **D-10: 45-minute notarytool timeout, 60-minute job timeout, fail-loud,
  no auto-retry.** `notarytool --timeout 45m` + `timeout-minutes: 60`
  on the job. On timeout the job fails and the human investigates
  (Apple outage, cert issue, entitlement rejection). Re-running release.yml
  on the same tag is trivial since the artifact is deterministic.
- **D-11: Three parallel matrix jobs, all must succeed before publish.**
  macOS + Windows + Linux build/sign/test concurrently. A final `publish`
  job (`needs: [build-mac, build-win, build-linux]`) collects artifacts
  and creates the GitHub Release. No partial-release ambiguity — failure
  on any platform fails the whole release. Wall-clock ~45 min worst case.
- **D-12: PR gate (ci.yml) is reused as a job in release.yml.** Before
  any build job runs, `release.yml` first runs the same quality gates
  (Larastan L10 strict + Pint + Pest on both 8.4 + 8.5). A tag pushed
  to a green main passes this trivially; a tag pushed to a broken main
  fails fast (~5 min) before the expensive signing jobs spin up.

### First-Launch Smoke Test

- **D-13: Smoke test depth — HTTP health probe.** Per-platform: install
  the bundle → launch the app → wait for the embedded HTTP server to
  become ready → `curl /health` → assert 200 + JSON payload → exit. Proves
  installer works, Gatekeeper/SmartScreen accept the signature, NativePHP
  boots the embedded PHP, Laravel boots far enough to respond, SQLite is
  creatable at `UserDataPath`. Does NOT cover UI rendering (defer to
  Phase 21 manual UAT).
- **D-14: New `/health` route in `Modules/Core`.** Auth-free (must work
  before any user exists in the bundle). Returns
  `{ status: "ok", app_version, php_version, sqlite_version }` JSON.
  Lives in `Modules/Core/Routes/web.php`. The same endpoint is useful
  for Phase 21 beta cohort troubleshooting ("open
  `http://127.0.0.1:<port>/health` to confirm what version you're on").
  No sensitive data — versions only.
- **D-15: Smoke failure fails the whole release.** Consistent with the
  parallel-jobs-all-must-succeed decision (D-11). A bad signature, busted
  bundle, or Gatekeeper rejection on any platform → no GitHub Release is
  created. Investigate, fix, re-tag (or move the tag).

### Claude's Discretion

- **Exact secrets enumeration** in the `signing-prod` GitHub Environment.
  Working list the planner should refine against the action docs:
  `MAC_SIGNING_IDENTITY`, `MAC_CSC_LINK` (P12 base64), `MAC_CSC_KEY_PASSWORD`,
  `APPLE_API_KEY`, `APPLE_API_KEY_ID`, `APPLE_API_ISSUER`, `AZURE_TENANT_ID`,
  `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET`, `AZURE_CODE_SIGNING_ACCOUNT_NAME`,
  `AZURE_CERTIFICATE_PROFILE_NAME`. Exact names follow the action
  conventions of `apple-actions/import-codesign-certs v7.0.0` and
  `Azure/trusted-signing-action v2.0.0`.
- **Exact gitleaks-action invocation** + any allowlist needed for the
  Phase 15 anonymized fixture files (`scripts/anonymize_*.php` outputs).
- **CI-06 sentinel file path + name** — somewhere under the
  `UserDataPathService`-resolved storage path; likely
  `<storage_path>/.app-key-generated` and `<storage_path>/.oauth-key-generated`.
  Exact path is planner's call.
- **`.env.bundled` template content** — the env-var names the bundle
  expects to find, with placeholder values. Planner enumerates from the
  current `.env` minus secrets.
- **CODEOWNERS file location + content** — standard `.github/CODEOWNERS`
  with `/.github/workflows/ @<github-username>`.
- **The Windows + Linux smoke-test runners** — Windows: PowerShell
  `Start-Process` + `Invoke-WebRequest http://127.0.0.1:<port>/health`;
  Linux: `xdotool` probably not needed since we're hitting HTTP, just
  background-spawn + curl. Planner picks the cleanest shell per platform.
- **Port discovery for the smoke test** — the NativePHP-bundled HTTP
  server picks a port at boot; the smoke test needs to discover it
  (read from a temp file the bundle writes? env var? fixed port for
  smoke-only builds?). Planner picks.
- **PR-gate matrix `fail-fast` policy** — current ci.yml uses
  `fail-fast: false`. Phase 17 widens to 8.5; keep `fail-fast: false`
  so both axes always run.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope & requirements

- `.planning/ROADMAP.md` § "Phase 17: CI/CD Pipeline + Code Signing" —
  goal + 4 success criteria; explicitly names the action versions
  (`apple-actions/import-codesign-certs v7.0.0`,
  `Azure/trusted-signing-action v2.0.0`,
  `softprops/action-gh-release v2`) and the runner versions (macOS 14,
  Windows 2025, Ubuntu 24.04). Treat these as LOCKED.
- `.planning/REQUIREMENTS.md` — CI-01, CI-02, CI-03, CI-04, CI-05, CI-06
  (six requirements in scope).
- `.planning/STATE.md` § "Blockers/Concerns" — three Phase 17 items
  flagged for planning: PHP 8.4-vs-8.5 spike (closed — bundle stays on
  8.4, dev pin moved to 8.4 per Phase 15-05); Windows signing pricing
  (Azure Trusted Signing $10/mo confirmed); macOS notarization timing
  in CI (mitigated by D-09 + D-10 above).

### Project conventions & milestone context

- `.planning/PROJECT.md` — v2.0 milestone goal, supplied logo asset,
  Hippocratic License 3.0 posture, local-only constraint, DI-only rule,
  modular boundary rule.
- `CLAUDE.md` — DI-only rule (constructor injection; no facades / global
  helpers); module Public/Internal split; cross-module access only via
  Public service classes or events; Larastan L10 + Pint + Pest gate.
- `.planning/STATE.md` — current milestone position; carried-forward
  decisions from earlier v2.0 phases.

### Prior-phase context this phase depends on

- `.planning/phases/15-desktop-shell-nativephp-integration/15-CONTEXT.md`
  — D-21..D-23 first-launch bootstrap (CI-06's APP_KEY sentinel hooks
  into the same bootstrap); D-20 brand asset location at
  `resources/brand/logo.svg`; the SC3 routing caveat about Modules
  ownership. Phase 17 is also explicitly listed as deferring code
  signing + notarization execution to this phase.
- `.planning/phases/14-queue-rewire-horizon-carve-out/14-CONTEXT.md` —
  shipped bundle uses `QUEUE_CONNECTION=database`; no Redis/Horizon in
  the `--no-dev` tree; `DIEDERIK_DEV_MODE` gates dev-only features.
  `release.yml` runs `composer install --no-dev` for the build job —
  Horizon + predis stay out of the bundle.
- `.planning/phases/13-app-paths/13-CONTEXT.md` —
  `NATIVEPHP_STORAGE_PATH` resolves the authoritative path;
  `UserDataPathService` routes all I/O. CI-06's sentinel file (D-18 in
  Claude's discretion above) lands at the
  `UserDataPathService`-resolved location.
- `.planning/phases/12-multi-user-activation/12-CONTEXT.md` — Fortify
  auth, `SESSION_DRIVER=database`; the smoke-test `/health` route in
  D-14 must remain accessible WITHOUT a session (auth-free).

### Existing CI + signing assets (in-repo)

- `.github/workflows/ci.yml` — current PR-gate skeleton. Phase 17
  widens the `php` matrix from `['8.4']` to `['8.4', '8.5']`. All
  other shape — extension list, cache key, Pint/PHPStan/Pest order,
  `TZ=Europe/Amsterdam`, `fail-fast: false`, `timeout-minutes: 15` —
  stays as-is.
- `scripts/nativephp_force_adhoc_signing.php` — existing prebuild hook;
  Phase 17 adds the `NATIVEPHP_USE_DEVELOPER_ID` early-return (D-05).
  Read the head comment in this file — it documents the partial-signing
  failure mode that justifies D-06's no-auto-discovery rule.
- `build/entitlements.mac.plist` — already configured with the two
  Hardened Runtime entitlement keys. Phase 17 does NOT modify this
  file; just references it in the macOS signing step.
- `Modules/Desktop/tests/Unit/ForceAdhocSigningScriptTest.php` — the
  Pest test pattern the two new hook tests (D-06, D-07) mirror. Same
  module is the right home for `InjectDeveloperIdScriptTest` and
  `InjectWindowsSigningScriptTest`.
- `Modules/Desktop/tests/Unit/HardenedRuntimeEntitlementsTest.php` —
  the entitlements-file Pest test. Phase 17 does not need to extend
  this — it just runs as part of the CI gate that proves the file
  is correct before signing.
- `config/nativephp.php` — `'version' => env('NATIVEPHP_APP_VERSION',
  '1.0.0')`. Phase 17 flips the default to `'0.0.0-dev'` per D-03.

### Other in-repo context

- `composer.json` — `php: ^8.4` (dev pin moved to 8.4 in Phase 15-05);
  Phase 17's PR-gate widens to `['8.4', '8.5']` matrix. `conflict`
  block on `webklex/*` + `ddeboer/imap` must NOT be relaxed — the
  CI workflow's PHP extension list deliberately omits `ext-imap`.
- `Modules/Core/Routes/web.php` — where the new `/health` route
  (D-14) lives.

No external ADRs — every architectural decision lives in `.planning/`.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets

- `scripts/nativephp_force_adhoc_signing.php` — the prebuild-hook
  pattern (idempotency check, dual-path resolution for published vs
  vendor electron dir, `/s`-flag regex scoping `identity:` to the
  innermost `mac: { ... }` block). The two new hooks (D-06, D-07)
  follow this shape exactly.
- `.github/workflows/ci.yml` — the existing single-axis skeleton.
  Phase 17 widens it; doesn't rewrite it. The cache key already
  parameterizes on `${{ matrix.php }}` so the cache fans out cleanly
  when 8.5 is added.
- `Modules/Desktop/Internal/NativeAppServiceProvider.php` — registers
  the bundle's runtime contracts. The `/health` route (D-14) is
  in `Modules/Core`, not `Desktop` — Core's contract surface is the
  right home for an auth-free runtime probe.
- `Modules/Core/Public/Services/UserDataPathService.php` — Phase 13's
  path-resolution contract. CI-06's APP_KEY sentinel file lives at a
  `UserDataPathService`-resolved location.
- The Phase 15-05 `FirstLaunchBootstrap` — runs every launch
  idempotently. CI-06's sentinel-based APP_KEY regen plugs into this
  existing hook; no new bootstrap pipeline needed.
- The `Modules/Desktop/tests/Unit/ForceAdhocSigningScriptTest.php`
  Pest test — model for the two new hook unit tests.

### Established Patterns

- DI-only: constructor injection everywhere; no facades / global
  helpers in module code. The new `/health` route handler follows
  this rule (resolve services via the controller's constructor).
- Module Public/Internal split. The `/health` route is Public.
- Every new boundary gets a Pest arch test invariant. Phase 17 adds
  no new module boundaries (works on `.github/`, `scripts/`,
  `config/`, and one new route in `Modules/Core`) so no new arch
  test is required.
- Tests live in `Modules/<Name>/tests/Unit/` or `tests/Feature/`.
  New script tests land in `Modules/Desktop/tests/Unit/`.
- Pint preset is Laravel default — no per-file overrides; new scripts
  must pass `vendor/bin/pint --test` unchanged.
- Larastan L10 strict + canvural strict rules + larastan-livewire
  are non-negotiable; all new code lands at level 10 from day 1.

### Integration Points

- **`.github/workflows/ci.yml`** — extends in place; matrix axis
  change only. PR gate stays the contract for PR-time velocity.
- **`.github/workflows/release.yml`** — NEW. Triggered on tag push
  matching `v*.*.*` or `v*-rc.*`. Job graph:
  `lint-and-test (reuse ci.yml gates) → [build-mac, build-win,
  build-linux] (parallel) → publish (needs all three)`.
- **`.github/CODEOWNERS`** — NEW. `/.github/workflows/ @<maintainer>`
  forces review on any workflow change.
- **`scripts/nativephp_inject_developer_id.php`** + **`scripts/nativephp_inject_windows_signing.php`** —
  NEW prebuild hooks; registered in `config/nativephp.php` `prebuild`
  array alongside the existing ad-hoc hook.
- **`config/nativephp.php`** — three changes: flip `version` default
  from `'1.0.0'` to `'0.0.0-dev'`; register the two new prebuild hooks;
  no `removed_env` change (already correct).
- **`Modules/Core/Routes/web.php`** — NEW `/health` route.
- **`Modules/Core/Public/Http/Controllers/HealthController.php`**
  (or similar) — NEW controller, DI-constructed, returns JSON.
- **`<storage>/.app-key-generated`** sentinel — NEW; created on first
  successful key:generate run inside the bundle.
- **`.env.bundled`** template — NEW; lives in repo root (or under
  `deploy/` next to the launchd plists).
- **`.gitleaks.toml`** (optional) — only if the default gitleaks
  config flags one of the anonymized fixture files.
- **Downstream phase dependencies:** Phase 18 (auto-update plumbing)
  consumes the channel structure (D-04), the published manifest
  (`latest.yml` / `latest-mac.yml` / `latest-linux.yml`), and the
  version-from-tag contract (D-03). Phase 19 (REL-04) may re-export
  the brand-asset PNGs that this phase consumed.

</code_context>

<specifics>
## Specific Ideas

- **Tag commands, verbatim:** `git tag v2.0.0 && git push --tags` (stable);
  `git tag v2.0.0-rc.1 && git push --tags` (preview).
- **Env-var name for the ad-hoc-hook switch:** `NATIVEPHP_USE_DEVELOPER_ID=1`
  (D-05). The check is `getenv(...) === '1'`, not `getenv(...) !== false`
  — explicit opt-in, no truthiness ambiguity.
- **`mac.identity` format, verbatim:** `"Developer ID Application: <legal
  name> (<team-id>)"` — the exact string the existing CSC certificate
  contains. Set via `MAC_SIGNING_IDENTITY` GitHub Secret.
- **`notarytool` invocation, verbatim:** `xcrun notarytool submit
  <path-to-dmg> --apple-id <APPLE_API_ISSUER> --key <APPLE_API_KEY>
  --key-id <APPLE_API_KEY_ID> --wait --timeout 45m`. Then
  `xcrun stapler staple <path-to-dmg>`.
- **`/health` response shape, verbatim:**
  ```json
  {
    "status": "ok",
    "app_version": "2.0.0",
    "php_version": "8.4.7",
    "sqlite_version": "3.45.1"
  }
  ```
  No timestamp (would break deterministic smoke-test assertions).
- **Smoke test assertion:** HTTP 200 + JSON shape match + `app_version`
  equals the tag (minus the leading `v`). No version mismatch tolerated.
- **Wall-clock budget:** `lint-and-test` ~5 min; parallel platform
  builds ~45 min worst case (macOS notarization-bound); publish ~1 min.
  Total ~50 min for a typical release.

</specifics>

<deferred>
## Deferred Ideas

- **`electron-updater` in-app integration** — Phase 18 (UPDATE-01,
  UPDATE-02, UPDATE-03, UPDATE-04). Phase 17 publishes the manifest
  files (`latest.yml`, `latest-mac.yml`, `latest-linux.yml`) as a
  byproduct of `electron-builder`; Phase 18 wires the runtime that
  reads them, plus Ed25519 manifest signing/verification.
- **Ed25519 manifest publisher key** — Phase 18. Phase 17 does not
  need to provision this key; the manifest published in Phase 17
  is consumed unsigned by a Phase 18 implementation that adds the
  signing key + verification logic before public beta.
- **Final brand assets** — Phase 19 (REL-04) is the canonical owner
  of `.icns`, `.ico`, `logo-512.png`, favicon. Phase 17 uses whatever
  Phase 15 already committed under `resources/brand/`; Phase 19 may
  re-export and replace before public release.
- **Auto-rollback on smoke-test failure** — considered, rejected.
  The fail-the-whole-release policy (D-15) plus draft-by-default for
  stable releases (D-02) already give the safety this would buy.
- **Two-job notarization (submit + await split)** — considered,
  rejected. Personal-velocity release cadence doesn't justify the
  orchestration cost. Reconsider only if release frequency grows to
  multiple per week.
- **Headless windowed-app smoke test (Playwright)** — considered,
  deferred to Phase 21 manual UAT. The HTTP `/health` probe (D-13)
  proves the bundle boots; UI rendering correctness is a Phase 21
  human-eyes concern.
- **Sentry crash reporting in release builds** — out of scope per
  PROJECT.md "Out of Scope" section; deferred to v2.1 as TELE-01
  with explicit decision required.

None of the above are scope creep — they are explicit phase boundaries
from the v2.0 ROADMAP. Discussion stayed within Phase 17's domain.

</deferred>

---

*Phase: 17-CI/CD Pipeline + Code Signing*
*Context gathered: 2026-05-27*
