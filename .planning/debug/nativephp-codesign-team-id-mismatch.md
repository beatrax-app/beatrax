---
status: fixing
trigger: "macOS code-signing failure: NativePHP-packaged diederik.app crashes on launch with DYLD Library missing / Team ID mismatch on Electron Framework"
created: 2026-05-22T21:30:00Z
updated: 2026-05-22T22:10:00Z
---

## Current Focus

### Cycle 2 (2026-05-22T22:00:00Z) — "silent exit" is the SAME crash

reasoning_checkpoint:
  hypothesis: "The c0f4ef1 prebuild hook never applied. The hook patches $projectRoot/nativephp/electron/electron-builder.mjs, but NativePHP's ElectronServiceProvider::electronPath() falls back to the VENDOR copy (vendor/nativephp/desktop/resources/electron/) whenever nativephp/electron/package.json is absent. The project has no nativephp/electron/ directory, so the build ran against the unpatched vendor electron-builder.mjs (mac block has no `identity` key) and the hook silently exited 0 with 'no electron-builder.mjs found, skipping'. electron-builder then auto-discovered a keychain identity and produced a Team-ID-mismatched bundle. The 'silent exit with no window' the user reports is in fact the SAME dyld Team-ID abort — it aborts in dyld4::prepare before any window is created, so macOS shows no crash dialog."
  confirming_evidence:
    - "Crash reports diederik-2026-05-22-2151{42,43}.ips (launch time) — EXC_CRASH/SIGABRT, DYLD 'Library missing', 'Electron Framework ... not valid for use in process: ... different Team IDs'. codeSigningMonitor=1. Backtrace ends in dyld4::prepare → dyld4::start: crash is BEFORE the app runs."
    - "ElectronServiceProvider::electronPath() (line 21-27): returns base_path('nativephp/electron') only if that dir has package.json, ELSE returns the vendor default vendor/nativephp/desktop/resources/electron."
    - "No nativephp/electron/ directory exists in the project (`ls -d vendor/nativephp/electron` empty; `find . -name electron-builder.mjs` finds only the vendor copy)."
    - "vendor/nativephp/desktop/resources/electron/electron-builder.mjs mac block (lines 107-115) still has NO `identity` key — the build target was never patched."
    - "scripts/nativephp_force_adhoc_signing.php hard-codes \$configPath = \$projectRoot.'/nativephp/electron/electron-builder.mjs' — a path that does not exist — so it prints 'skipping' and exits 0, a silent no-op."
  falsification_test: "If the hook HAD patched the build target, the vendor electron-builder.mjs mac block would contain `identity: null`. It does not. Conversely, if electronPath() always used nativephp/electron/, the prior fix path would be correct — but the documented fallback logic and the missing directory contradict that."
  fix_rationale: "Rewrite the prebuild hook to resolve the SAME path NativePHP's build uses: prefer base_path('nativephp/electron/electron-builder.mjs') when nativephp/electron/package.json exists, otherwise patch the vendor fallback at vendor/nativephp/desktop/resources/electron/electron-builder.mjs. This guarantees the file the build actually reads gets `identity: null`, making ad-hoc signing deterministic regardless of keychain state. The Builder::preProcess() runs the hook before buildOrPublish() invokes electron-builder, so the patch lands in time."
  blind_spots: "The vendor fallback file is reinstalled by `composer install`/`update`, so the patch must remain idempotent and re-run every build (it does — it's a prebuild hook). If a future NativePHP version restructures electronPath() the hook's resolution logic must be revisited. Cannot run native:build headlessly here — user must re-verify."

### Cycle 1 (superseded — fix targeted the wrong file)

reasoning_checkpoint:
  hypothesis: "NativePHP's electron-builder.mjs has no `mac.identity` key. electron-builder's macOS signing then auto-discovers a keychain identity. When a discovered identity exists (Xcode-provisioned 'Apple Development' cert, expired Developer ID, etc.), electron-builder partially signs the bundle — the app shell ends up ad-hoc/empty-TeamID while the nested Electron Framework retains a non-empty Team ID, causing dyld to abort on Apple Silicon. With no identity in the keychain it does clean ad-hoc signing. The build is therefore non-deterministic."
  confirming_evidence:
    - "vendor/.../electron-builder.mjs `mac` block has entitlementsInherit + extendInfo but NO `identity` key — electron-builder default = auto-discover from keychain."
    - "Crash report: framework had a non-empty Team ID, main exe codeSigningTeamID empty → classic mixed/partial signing."
    - "Current keychain: `security find-identity` → 0 valid identities. Current dist/ bundle is cleanly ad-hoc (both main exe + framework TeamIdentifier=not set, `codesign --verify --deep --strict` passes)."
    - "Discrepancy between crash report and current clean bundle is explained only by transient keychain state at build time → confirms non-determinism."
  falsification_test: "If electron-builder ignored the keychain entirely, adding `identity: null` would change nothing and a build with a planted identity would still come out ad-hoc. (Documented electron-builder behavior contradicts this.)"
  fix_rationale: "Explicitly setting `mac.identity: null` forces electron-builder to ALWAYS perform consistent `--deep` ad-hoc signing regardless of keychain contents. This makes the build reproducible and guarantees no Team-ID mismatch. It does not require any paid Apple Developer ID."
  blind_spots: "Cannot reproduce the original crash without planting an identity in the keychain. Relying on documented electron-builder semantics + the matching symptom pattern. The absolute-@rpath note in the crash report is NOT present in the current build (rpath = @executable_path/../Frameworks) — that was a side effect of launching the in-place dev build, not a packaging defect; no fix needed."
test: Apply `mac.identity: null` to the NativePHP electron-builder config and confirm the build stays clean.
next_action: Patch electron-builder.mjs via NativePHP's customisation path so the change survives vendor reinstall.

## Symptoms

expected: `php artisan native:build` produces a .dmg whose installed app launches cleanly.
actual: app crashes on launch — DYLD "Library missing" for @rpath/Electron Framework.framework.
errors: |
  Termination Reason: Namespace DYLD, Code 1 Library missing
  Library not loaded: @rpath/Electron Framework.framework/Electron Framework
  code signature 'Electron Framework' not valid: mapping process and mapped file
  (non-platform) have different Team IDs
  codeSigningTeamID "" on main executable; @rpath resolved to absolute dev build dir path.
reproduction: php artisan native:build (mac arm64), install .dmg, launch app.
started: Phase 15 — first NativePHP build (nativephp/desktop 2.2.0, nativephp/php-bin 1.2.0).

## Eliminated

- hypothesis: "The c0f4ef1 fix resolved the crash; the new symptom (silent exit, no window) is a separate boot/window-lifecycle failure."
  evidence: "Crash reports from launch time (2151{42,43}.ips) show the IDENTICAL DYLD Team-ID abort. There is no separate boot failure — no Laravel log, no APP_KEY error, no DB error. The 'silent exit' is just the same SIGABRT happening pre-window so macOS shows no dialog."
  timestamp: 2026-05-22T22:05:00Z

- hypothesis: "Empty NativeAppServiceProvider::boot() (no Window::open()) is the root cause — app starts and exits with nothing to show."
  evidence: "The PHP/Laravel layer is never reached. dyld aborts the Electron process in dyld4::prepare, long before NativePHP boots Laravel or calls the provider. boot() being empty is real but not THIS bug — it would only matter once the bundle launches."
  timestamp: 2026-05-22T22:06:00Z

## Evidence

- timestamp: 2026-05-22T22:00:00Z
  checked: ~/Library/Logs/DiagnosticReports/diederik-2026-05-22-2151{42,43}.ips
  found: EXC_CRASH / SIGABRT, termination namespace DYLD, "Library missing", "Electron Framework ... code signature ... not valid for use in process: mapping process and mapped file (non-platform) have different Team IDs". codeSigningMonitor=1. Backtrace = __abort_with_payload → dyld4::halt → dyld4::prepare → dyld4::start.
  implication: The "silent exit" is the SAME Team-ID crash, aborting before any window. The c0f4ef1 fix did not take effect.

- timestamp: 2026-05-22T22:02:00Z
  checked: codesign -dvv on the installed /Applications/diederik.app main exe + Electron Framework
  found: Both currently report Signature=adhoc, TeamIdentifier=not set; `codesign --verify --deep --strict` passes. (This particular installed bundle was built with an empty keychain — but the 21:51 crash report is from a build/launch where the mismatch was present.)
  implication: Confirms the build is non-deterministic. The disk state of one lucky build is clean, but builds with a keychain identity present still crash. The fix must make EVERY build deterministic.

- timestamp: 2026-05-22T22:04:00Z
  checked: ElectronServiceProvider::electronPath() (vendor/nativephp/desktop/src/Drivers/Electron/ElectronServiceProvider.php:21-27); presence of nativephp/electron/ directory
  found: electronPath() returns base_path('nativephp/electron') ONLY if that dir contains package.json, else the vendor default vendor/nativephp/desktop/resources/electron. No nativephp/electron/ directory exists in the project.
  implication: native:build ran electron-builder against the VENDOR electron-builder.mjs, not the project-local path the prebuild hook targets.

- timestamp: 2026-05-22T22:05:00Z
  checked: scripts/nativephp_force_adhoc_signing.php $configPath; vendor/nativephp/desktop/resources/electron/electron-builder.mjs mac block
  found: The hook hard-codes $projectRoot.'/nativephp/electron/electron-builder.mjs' (nonexistent) → prints "no electron-builder.mjs found, skipping" → exit 0. The vendor electron-builder.mjs mac block (lines 107-115) still has no `identity` key.
  implication: ROOT CAUSE — the c0f4ef1 prebuild hook patches a file the build never reads, and silently no-ops. The build target stays unpatched, so electron-builder auto-discovers a keychain identity and produces the Team-ID-mismatched bundle.

- timestamp: 2026-05-22T21:35:00Z
  checked: nativephp/electron/dist/builder-effective-config.yaml + vendor/nativephp/desktop/resources/electron/electron-builder.mjs
  found: The `mac` block sets entitlementsInherit, artifactName, extendInfo — but NO `identity` key.
  implication: electron-builder uses default behavior = auto-discover a signing identity from the keychain.

- timestamp: 2026-05-22T21:36:00Z
  checked: codesign -dv on main exe and Electron Framework in current dist/mac-arm64 bundle
  found: Both report Signature=adhoc, TeamIdentifier=not set. `codesign --verify --deep --strict` → "valid on disk, satisfies its Designated Requirement". rpath = @executable_path/../Frameworks (relocatable).
  implication: The CURRENT bundle is clean. The crash report describes a STALE earlier build — confirming non-determinism.

- timestamp: 2026-05-22T21:37:00Z
  checked: security find-identity -v -p codesigning
  found: 0 valid identities found.
  implication: With an empty keychain electron-builder did clean ad-hoc signing. The crash occurred on a build where a keychain identity was present and caused partial/mismatched signing. Build outcome depends on transient keychain state.

## Resolution

root_cause: |
  Two-layer root cause.
  (1) Underlying defect: NativePHP's bundled electron-builder config omits `mac.identity`, so electron-builder auto-discovers a keychain code-signing identity at build time. When an identity is present the bundle is partially signed — the app shell stays ad-hoc (empty Team ID) while the nested Electron Framework keeps a non-empty Team ID — and on Apple Silicon dyld aborts at launch with mismatched Team IDs. This abort happens in dyld4::prepare BEFORE any window, so macOS shows no crash dialog — it looks like a "silent exit".
  (2) Why the cycle-1 fix did not work: the cycle-1 prebuild hook patched `$projectRoot/nativephp/electron/electron-builder.mjs`. But `ElectronServiceProvider::electronPath()` only uses that project-local directory when it contains `package.json`; otherwise it falls back to the package default at `vendor/nativephp/desktop/resources/electron/`. The project has no `nativephp/electron/` directory, so the build read the VENDOR electron-builder.mjs — which the hook never touched. The hook found nothing at its hard-coded path, printed "skipping", and exited 0: a silent no-op. The build target stayed unpatched and kept crashing.
fix: |
  Rewrote scripts/nativephp_force_adhoc_signing.php to resolve the electron-builder.mjs the SAME way NativePHP's build does: prefer `nativephp/electron/electron-builder.mjs` when `nativephp/electron/package.json` exists, otherwise patch the vendor fallback `vendor/nativephp/desktop/resources/electron/electron-builder.mjs`. It injects `identity: null` as the first `mac` block key, forcing deterministic `--deep` ad-hoc signing regardless of keychain contents. The hook now exits 1 (fails loudly) if no electron-builder.mjs can be found at all, instead of silently no-opping. Still wired into config/nativephp.php `prebuild`, still idempotent, still runs in Builder::preProcess() before electron-builder is invoked. No paid Apple Developer ID required.
verification: |
  - Hook run against the real vendor electron-builder.mjs: injects `identity: null` as the first `mac` key (line 108). Second run reports "identity already configured" and is a no-op (idempotent).
  - `node --check` confirms the patched electron-builder.mjs is valid JavaScript.
  - Laravel Pint: passed. Larastan level 10 (--memory-limit=512M): "No errors".
  - Could not re-run native:build headlessly — the user must re-run `php artisan native:build mac arm64`, reinstall the .dmg, and confirm the app launches a window. End-to-end verification is the human checkpoint below.
files_changed:
  - scripts/nativephp_force_adhoc_signing.php (rewritten — resolves the real build target path)
