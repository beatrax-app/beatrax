---
phase: 16-developer-mode-ui
plan: 02
subsystem: rename
tags: [rename, composer-name, artisan-signatures, env-var, bundle-id, herd-hostname, impersonation-removal, arch-invariant]

# Dependency graph
requires:
  - phase: 16-developer-mode-ui
    plan: 01
    provides: "Sidebar brand row already locked to `beatrax` (D-10) and the `auth::partials.impersonation-banner` include site already removed from app.blade.php (D-11 foreshadow). The Blade partial file itself stayed on disk until this plan deleted it."
provides:
  - "composer.json name = beatrax/beatrax + every per-module composer.json renamed."
  - "Every former diederik:* artisan signature now resolves under beatrax:* (5 commands flipped; db:backup + db:restore stay native)."
  - "DIEDERIK_DEV_MODE env-var name flipped to BEATRAX_DEV_MODE in config/app.php + config/nativephp.php cleanup_env_keys; the `config('app.dev_mode')` KEY stays unchanged so every consumer keeps working."
  - "macOS LaunchAgent plists renamed from `com.diederik.*` to `com.beatrax.*` (tracked plist files + InstallCommand emit strings)."
  - "Herd hostname flipped from `diederik.test` to `beatrax.test` across .env.example, README, and one PHPDoc."
  - "Recovery-codes download filename + backup-file basename + Docker container/volume names flipped to beatrax-* prefixes."
  - "AppMenuBuilder verbatim consts: HELP_ABOUT, GITHUB_REPO_URL, REPORT_ISSUE_URL all flipped to beatrax / beatrax-app/beatrax."
  - "Phase 12 'Act as partner' surface deleted: 4 PHP files + 1 Blade partial + 2 test files (7 files total deleted) + impersonation routes + impersonation singleton bindings + middleware push + module.json keyword."
  - "Two new BoundaryArchTest invariants: `impersonationSurfaceRemoved` (file_exists check on the 5 deleted paths) and `noDiederikLiteralAfterRename` (recursive grep over Modules/ + tests/ + resources/ + config/)."
  - "New tests/Feature/BeatraxCommandsResolveTest.php: 3 behaviors (5-row dataset + 2 regression guards) proving the rename landed in the console kernel + composer manifest."
affects:
  - 16-03-dev-shell-layout
  - 16-04-overview-page
  - 16-05-artisan-runner
  - 16-06-dev-block-live-data
  - 16-08-command-palette
  - 17-cicd-pipeline-code-signing
  - 19-public-release-boundary

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Allow-listed arch invariant: a tight per-file allow-list (3 entries for noDiederikLiteralAfterRename, 0 for impersonationSurfaceRemoved) keeps the regression check honest while permitting the literal `diederik` to appear in deliberately-housing files (this plan's invariants + the regression-guard test + the sidebar render assertion)."
    - "Pure filesystem assertion for surface deletion: `file_exists(base_path(...))` instead of `class_exists()` — the latter triggers Composer's autoloader which may hold a stale entry on a recently-deleted file and emit a misleading 'failed to open stream' warning."
    - "Per-area commit split for a large rename: 5 commits along subsystem lines (artisan signatures + composer name; configs + scripts + README; Modules production; Modules tests + tests snapshots; impersonation deletion + arch invariants). Each commit stays bisectable and the suite is green between every commit boundary (W-2 cap honoured)."

key-files:
  created:
    - "tests/Feature/BeatraxCommandsResolveTest.php — 3-behavior regression suite (dataset of 5 renamed command names + no-diederik:* guard + composer name assertion)."
  modified:
    - "composer.json — name field flipped from diederik/diederik to beatrax/beatrax."
    - "config/app.php — sources BEATRAX_DEV_MODE env var (the config key `app.dev_mode` stays)."
    - "config/nativephp.php — cleanup_env_keys array uses BEATRAX_DEV_MODE."
    - "Modules/Core/Internal/Console/{DoctorCommand,FailedJobsCommand,InstallCommand,BackupDatabaseCommand}.php — signatures + emit strings + backup-file basename prefix flipped."
    - "Modules/Auth/Internal/Console/ResetPasswordCommand.php — signature + error string flipped."
    - "Modules/Ledger/Internal/Console/RederiveFingerprintsCommand.php — signature flipped."
    - "Modules/Auth/Providers/AuthServiceProvider.php — impersonation singleton bindings + middleware push removed."
    - "Modules/Auth/Routes/web.php — impersonation routes + imports removed."
    - "Modules/Auth/tests/Feature/CrossUserIsolationTest.php — impersonation `it()` block + allow-list entries removed; the other 9 isolation assertions stay green."
    - "Modules/Desktop/Internal/Native/AppMenuBuilder.php — HELP_ABOUT, GITHUB_REPO_URL, REPORT_ISSUE_URL consts flipped."
    - "Modules/EmailScan/Public/Services/OAuthSecretsRepository.php — PHPDoc 'developer impersonation' reference trimmed."
    - ".env.example — APP_NAME, APP_URL, MAIL_FROM_ADDRESS, BEATRAX_DEV_MODE flipped."
    - "README.md — operator-facing copy flipped (LaunchAgent names, plist filenames, Docker container/volume names, Herd hostname, beatrax:* artisan-call examples)."
    - "tests/Contracts/BoundaryArchTest.php — ImpersonationBannerMiddleware allow-list entry removed; two new invariants added (impersonationSurfaceRemoved + noDiederikLiteralAfterRename)."
    - "tests/.pest/snapshots/Snapshot/SidebarTest/.../snapshot_lock.snap — re-baselined to use post-rename `https://beatrax.test` route URLs."
    - "Plus 130 additional production + test files mechanically flipped from diederik->beatrax / Diederik->Beatrax (158 total file changes in this plan)."
  deleted:
    - "Modules/Auth/Public/Actions/ImpersonateUserAction.php"
    - "Modules/Auth/Public/Actions/EndImpersonationAction.php"
    - "Modules/Auth/Public/Dto/ImpersonationResult.php"
    - "Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php"
    - "Modules/Auth/Resources/views/partials/impersonation-banner.blade.php"
    - "Modules/Auth/tests/Feature/ImpersonationBannerTest.php"
    - "Modules/Auth/tests/Feature/ImpersonationActionTest.php"
  renamed:
    - "deploy/launchd/com.diederik.horizon.plist -> deploy/launchd/com.beatrax.horizon.plist"
    - "deploy/launchd/com.diederik.redis.plist   -> deploy/launchd/com.beatrax.redis.plist"
    - "deploy/launchd/com.diederik.scheduler.plist -> deploy/launchd/com.beatrax.scheduler.plist"

key-decisions:
  - "BackupDatabaseCommand backup-file basename prefix flipped from `diederik-` to `beatrax-` in the same commit as the artisan-signature flip. The plan's CONTEXT D-08 says 'every literal `diederik` / `Diederik`' and D-09 says 'no upgrade migration code — clean cut — app is not yet in anyone's hands'. Old-prefix backups on the developer's disk are out of scope; new backups land under the post-rename prefix and the BackupFreshnessProbe glob now matches `beatrax-*.sqlite.meta.json`."
  - "Tracked launchd plist files were git-mv'd to com.beatrax.*.plist + their <Label> + comments + Docker container/volume names + PHPDoc references all flipped. Previously-rendered + currently-loaded plists at ~/Library/LaunchAgents/com.diederik.* are runtime artifacts the developer hand-cuts over per D-09 (see Hand-off Notes)."
  - "Forecasting OpeningBalanceEditor Livewire method useDiederiksNumber + property $diederiksNumberMinor renamed to useBeatraxsNumber + $beatraxsNumberMinor in the same commit as the matching Blade `wire:click` + the sole test caller so the rename was atomic; no UI surface drifts from its data."
  - "`config('app.dev_mode')` KEY kept unchanged — only the source env-var name flipped. Every consumer (HorizonServiceProvider, future EnsureDeveloperMode middleware, etc.) keeps working without any source edits."
  - "`impersonationSurfaceRemoved` invariant uses pure file_exists checks instead of class_exists checks. class_exists triggers the Composer autoloader, which may hold a stale entry pointing at a recently-deleted file and emit a misleading 'failed to open stream' warning. file_exists against base_path() is deterministic and bisect-stable."
  - "`noDiederikLiteralAfterRename` allow-list kept tight: 3 files only (this invariant's own body, BeatraxCommandsResolveTest, AppSidebarRenderTest). `.snap` files are skipped wholesale — they are test infrastructure baselines and were re-baselined alongside the source rename."
  - "CI workflow files (.github/workflows/ci.yml), CLAUDE.md, and the `.planning/` historical SUMMARYs left untouched. Per CONTEXT deferred list: CI matrix updates are Phase 17 scope ('CI/CD axes for the renamed beatrax:* commands'); CLAUDE.md is the project-instruction doc (not under any scanned root); historical phase SUMMARYs are explicitly historical (Wave 1 SUMMARY already uses `beatrax` per D-10's early lock; older phase SUMMARYs are preserved as-written for historical accuracy)."

patterns-established:
  - "Per-area commit split for global renames: 5 commits sequenced (artisan-signatures + composer; env + configs + scripts + README; production code; test code + snapshots; surface deletion + arch invariants) with the suite green between every commit boundary. Bisectable + reviewable + matches W-2 commit-cap rule."
  - "Allow-listed arch invariant for regression guards: when an invariant body necessarily houses the banned literal, an explicit per-file allow-list (committed in the invariant code, with a justifying comment) is the right tool rather than a brittle regex carve-out."
  - "Pure-filesystem assertion for 'surface deletion' invariants instead of class_exists / function_exists checks — the latter pull the Composer autoloader and can emit misleading warnings on stale classmap entries."

requirements-completed: []  # 16-02-PLAN.md frontmatter requirements: [] — the rename is infrastructure, not a REQUIREMENTS.md entry.

# Metrics
duration: 50min
completed: 2026-05-24
---

# Phase 16 Plan 02: diederik -> beatrax Full Rename + Impersonation Surface Removal Summary

**Repo-wide rename of `diederik` / `Diederik` to `beatrax` / `Beatrax` across composer manifests, 5 artisan signatures, env var, macOS bundle id, Herd hostname, every Blade view + test fixture; Phase 12 'Act as partner' impersonation surface deleted (7 files); two new BoundaryArchTest invariants lock the result at PR time.**

## Performance

- **Duration:** ~50 min (research + inventory + 5 commits + verification)
- **Tasks:** 3 (TDD task 1 + literal sweep task 2 + impersonation deletion task 3)
- **Commits:** 5 atomic commits
- **Files modified:** 147
- **Files created:** 1 (tests/Feature/BeatraxCommandsResolveTest.php)
- **Files deleted:** 7 (impersonation surface)
- **Files renamed:** 3 (launchd plists)
- **Total file changes:** 158
- **Net line delta:** +667 / -1148 (a net 481-line shrink — the impersonation surface deletion dominates)

## Accomplishments

### Task 1 — Console + composer rename (commit `8e8cd76`)

- New `tests/Feature/BeatraxCommandsResolveTest.php` (3 behaviors, 7 test runs incl. dataset) proves:
  - Every former `diederik:*` command name now resolves under its `beatrax:*` signature in the Artisan kernel registry. Pest dataset enumerates 5 commands.
  - No `diederik:*` signature remains anywhere in the console kernel (regression guard against partial flips).
  - `composer.json` `name` field reads `beatrax/beatrax`.
- 5 console-command `protected $signature` declarations flipped to `beatrax:*`:
  `beatrax:doctor`, `beatrax:failed-jobs`, `beatrax:install`, `beatrax:reset-password`, `beatrax:rederive-fingerprints`. `db:backup` and `db:restore` stay native per the plan's lock.
- Every `$this->artisan('diederik:...')` test call across `Modules/*` + `tests/` flipped to `beatrax:...`.
- `composer.json` `name`: `diederik/diederik` -> `beatrax/beatrax`. `composer dump-autoload` succeeds.
- Tracked launchd plist files git-mv'd from `com.diederik.*.plist` to `com.beatrax.*.plist`; Label key + comments + Docker container/volume names + PHPDoc references all flipped. `InstallCommand` source-path string + plist filename emit string + log lines flipped to `com.beatrax.{name}.plist`.
- `BackupDatabaseCommand` backup-file basename prefix flipped from `diederik-` to `beatrax-`; matching `BackupFreshnessProbe` glob now searches `beatrax-*.sqlite.meta.json`.

### Task 2 — Literal sweep (commits `6bf074a`, `d4a7029`, `9859f21`)

Per-area commit split honouring the W-2 cap (>15 hits in a subsystem -> split):

1. **Commit 2a (`6bf074a`) — env + configs + scripts + README** (11 files):
   - `.env.example`: APP_NAME, APP_URL, MAIL_FROM_ADDRESS Herd hostname, `DIEDERIK_DEV_MODE` -> `BEATRAX_DEV_MODE`.
   - `config/app.php`: source env-var renamed (the config KEY `app.dev_mode` stays — every consumer keeps working).
   - `config/nativephp.php`: cleanup_env_keys array updated.
   - Other config/ files (database / email-scan / fortify / modules / session): in-comment fallback `diederik` refs flipped.
   - 2 scripts in `scripts/`: in-comment + emit-text refs flipped.
   - README: operator-facing copy flipped (LaunchAgent names, plist filenames, Docker container/volume names, Herd hostname, every `beatrax:*` artisan-call example).

2. **Commit 2b (`d4a7029`) — Modules production code** (83 files):
   - Per-module `composer.json`: package names flipped to `beatrax/<module>` (12 modules).
   - PHPDocs, in-class comments, error messages, log channel literals (every domain module).
   - Blade view copy (`'Use diederik's number'` -> `'Use beatrax's number'`, `'diederik prefer receipts'` -> `'beatrax prefer receipts'`, page <title> fallbacks, settings copy, system-alert messages).
   - `Forecasting/OpeningBalanceEditor`: Livewire method name + property rename (`useDiederiksNumber` -> `useBeatraxsNumber`; `$diederiksNumberMinor` -> `$beatraxsNumberMinor`) — PHP class + Blade `wire:click` + sole test caller all rewired in this commit.
   - `Desktop/AppMenuBuilder` verbatim consts: `HELP_ABOUT = 'About beatrax'`, `GITHUB_REPO_URL = 'https://github.com/beatrax-app/beatrax'`, `REPORT_ISSUE_URL = 'https://github.com/beatrax-app/beatrax/issues/new'`.
   - `Auth/Recovery/RecoveryCodeFormatter` filename: `diederik-recovery-codes-*.txt` -> `beatrax-recovery-codes-*.txt`.
   - `Core/Internal/Console/Probes/BackupFreshnessProbe` + `BackupRetentionPolicy` + `DurationParser`: every PHPDoc reference to the renamed commands.
   - `resources/css/app.css` + `app/PhpStan/Rules/BoundaryRule.php`: in-comment references.

3. **Commit 2c (`9859f21`) — Modules tests + tests root + snapshot re-baseline** (37 files):
   - Every PHPDoc, assertion-message, fixture string, and inline comment literal flipped.
   - `tests/.pest/snapshots/.../SidebarTest` snapshot baseline re-locked to use the post-rename `https://beatrax.test` route URLs (APP_URL flipped in .env per D-09).
   - `AppSidebarRenderTest` "brand row" assertion rewritten to its post-rename intent (Rule 1 deviation — see below).

### Task 3 — Impersonation deletion + arch invariants (commit `4811a70`)

- 7 files deleted: 4 PHP classes (ImpersonateUserAction, EndImpersonationAction, ImpersonationResult DTO, ImpersonationBannerMiddleware) + 1 Blade partial (impersonation-banner) + 2 Pest test files (ImpersonationActionTest, ImpersonationBannerTest).
- `Modules/Auth/Providers/AuthServiceProvider.php`: dropped the two impersonation singleton bindings, the `ImpersonationBannerMiddleware` `pushMiddlewareToGroup('auth', ...)` call, and the now-unused imports. Class docblock updated.
- `Modules/Auth/Routes/web.php`: removed the `/impersonate` (developer-gated, password-verified guard swap) and `/impersonate/end` (always-auth, return-to-self) POST routes + their action / DTO / `Illuminate\Http\Request` imports.
- `Modules/Auth/module.json`: removed `"impersonation"` keyword + the description tail.
- `Modules/Auth/tests/Feature/CrossUserIsolationTest.php`: removed the one `it()` block that exercised the impersonation session keys + the two impersonation route names from the route-coverage allow-list. The other 9 isolation assertions stay green.
- `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php`: PHPDoc "developer impersonation" reference trimmed.
- `tests/Contracts/BoundaryArchTest.php`:
  - The `ImpersonationBannerMiddleware` entry in the `noAuthFacadeOrHelper` allow-list is removed (the file no longer exists, so the allow-list entry would be dead weight).
  - **New invariant `impersonationSurfaceRemoved`**: pure `file_exists(base_path(...))` check against the 5 deleted paths. Fails if any file re-appears on disk.
  - **New invariant `noDiederikLiteralAfterRename`**: recursive grep over `Modules/`, `tests/`, `resources/`, `config/` for the case-insensitive literal `diederik`. Allow-list: 3 files only (the invariant body, BeatraxCommandsResolveTest, AppSidebarRenderTest — all three deliberately house the literal as their regression-assertion subject). `.snap` files are skipped wholesale.

## Verification

All quality gates green at plan close:

- `vendor/bin/pest --filter=BeatraxCommandsResolveTest`: 7 passed (3 behaviors, 1 includes a 5-row dataset)
- `vendor/bin/pest --filter='impersonationSurfaceRemoved|noDiederikLiteralAfterRename'`: 2 passed
- `vendor/bin/pest` (full sequential): **2196 passed**, 19 todos, 6 skipped, 0 failed (24832 assertions). 2 new tests vs Wave 1 baseline of 2194 (the two new arch invariants).
- `vendor/bin/phpstan analyse --memory-limit=2G`: **No errors** (Larastan L10 strict, 552 files analysed)
- `vendor/bin/pint --test`: **passed**
- `grep -rn "ImpersonateUserAction\|EndImpersonationAction\|ImpersonationBannerMiddleware\|auth\.impersonating\.original" Modules/ tests/`: returns only the arch invariant's banned-list entries (3 hits in `tests/Contracts/BoundaryArchTest.php`). Defense-in-depth check passes.
- `php artisan list | grep diederik`: returns nothing.
- `php artisan list | grep beatrax`: returns all 5 renamed commands.

## Task Commits

| Task | Commit | Title |
|------|--------|-------|
| 1 (TDD) | `8e8cd76` | refactor(16-02): rename diederik:* artisan signatures to beatrax:* and flip composer name |
| 2a      | `6bf074a` | refactor(16-02): rename diederik->beatrax in env, configs, scripts, README |
| 2b      | `d4a7029` | refactor(16-02): rename diederik->beatrax across Modules/* production code |
| 2c      | `9859f21` | refactor(16-02): rename diederik->beatrax across Modules/*/tests + tests/* |
| 3       | `4811a70` | refactor(16-02): delete Phase 12 impersonation surface and lock invariants |

The plan's `<interfaces>` block suggested 7-step per-area ordering; the actual split landed in 5 commits because steps 1+2+3+4 of the plan (composer name, signatures, env, configs, AppMenuBuilder) consolidated into 2 commits (Task 1 + Task 2a) without exceeding the W-2 cap, and steps 5+6+7 (modules + tests + planning) became Task 2b + Task 2c.

## Decisions Made

- **Backup-file prefix flipped in Task 1 commit** (`diederik-*.sqlite` -> `beatrax-*.sqlite`), not deferred. The BackupFreshnessProbe + BackupRetentionPolicy + every test fixture all glob on the prefix; deferring would have left fixtures testing the wrong prefix. D-09's "clean cut" justifies the immediate flip — backups on the developer's disk are runtime artifacts the developer hand-cuts over.
- **Forecasting OpeningBalanceEditor method + property renamed atomically** in Task 2b. The PHP class, the matching Blade `wire:click`, and the sole Pest test caller all live in three files that flipped together so the UI never drifts from the data.
- **`config('app.dev_mode')` KEY preserved** — only the source env var renamed. This means every existing consumer (HorizonServiceProvider, the future EnsureDeveloperMode middleware in 16-03, the Settings UI gate, etc.) keeps working without source edits.
- **CI workflow files (`.github/workflows/ci.yml`), CLAUDE.md, and the older `.planning/` phase SUMMARYs left untouched**. CONTEXT explicitly defers CI updates to Phase 17 ('CI/CD axes for the renamed beatrax:* commands'); CLAUDE.md is the project-instruction doc; older phase SUMMARYs are historical record.
- **`impersonationSurfaceRemoved` uses file_exists, not class_exists**. The latter triggers Composer's autoloader; if the classmap still references a recently-deleted file, the test emits a misleading "failed to open stream" warning instead of a clean assertion failure.
- **`noDiederikLiteralAfterRename` allow-list kept to 3 files**. Every other source/test file that contains the literal would be a regression. `.snap` files are skipped wholesale because they are test infrastructure baselines and were re-baselined in commit 2c.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Bootstrap the test environment that the worktree lacked**
- **Found during:** Task 1 setup (RED-phase test run prep)
- **Issue:** The worktree had no `.env`, no `vendor/`, no `database/database.sqlite`, and no `public/build/manifest.json`. Same per-worktree environment hygiene issue that Wave 1 surfaced.
- **Fix:** `cp .env.example .env && composer install && php artisan key:generate && touch database/database.sqlite && php artisan migrate --force && npm install && npm run build`. None of these artifacts is committed (`.env`, `vendor/`, `database/database.sqlite`, `public/build/`, `node_modules/` are all gitignored), but they are required for any test run in a fresh worktree.
- **Verification:** Full sequential Pest reached 2194 passed (the Wave 1 baseline) BEFORE Task 1 started.
- **Committed in:** N/A — environment-bootstrap actions, not tracked changes.

**2. [Rule 1 — Bug] Repair the self-contradictory AppSidebarRender brand-row assertion after the bulk flip**
- **Found during:** Task 2 (full Pest run after the bulk literal flip)
- **Issue:** The Wave 1 test asserted "rendered HTML contains `>beatrax</span>` AND does not contain `<span>diederik</span>`". The Task 2 bulk find/replace flipped the second-half literal from `<span>diederik</span>` to `<span>beatrax</span>`, turning the test into "contains `>beatrax</span>` AND does not contain `<span>beatrax</span>`" — self-contradictory; the brand row legitimately renders `<span>beatrax</span>`.
- **Fix:** Rewrite the assertion to its post-rename intent — the rendered sidebar HTML must contain `>beatrax</span>` AND must not contain any case-insensitive `diederik` substring anywhere. This is now an explicit post-rename regression guard (covered by the new `noDiederikLiteralAfterRename` arch invariant for source files; this assertion covers rendered HTML at request time).
- **Files modified:** `Modules/Core/tests/Feature/AppSidebarRenderTest.php`
- **Verification:** Test passes; the post-rename intent is preserved + tightened.
- **Committed in:** `9859f21` (commit 2c — alongside the broader test sweep).

**3. [Rule 2 — Missing critical functionality] Add Modules/Auth/module.json + OAuthSecretsRepository PHPDoc to the impersonation-deletion sweep**
- **Found during:** Task 3 grep sweep (step 5 of the plan's task body — defense-in-depth grep for any remaining "Impersonate / impersonating / impersonation" string)
- **Issue:** Two non-PHP-class references survived the file deletion: the `Modules/Auth/module.json` description + keywords array still listed "impersonation" as an Auth-module concern, and `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php` had a PHPDoc note "a guard swap — such as developer impersonation — is honoured immediately". The defense-in-depth grep in the plan's Task 3 step 5 explicitly asks for these to be either justified or removed.
- **Fix:** Trimmed `Modules/Auth/module.json` description + keywords; trimmed the OAuthSecretsRepository PHPDoc to "a guard swap is honoured immediately" (the surrounding paragraph already explains the cross-user scoping pattern, so the trimmed wording loses no information).
- **Files modified:** `Modules/Auth/module.json`, `Modules/EmailScan/Public/Services/OAuthSecretsRepository.php`
- **Verification:** Plan's defense-in-depth grep returns only the arch invariant's banned-list entries.
- **Committed in:** `4811a70` (Task 3 commit).

**4. [Rule 1 — Bug] Use file_exists instead of class_exists in `impersonationSurfaceRemoved`**
- **Found during:** Task 3 (running the new arch invariant for the first time)
- **Issue:** The first cut of `impersonationSurfaceRemoved` used `class_exists()` against the four banned FQCNs. Even though the files were deleted on disk, Composer's optimized classmap still held entries pointing at the deleted paths, and `class_exists()` triggered an `ErrorException: include(): Failed to open stream: No such file or directory` instead of returning false.
- **Fix:** Rewrote the invariant to use pure `file_exists(base_path(...))` checks against the 5 banned paths (4 PHP files + 1 Blade partial). The autoloader is never touched; the assertion is deterministic and bisect-stable. The PHPDoc explains the choice so a future contributor doesn't "fix" it back to class_exists.
- **Files modified:** `tests/Contracts/BoundaryArchTest.php`
- **Verification:** Both new invariants green.
- **Committed in:** `4811a70` (Task 3 commit, alongside the deletion + the invariant introduction).

**5. [Rule 3 — Blocking] Update README.md (out of the bulk-flip scope I'd originally drawn)**
- **Found during:** Task 2 verification (`ReadmeOperationalDocsTest` failing after Task 2a/2b — the test asserts README mentions `php artisan beatrax:doctor` literally).
- **Issue:** My first bulk-flip script scanned `Modules/ resources/ config/ app/ scripts/ deploy/ bootstrap/ tests/` — repo-root markdown files (README.md, CLAUDE.md) were outside that root list. README is in scope per CONTEXT D-08 ("every literal `diederik` / `Diederik` in `.planning/*`, tests, system_alerts copy, Blade views, log channel name" plus operator-facing copy), even though Phase 19 owns the public README rewrite.
- **Fix:** Re-ran the perl in-place flip against README.md (38 hits flipped); folded into commit 2a so the ReadmeOperationalDocsTest assertion passes alongside the env/config commit. CLAUDE.md left untouched (project-instruction doc, not in any scanned root, Phase 19 / docs-only sweep target).
- **Files modified:** `README.md`
- **Verification:** ReadmeOperationalDocsTest passes.
- **Committed in:** `6bf074a` (commit 2a — env + configs + scripts + README).

---

**Total deviations:** 5 auto-fixed (3 Rule 1 — bug; 1 Rule 2 — missing critical; 1 Rule 3 — blocking).
**Impact on plan:** All 5 are necessary follow-throughs of the plan's actual intent. The brand-row assertion fix and the file_exists rewrite are direct consequences of doing the work; the module.json / OAuthSecretsRepository trim is the plan's Task 3 step 5 grep sweep doing its job; the README scope was an inventory oversight on my part that the test suite caught immediately. No scope creep.

## Hand-off Notes

Per D-09, the rename does NOT carry any upgrade-migration code. The developer landing this plan must hand-cut over four runtime artifacts on their local machine. The committed `.env.example` already uses the post-rename values, and the snapshot test was re-baselined assuming the rename has landed in `.env`.

**Manual cut-over steps for the developer landing this plan on their box:**

1. **Edit local `.env`** (gitignored):
   - `APP_NAME=diederik` -> `APP_NAME=beatrax`
   - `APP_URL=https://diederik.test` -> `APP_URL=https://beatrax.test`
   - `DIEDERIK_DEV_MODE=true` -> `BEATRAX_DEV_MODE=true` (key rename — value preserved)
   - `MAIL_FROM_ADDRESS="hello@diederik.test"` -> `MAIL_FROM_ADDRESS="hello@beatrax.test"`

2. **Re-link Laravel Herd**:
   ```bash
   herd unlink diederik
   herd link beatrax
   ```
   (Or edit `~/.config/herd/...` directly.)

3. **Uninstall the old `.app` bundle** from `/Applications/diederik.app` (if a previous `php artisan native:build` produced one). The next `php artisan native:build` will produce `/Applications/beatrax.app` once `NATIVEPHP_APP_ID` is set to `com.beatrax.app` in the developer's `.env`.

4. **Re-register `~/Library/LaunchAgents/com.diederik.*` plists as `com.beatrax.*`** (if the developer set them up per Phase 15-03 via `php artisan beatrax:install --launchd`):
   ```bash
   # Unload + remove the old plists
   launchctl bootout gui/$(id -u) ~/Library/LaunchAgents/com.diederik.horizon.plist
   launchctl bootout gui/$(id -u) ~/Library/LaunchAgents/com.diederik.scheduler.plist
   launchctl bootout gui/$(id -u) ~/Library/LaunchAgents/com.diederik.redis.plist 2>/dev/null || true
   rm ~/Library/LaunchAgents/com.diederik.*.plist
   # Re-install under the new prefix
   php artisan beatrax:install --launchd  # add --without-redis if Docker Desktop autostarts
   ```

## Known Issues / Documented Trade-offs

- **`system_alerts` rows authored under the old name retain `diederik` content.** Per D-09 (no migration code), old alert bodies on the developer's local DB keep the literal `diederik`; every new row will render `beatrax`. Acceptable: the alerts are visible only to authenticated users on their own machine, and writing a one-shot migration introduces more risk than it prevents. Documented under T-16-06 in the plan's threat register.
- **CI workflow files (`.github/workflows/ci.yml`) untouched.** Per CONTEXT deferred list — Phase 17 owns CI matrix updates for the renamed `beatrax:*` commands.
- **CLAUDE.md and older `.planning/` phase SUMMARYs untouched.** CLAUDE.md is the project-instruction doc and lives outside any scanned root. Older phase SUMMARYs (12-* through 15-*) intentionally retain `diederik` for historical accuracy — the rename is a delta, and the historical record stays unaltered. Wave 1 already authored its SUMMARY using `beatrax` per D-10's early lock.
- **Old-prefix backup files (`diederik-YYYY-MM-DD-HHMMSS.sqlite*`)** on the developer's `storage/app/backups/` disk are runtime artifacts the developer hand-cuts over (delete or rename) per D-09. The BackupFreshnessProbe + BackupRetentionPolicy now glob exclusively on `beatrax-*.sqlite.meta.json`.

## Known Stubs

No stubs introduced by this plan. The Wave 1 stubs (`.side-search` placeholder input, Dev-block pulse static copy, `.side-badge` empty slots) remain as documented in 16-01-SUMMARY.md — they are not affected by the rename.

## Self-Check: PASSED

Files asserted present:

- `tests/Feature/BeatraxCommandsResolveTest.php` — FOUND
- `composer.json` (post-rename name) — FOUND, contains `"name": "beatrax/beatrax"`
- `config/app.php` (post-rename env-var) — FOUND, contains `env('BEATRAX_DEV_MODE', false)`
- `config/nativephp.php` (post-rename env-var in cleanup_env_keys) — FOUND
- `deploy/launchd/com.beatrax.horizon.plist` — FOUND (renamed from com.diederik.horizon.plist)
- `deploy/launchd/com.beatrax.redis.plist` — FOUND (renamed)
- `deploy/launchd/com.beatrax.scheduler.plist` — FOUND (renamed)
- `tests/Contracts/BoundaryArchTest.php` (new invariants) — FOUND, contains `impersonationSurfaceRemoved` + `noDiederikLiteralAfterRename`

Files asserted deleted:

- `Modules/Auth/Public/Actions/ImpersonateUserAction.php` — DELETED
- `Modules/Auth/Public/Actions/EndImpersonationAction.php` — DELETED
- `Modules/Auth/Public/Dto/ImpersonationResult.php` — DELETED
- `Modules/Auth/Internal/Http/Middleware/ImpersonationBannerMiddleware.php` — DELETED
- `Modules/Auth/Resources/views/partials/impersonation-banner.blade.php` — DELETED
- `Modules/Auth/tests/Feature/ImpersonationBannerTest.php` — DELETED
- `Modules/Auth/tests/Feature/ImpersonationActionTest.php` — DELETED

Files asserted renamed (via git mv):

- `deploy/launchd/com.diederik.horizon.plist` -> `deploy/launchd/com.beatrax.horizon.plist` — RENAMED
- `deploy/launchd/com.diederik.redis.plist`   -> `deploy/launchd/com.beatrax.redis.plist` — RENAMED
- `deploy/launchd/com.diederik.scheduler.plist` -> `deploy/launchd/com.beatrax.scheduler.plist` — RENAMED

Commits asserted present:

- `8e8cd76` (Task 1 — composer + signatures) — FOUND
- `6bf074a` (Task 2a — env + configs + scripts + README) — FOUND
- `d4a7029` (Task 2b — Modules production) — FOUND
- `9859f21` (Task 2c — Modules tests + snapshot) — FOUND
- `4811a70` (Task 3 — impersonation deletion + arch invariants) — FOUND

## Next Phase Readiness

- The DevMode-module skeleton (16-03) can register its `DevCommandRegistry` whitelist using the post-rename `beatrax:*` names directly — no rewrite mid-phase.
- The `EnsureDeveloperMode` middleware (16-03) will read `config('app.dev_mode')` exactly as today; the only thing that changed is the source env var name (`BEATRAX_DEV_MODE`).
- The `noDiederikLiteralAfterRename` invariant is in place, so any new file landed by 16-03+ that accidentally uses the old brand will fail CI immediately.
- The `impersonationSurfaceRemoved` invariant guarantees the dropped Phase 12 surface stays gone; the `BoundaryArchTest::noAuthFacadeOrHelper` allow-list no longer carries a dead-weight entry.
- The Horizon iframe gating (16-XX, D-38) composes cleanly with the renamed env var: `config('app.dev_mode')` -> `env('BEATRAX_DEV_MODE')` is the canonical source-of-truth path.

---
*Phase: 16-developer-mode-ui*
*Completed: 2026-05-24*
