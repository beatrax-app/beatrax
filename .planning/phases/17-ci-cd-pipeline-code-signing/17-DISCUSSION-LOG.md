# Phase 17: CI/CD Pipeline + Code Signing - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-27
**Phase:** 17-CI/CD Pipeline + Code Signing
**Areas discussed:** Tag taxonomy + release channels, Ad-hoc vs Developer ID signing switch, Notarization timing + release-job topology, First-launch smoke-test depth

---

## Tag taxonomy + release channels

### Q1 — Tag patterns

| Option | Description | Selected |
|--------|-------------|----------|
| Stable + beta + alpha | `v*.*.*`, `v*-beta.*`, `v*-alpha.*`. Three channels. | |
| Stable + beta only | `v*.*.*` + `v*-beta.*`. Two channels. | |
| Single `v*` | What ROADMAP literally says. One channel. | |
| Stable + RC | `v*.*.*` + `v*-rc.*`. Two channels, semver-conventional. | ✓ |

**User's choice:** Stable + RC.
**Notes:** RC naming is closer to semver convention than beta; same workflow cost as the beta-only option. Phase 21's invite-only beta cohort will consume the preview channel.

### Q2 — Publish mode

| Option | Description | Selected |
|--------|-------------|----------|
| Stable=draft, RC=immediate | Asymmetric. Stable held for human eyeball, RC ships immediately. | ✓ |
| Always immediate | Tag = released. Fastest, riskiest. | |
| Always draft | Maximum safety; RC doesn't auto-distribute. | |
| Immediate w/ auto-rollback | Publish, un-publish if smoke fails. Complex. | |

**User's choice:** Stable=draft, RC=immediate.
**Notes:** Best safety/velocity trade for a one-person ship. Catches bad stable releases before they auto-distribute; lets the beta cohort get fixes fast.

### Q3 — Version source of truth

| Option | Description | Selected |
|--------|-------------|----------|
| Tag is source; release.yml writes into bundle at build time | Zero drift risk. `config/nativephp.php` default → `0.0.0-dev`. | ✓ |
| `config/nativephp.php` is source; tag must match | Two places to update. CI verifies and fails on mismatch. | |
| Both, kept in sync by a composer script | More plumbing for marginal benefit. | |

**User's choice:** Tag is source of truth.
**Notes:** Cutting a release reduces to `git tag vX.Y.Z && git push --tags`.

---

## Ad-hoc vs Developer ID signing switch

### Q1 — Hook switch mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| Env-var gate `NATIVEPHP_USE_DEVELOPER_ID=1` | Explicit, testable, reversible. | ✓ |
| Auto-detect Developer ID in keychain | Zero config but silent behavior changes on machine state. | |
| Remove hook from prebuild on release job | Brittle config patching. | |
| Two separate hook scripts (force_adhoc + force_developer_id) | Symmetric but more files. | |

**User's choice:** Env-var gate.
**Notes:** One Pest test per branch covers both paths.

### Q2 — macOS signing config once hook steps aside

| Option | Description | Selected |
|--------|-------------|----------|
| Explicit `mac.identity` injected by a second prebuild hook | Symmetric; never relies on auto-discovery. | ✓ |
| Let electron-builder auto-discover from keychain | This is exactly the partial-signing bug the ad-hoc hook prevents. | |
| Hard-code identity in committed electron-builder.mjs | Leaks identity name into the repo. | |

**User's choice:** Explicit symmetric injection hook.
**Notes:** Reads `MAC_SIGNING_IDENTITY` from signing-prod GitHub Environment.

### Q3 — Windows signing pattern

| Option | Description | Selected |
|--------|-------------|----------|
| Mirror macOS pattern with `nativephp_inject_windows_signing.php` | Consistent, testable, same early-exit semantics. | ✓ |
| Direct entries in `electron-builder.mjs` reading env at eval time | Less code, mixes signing config into the file. | |
| Use Azure/trusted-signing-action's post-build sign step on the .msi | Decouples build from sign; different shape from macOS. | |

**User's choice:** Mirror macOS pattern.
**Notes:** Three signing-related prebuild hooks total: ad-hoc (default), inject-developer-id (mac release), inject-windows-signing (win release). Each gated by env var; compose without ordering concerns.

---

## Notarization timing + release-job topology

### Q1 — Notarization sequencing

| Option | Description | Selected |
|--------|-------------|----------|
| Single job, `notarytool --wait`, then staple + publish | Simplest; holds one runner up to 45 min. | ✓ |
| Two jobs: build+submit, then await+staple | Cheaper runner use, adds orchestration. | |
| Single job, submit-without-wait + manual staple later | Risky; unstapled = slow Gatekeeper checks. | |

**User's choice:** Single job with `--wait`.
**Notes:** Personal-velocity release cadence (every few weeks) doesn't justify orchestration cost.

### Q2 — Timeout + retry policy

| Option | Description | Selected |
|--------|-------------|----------|
| 45-min notarytool timeout, fail-loud, no auto-retry | Standard. Re-run on same tag is trivial. | ✓ |
| 45-min timeout with one auto-retry on transient errors | Helps with Apple 502s but masks recurring issues. | |
| 60-min timeout, fail-loud | More headroom; cost without benefit unless observed. | |
| 20-min timeout, fail-loud | Strict cost discipline; risks failing on normal slowness. | |

**User's choice:** 45-min, fail-loud, no auto-retry.
**Notes:** Artifact is deterministic; re-running release.yml on same tag is trivial.

### Q3 — Job topology

| Option | Description | Selected |
|--------|-------------|----------|
| Three parallel matrix jobs, all must succeed before publish | ~45 min wall-clock; no partial-release ambiguity. | ✓ |
| Three parallel, publish what succeeded | Allows shipping macOS even if Windows flakes; partial release messy. | |
| Serial mac → win → linux | ~3x wall-clock; easier to reason about. | |

**User's choice:** Three parallel, all-or-nothing publish.
**Notes:** Failure on any platform fails the whole release. Consistent with later smoke-test policy.

---

## First-launch smoke-test depth

### Q1 — Smoke depth

| Option | Description | Selected |
|--------|-------------|----------|
| Install → launch → HTTP-ready → `/health` → exit | Proves boot + Gatekeeper + Laravel + SQLite. Low flakiness. | ✓ |
| Install → launch → wait N sec → check process alive → exit | Cheapest; doesn't prove Laravel boots. | |
| Install → launch → screenshot dashboard → diff baseline | Highest signal, highest flakiness. | |
| Install → launch → Playwright assert login screen | Mid signal, high CI complexity. | |

**User's choice:** HTTP `/health` probe.
**Notes:** UI rendering correctness deferred to Phase 21 manual UAT.

### Q2 — `/health` route location and payload

| Option | Description | Selected |
|--------|-------------|----------|
| Public `/health` in Modules/Core, returns `{status, versions}` JSON | Auth-free, useful for Phase 21 troubleshooting. | ✓ |
| Auth-free `/health` returns just `200 OK` no body | Minimum signal. | |
| CLI command `php artisan diederik:health` | Awkward shelling into installed bundle. | |
| Reuse Dev Mode `diederik:doctor` | Requires Dev Mode middleware/auth; wrong for fresh install. | |

**User's choice:** Public route with versions JSON.
**Notes:** No timestamp in the payload — would break deterministic assertions.

### Q3 — Smoke-test failure policy

| Option | Description | Selected |
|--------|-------------|----------|
| Smoke failure fails the whole release | Consistent with all-must-succeed job topology. | ✓ |
| Linux failures warn-only; mac/win block | Asymmetric; only worth it if Linux flakes. | |
| Always publish, mark failures in release notes | Too risky. | |

**User's choice:** Fail the whole release.
**Notes:** Investigate, fix, re-tag.

---

## Claude's Discretion

- Exact secrets enumeration in the `signing-prod` GitHub Environment (working list provided in CONTEXT.md; planner refines against action docs).
- Exact `gitleaks-action` invocation + any allowlist needed for the Phase 15 anonymized fixture files.
- CI-06 sentinel file path + name (somewhere under `UserDataPathService`-resolved storage path).
- `.env.bundled` template content (env-var names with placeholder values).
- CODEOWNERS file location + content.
- Windows + Linux smoke-test shell choice (PowerShell on Win, plain bash + curl on Linux).
- Port discovery mechanism for the smoke test (temp file? env var? fixed port for smoke-only builds?).
- PR-gate matrix `fail-fast` policy (recommend keeping `fail-fast: false`).

## Deferred Ideas

- `electron-updater` runtime + Ed25519 manifest signing — Phase 18.
- Final brand assets (.icns, .ico, logo-512.png) — Phase 19 (REL-04).
- Auto-rollback on smoke-test failure — rejected; draft-by-default + fail-all-on-failure is sufficient.
- Two-job notarization split — rejected; personal-velocity cadence doesn't justify orchestration.
- Headless windowed-app smoke test (Playwright) — deferred to Phase 21 manual UAT.
- Sentry crash reporting — out of scope (TELE-01, v2.1 candidate).
