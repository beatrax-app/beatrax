---
phase: 15-desktop-shell-nativephp-integration
plan: 04
subsystem: desktop
tags: [nativephp, electron, file-associations, livewire, session, single-instance, deep-link]

# Dependency graph
requires:
  - phase: 15-01
    provides: "FileOpenedFromOs Public event skeleton + FileOpenedFromOsTest ->todo() stubs; Modules/Desktop bounded module + arch invariant"
  - phase: 15-02
    provides: "NotificationDeepLink Public event (D-14) — listener consolidated here; bundle-gated subscription pattern reused"
  - phase: 15-03
    provides: "CloseWindowPrompt + WindowCloseBehavior — the close-window-choice JS glue consolidated here"
  - phase: 15-05
    provides: "Published Electron project (nativephp/electron/) — the electron-builder.mjs + src/main/index.js edits land here"
provides:
  - "FileOpenIntake — security boundary that canonicalizes, allow-lists ({csv, eml}), size-limits, and emits FileOpenedFromOs (PKG-06)"
  - "FileOpenController — POST /desktop/file-open route behind ['web'] only (logged-out file-open must reach the intake — D-04)"
  - "FileStagingPage Livewire component — D-01 / D-02 staging page with read-and-clear of the pending intent"
  - "Modules/Desktop/Resources/views/staging.blade.php — Auth/wizard centered layout shell with dark-companion utilities"
  - "PendingFileIntent + RemembersPendingFileIntent — session-scoped pending-file-intent store + Public contract"
  - "ContinuePendingFileIntentAfterLogin — Login event subscriber that lets the staging page render bound to a file after the login round-trip (D-04)"
  - "HandleNativeOpenFile — bridge from NativePHP's \\Native\\Desktop\\Events\\App\\OpenFile to FileOpenIntake (bundle-gated)"
  - "Modules/Import/Internal/Listeners/HandleFileOpenedFromOs — .csv routes to the Import flow (SC3 routing caveat)"
  - "Modules/Receipts/Internal/Listeners/HandleFileOpenedFromOs — .eml routes to the Receipts pipeline"
  - "NavigateOnNotificationDeepLink — D-14 click listener consolidated from 15-02 (focus the window + navigate)"
  - "ApplyCloseWindowChoice + CloseActionController + in-layout JS hook — D-08 close-window JS glue consolidated from 15-03"
  - "FileAssociationSpike.md — code-adjacent design note recording the cross-OS plumbing choices"
  - "nativephp/electron/electron-builder.mjs fileAssociations block (gitignored; documented in the spike)"
  - "nativephp/electron/src/main/index.js cross-OS argv + single-instance lock + second-instance forwarding (gitignored; documented in the spike)"
affects: [16 (Developer Mode UI can consume the same focus-window-and-navigate seam), 17 (CI/CD must verify file-association registration on macOS notarised build + Windows MSI), 21 (Invite-only beta exercises the cross-OS argv paths against real installers)]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Native-event bridge pattern: a thin Internal/Listeners/Handle* class subscribes to a NativePHP-emitted event and delegates to an Internal security boundary. The Public event is what cross-module listeners depend on — no module reaches \\Native\\Desktop\\* directly."
    - "Read-and-clear at mount() time: FileStagingPage::mount() reads PendingFileIntent and clears it on the spot so refreshing within the staging step keeps the binding (via the component's \$pending property) while the next visit shows the empty state."
    - "Public contract for an Internal session-scoped store: RemembersPendingFileIntent is the cross-module seam; the concrete PendingFileIntent stays in Modules/Desktop/Internal/Native. Other modules inject the contract — never the concrete — preserving the noNativePhpImportsOutsideDesktopModule arch invariant and the Internal-namespace containment."
    - "Allow-list defence-in-depth at every emission boundary: FileOpenIntake (extension allow-list), PendingFileIntent::remember (extension allow-list before session put), CloseActionController (choice allow-list before facade call)."
    - "Bundle-gated vs always-on listener split: FileOpenedFromOs Public event listeners (Import + Receipts + Desktop continuation) subscribe ALWAYS — the pending-intent round-trip is the same in tests / Herd / bundle. NativePHP-event listeners (HandleNativeOpenFile, NavigateOnNotificationDeepLink, OS-notification dispatch) gate on nativephp-internal.running so Herd / CI never reach the NativePHP HTTP client."

key-files:
  created:
    - "Modules/Desktop/Internal/Native/FileAssociationSpike.md"
    - "Modules/Desktop/Internal/Native/FileOpenIntake.php"
    - "Modules/Desktop/Internal/Native/PendingFileIntent.php"
    - "Modules/Desktop/Internal/Http/FileOpenController.php"
    - "Modules/Desktop/Internal/Http/CloseActionController.php"
    - "Modules/Desktop/Internal/Http/Livewire/FileStagingPage.php"
    - "Modules/Desktop/Internal/Listeners/HandleNativeOpenFile.php"
    - "Modules/Desktop/Internal/Listeners/ContinuePendingFileIntentAfterLogin.php"
    - "Modules/Desktop/Internal/Listeners/NavigateOnNotificationDeepLink.php"
    - "Modules/Desktop/Internal/Listeners/ApplyCloseWindowChoice.php"
    - "Modules/Desktop/Public/Contracts/RemembersPendingFileIntent.php"
    - "Modules/Desktop/Resources/views/staging.blade.php"
    - "Modules/Import/Internal/Listeners/HandleFileOpenedFromOs.php"
    - "Modules/Receipts/Internal/Listeners/HandleFileOpenedFromOs.php"
  modified:
    - "Modules/Desktop/tests/Feature/FileOpenedFromOsTest.php (12 cases, all green; supersedes plan-15-01 ->todo() stubs)"
    - "Modules/Desktop/Providers/DesktopServiceProvider.php (PendingFileIntent + RemembersPendingFileIntent contract + 5 new listener registrations + 3 new event subscriptions, 2 bundle-gated)"
    - "Modules/Desktop/Routes/web.php (added /desktop/file-open intake route behind ['web'] and /desktop/file-staging + /desktop/close-action routes behind ['web', 'auth'])"
    - "Modules/Import/Providers/ImportServiceProvider.php (HandleFileOpenedFromOs binding + FileOpenedFromOs subscription via Dispatcher in boot())"
    - "Modules/Receipts/Providers/ReceiptsServiceProvider.php (HandleFileOpenedFromOs binding + FileOpenedFromOs subscription in boot())"
    - "resources/views/layouts/app.blade.php (D-08 close-window-choice JS hook — POSTs to desktop.close-action with the chosen action)"
    - "tests/Contracts/BoundaryArchTest.php (facade allow-list extended with NavigateOnNotificationDeepLink + ApplyCloseWindowChoice)"
    - "phpstan.neon (facade-rule regex extended with App; object::url|hide ignore covers both new listeners)"
    - "nativephp/electron/electron-builder.mjs (fileAssociations block for .csv / .eml — gitignored; documented in the spike)"
    - "nativephp/electron/src/main/index.js (cross-OS argv extractor + unconditional single-instance lock + second-instance forwarding — gitignored; documented in the spike)"

key-decisions:
  - "Reuse NativePHP's existing notifyLaravel('events', '\\\\Native\\\\Desktop\\\\Events\\\\App\\\\OpenFile') transport for ALL THREE open-file paths (macOS open-file, Windows/Linux argv, second-instance). One JS-side extractor, one PHP-side native-event subscriber (HandleNativeOpenFile). No new transport invented."
  - "FileOpenedFromOs Public event listeners are NOT bundle-gated. The pending-intent round-trip must work in tests / Herd / bundle uniformly — the listeners only touch the Session contract and the cross-module Public contract; no NativePHP facade. The native-event bridge (HandleNativeOpenFile) IS bundle-gated because the upstream NativePHP event only fires inside the bundle."
  - "Both .csv and .eml staging pages share ONE Livewire component (FileStagingPage). The extension-flavoured body copy differs but the layout, CTA, and consume-once semantics are identical — duplicating the component would just clone code. The per-extension routing decision lives on the FileOpenedFromOs listeners in Import + Receipts."
  - "FileStagingPage uses read-and-clear at mount() rather than read-at-action. Visiting the page consumes the intent so a future dashboard navigation doesn't re-render the staging page bound to an old file. Within the staging step the choice persists on the component's \$pending property — refreshing the staging page never loses the file binding."
  - "PendingFileIntent::pending() does its own stale-path realpath() check on every read. A flash drive unmounted between double-click and login degrades silently to the empty state — no exception, no error page."
  - "The Window::current()->url(...) path-navigation API and App::quit() / Window::current()->hide() are the canonical NativePHP shapes with no constructor-injection seam. Two more listeners (NavigateOnNotificationDeepLink, ApplyCloseWindowChoice) join the facade allow-list pattern established in 15-01 / 15-02 / 15-03 rather than inventing a contract abstraction."
  - "Full Electron-side closable(false) interception (preventing the OS-level close before the prompt fires) is NOT shipped here. The CloseActionController + JS hook handle the post-choice apply step; the pre-prompt intercept needs main.js-side closable + before-quit JS that ships with the bundle's electron-plugin. Deferred to Phase 21 beta (the only place we exercise a real bundle close)."

patterns-established:
  - "Native-event bridge: a thin listener (HandleNativeOpenFile) translates a NativePHP event (\\Native\\Desktop\\Events\\App\\OpenFile) into a Public domain event (FileOpenedFromOs) — never letting other modules import NativePHP types. The bridge stays inside Modules/Desktop/Internal."
  - "Public contract for a session-scoped Internal store: RemembersPendingFileIntent shows how cross-module writes to a session-scoped Desktop-internal store stay decoupled from the concrete implementation. Future patterns wanting to record cross-module session state (e.g. a pending-update-installer intent) can mirror this seam."
  - "Browser-event → JS hook → POST route → facade-bearing controller: the close-window choice path establishes the canonical 'Livewire dispatches a browser event, a layout-level JS hook POSTs to a Desktop endpoint, the endpoint calls into the bundle-facade-bearing listener' pipeline. Future Desktop-shell actions originating in a Livewire component can adopt the same shape."

requirements-completed: [PKG-06]

# Metrics
duration: 24min
completed: 2026-05-23
---

# Phase 15 Plan 04: OS File Associations + Single-Instance Focus + Pending Intent Survives Login Summary

**Cross-OS file-association handlers (.csv / .eml double-click → diederik staging page), pending-intent round-trip across the login boundary (D-04), single-instance focus equivalence (D-03), and consolidation of the two deferred "focus the window and navigate" seams from plans 15-02 (NotificationDeepLink click) and 15-03 (close-window-choice JS glue).**

## Performance

- **Duration:** ~24 min (2026-05-23T14:05:15Z → 2026-05-23T14:29:07Z)
- **Started:** 2026-05-23T14:05:15Z
- **Completed:** 2026-05-23T14:29:07Z
- **Tasks:** 4 (1 spike, 3 TDD)
- **Files created:** 14 (13 production, 1 spike doc)
- **Files modified:** 9 (5 PHP, 1 view, 2 arch + neon, 1 layout JS hook) plus 2 gitignored Electron files
- **Commits:** 7 (docs × 1, test × 1, feat × 4, style × 1)

## Accomplishments

- **PKG-06 cross-OS file-association settled.** The published Electron project's `electron-builder.mjs` now declares a `fileAssociations` block for `.csv` and `.eml` (macOS `CFBundleDocumentTypes` + Windows registry + Linux `.desktop` MIME). The `main/index.js` unconditionally acquires the single-instance lock and adds a cross-OS argv extractor that POSTs Windows/Linux cold-start + `second-instance` paths to NativePHP's existing `notifyLaravel('events', '\\Native\\Desktop\\Events\\App\\OpenFile')` transport — so the PHP-side subscriber is OS-agnostic. The macOS `open-file` path uses NativePHP's existing plugin wiring unchanged.
- **D-01 / D-02 staging page** — `FileStagingPage` Livewire component renders the verbatim UI-SPEC "File received: `<name>`" heading + a single emerald "Start import" button when a pending intent is set, and the "We couldn't open that file" empty state otherwise. The same component serves both extensions; the per-extension routing decision lives on the upstream listeners in `Modules/Import` (`.csv`) and `Modules/Receipts` (`.eml`).
- **D-03 single-instance focus** — `FileOpenIntake` treats a cold-start input identically to a `second-instance` input; one validation boundary, one emission shape. The actual window focus + navigate is owned by the published Electron's `main/index.js` (focuses the existing window before forwarding the path).
- **D-04 pending intent survives login** — `PendingFileIntent` stores a validated path + extension on the user's session (SESSION_DRIVER=database). `ContinuePendingFileIntentAfterLogin` subscribes to Laravel's `Login` event; when the user authenticates the session-scoped intent is still there for the next `/desktop/file-staging` request to render. A cross-user test asserts user B's login never inherits user A's pending intent (T-15-10 mitigation). A stale-path entry (the underlying file was deleted between double-click and login) is silently discarded.
- **D-14 NotificationDeepLink click listener consolidated** (deferred from 15-02). `NavigateOnNotificationDeepLink` subscribes to `NotificationDeepLink` and navigates the focused window via `Window::current()->url($screenRoute)`. Bundle-gated subscription.
- **D-08 close-window-choice JS glue consolidated** (deferred from 15-03). The in-layout JS hook listens for the Livewire-dispatched `close-window-choice` browser event and POSTs the choice to `/desktop/close-action`; the controller re-validates against the `{quit, tray}` allow-list before calling `App::quit()` / `Window::current()->hide()` inside `ApplyCloseWindowChoice`.
- **Security boundary held** — `FileOpenIntake` canonicalises (`realpath()`), allow-lists (`csv` / `eml`), rejects directories + non-existent paths + traversals that escape bounds, size-limits to 50 MB, and never `exec()`'s the path. The intake route sits behind `['web']` only (not `auth`) so a logged-out file-open still reaches it.
- **Containment held** — `noNativePhpImportsOutsideDesktopModule` arch invariant green. Every `Native\Desktop\*` import stays inside `Modules/Desktop/`; cross-module listeners depend only on the Public `FileOpenedFromOs` event + the Public `RemembersPendingFileIntent` contract. Two new facade-bearing listeners (`NavigateOnNotificationDeepLink`, `ApplyCloseWindowChoice`) joined the existing carve-out lists in `BoundaryArchTest` + `phpstan.neon`.
- **Quality gates** — Larastan level 10 strict: 0 errors. Pest: 2148 passed, 7 todos, 6 skipped — 0 failures (was 2136 at 15-03 close; +12 = the new `FileOpenedFromOsTest` cases).

## Task Commits

Each task was committed atomically (RED before GREEN where TDD applied):

1. **Task 1 (auto): cross-OS file-association spike + Electron wiring** — `0dfee05` (docs)
2. **Task 2 RED: failing FileOpenedFromOs tests (12 cases)** — `048e620` (test)
3. **Task 2 GREEN: FileOpenIntake + FileOpenController + intake route** — `e2943d1` (feat)
4. **Task 3 GREEN: staging page + Import / Receipts routing + native-event bridge** — `f4d184e` (feat)
5. **Task 4 GREEN: pending intent + login continuation + single-instance equivalence** — `2b09e97` (feat)
6. **Pint formatting cleanup on plan-15-04 surface** — `c68e327` (style)
7. **Deferred-items consolidation: NotificationDeepLink click + close-window JS glue** — `e0f92eb` (feat)

## Files Created/Modified

### Created (production)

- `Modules/Desktop/Internal/Native/FileAssociationSpike.md` — code-adjacent design note recording per-OS behavior + chosen path-forwarding mechanism
- `Modules/Desktop/Internal/Native/FileOpenIntake.php` — security boundary (canonicalise / allow-list / size-limit / emit)
- `Modules/Desktop/Internal/Native/PendingFileIntent.php` — session-scoped pending-intent store + stale-path realpath check
- `Modules/Desktop/Internal/Http/FileOpenController.php` — POST /desktop/file-open intake controller (behind ['web'])
- `Modules/Desktop/Internal/Http/CloseActionController.php` — POST /desktop/close-action endpoint with {quit, tray} allow-list (consolidated from 15-03)
- `Modules/Desktop/Internal/Http/Livewire/FileStagingPage.php` — D-01 / D-02 staging page Livewire component (read-and-clear at mount)
- `Modules/Desktop/Internal/Listeners/HandleNativeOpenFile.php` — bridge from NativePHP's OpenFile event to FileOpenIntake
- `Modules/Desktop/Internal/Listeners/ContinuePendingFileIntentAfterLogin.php` — Login event subscriber (D-04)
- `Modules/Desktop/Internal/Listeners/NavigateOnNotificationDeepLink.php` — NotificationDeepLink click listener (consolidated from 15-02)
- `Modules/Desktop/Internal/Listeners/ApplyCloseWindowChoice.php` — applies the user's close choice via App::quit() / Window::current()->hide() (consolidated from 15-03)
- `Modules/Desktop/Public/Contracts/RemembersPendingFileIntent.php` — cross-module Public seam for the pending-intent store
- `Modules/Desktop/Resources/views/staging.blade.php` — D-01 / D-02 staging-page view with dark companions
- `Modules/Import/Internal/Listeners/HandleFileOpenedFromOs.php` — .csv FileOpenedFromOs → Desktop staging (SC3 caveat)
- `Modules/Receipts/Internal/Listeners/HandleFileOpenedFromOs.php` — .eml FileOpenedFromOs → Desktop staging

### Modified

- `Modules/Desktop/tests/Feature/FileOpenedFromOsTest.php` — 12 cases (3 intake-emit, 2 intake-reject, 3 staging routing/empty-state, 3 pending-intent, 1 single-instance) replacing the 4 `->todo()` stubs from 15-01
- `Modules/Desktop/Providers/DesktopServiceProvider.php` — bindings + subscriptions for the 5 new listeners + the PendingFileIntent + RemembersPendingFileIntent contract; Login + OpenFile + NotificationDeepLink event subscriptions
- `Modules/Desktop/Routes/web.php` — /desktop/file-open behind ['web']; /desktop/file-staging + /desktop/close-action behind ['web', 'auth']
- `Modules/Import/Providers/ImportServiceProvider.php` — HandleFileOpenedFromOs binding + Dispatcher-driven subscription in boot()
- `Modules/Receipts/Providers/ReceiptsServiceProvider.php` — same shape
- `resources/views/layouts/app.blade.php` — close-window-choice browser-event listener + fetch() POST to /desktop/close-action
- `tests/Contracts/BoundaryArchTest.php` — facade allow-list extended with the two new facade-bearing listeners
- `phpstan.neon` — facade-rule regex extended with `App`; the `object::url|hide` ignore covers both new listeners
- `nativephp/electron/electron-builder.mjs` — fileAssociations block (gitignored)
- `nativephp/electron/src/main/index.js` — cross-OS argv extractor + single-instance lock + second-instance forwarding (gitignored)

## Decisions Made

- **One staging Livewire component for both extensions.** The page is identical (heading + CTA); only the body copy line differs. Duplicating into `.csv` and `.eml` variants would clone code without buying anything. The per-extension routing decision lives on the upstream listeners.
- **Read-and-clear at mount().** Choosing read-at-action would leave the intent in the session after a dashboard navigation, causing a future visit to render a staging page bound to a long-gone file. Read-at-mount with the value captured on a public property guarantees the staging step works even after a refresh.
- **PendingFileIntent stays Internal — RemembersPendingFileIntent is the Public seam.** Other modules inject the contract; only the Desktop module touches the concrete class. This keeps the Internal-namespace containment intact (`Modules\\Desktop\\Internal` is only used inside `Modules\\Desktop`).
- **FileOpenedFromOs subscribers are NOT bundle-gated.** Unlike the OS notifications (which trigger an HTTP POST to the NativePHP shell), the Public event consumers in Import + Receipts + Desktop only touch the Session contract and the cross-module Public contract. Bundle-gating them would break the cross-OS feature tests that drive the event directly.
- **HandleNativeOpenFile (the native-event subscriber) IS bundle-gated.** The `\Native\Desktop\Events\App\OpenFile` event only fires inside the bundle — gating its subscription prevents the listener from being a no-op subscriber that confuses the bundle vs Herd reasoning.
- **Two new listeners join the facade carve-out** (`NavigateOnNotificationDeepLink`, `ApplyCloseWindowChoice`) rather than inventing a new contract abstraction. `Window::current()` and `App` are canonical NativePHP shapes — the carve-out idiom already covers this exact case.
- **Full Electron-side closable(false) intercept deferred to Phase 21.** The post-choice apply step ships here; the pre-prompt intercept (so the OS close button doesn't immediately quit before the prompt fires) needs main.js close-event handlers we can only validate against a real bundle close. Deferred to the beta cycle.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Larastan cast.useless on a string already typed as string**
- **Found during:** Task 2 (post-implementation Larastan run)
- **Issue:** `strtolower((string) pathinfo(...))` triggered `cast.useless` — `pathinfo()` already returns `string` for `PATHINFO_EXTENSION`.
- **Fix:** Dropped the `(string)` cast.
- **Files modified:** `Modules/Desktop/Internal/Native/FileOpenIntake.php`
- **Verification:** Larastan `--memory-limit=1G` exits 0.
- **Committed in:** `e2943d1` (Task 2 GREEN commit).

**2. [Rule 2 — Missing Critical] FileStagingPage was reading the intent at action time, leaving it in the session**
- **Found during:** Task 4 (the "pending intent survives login" test failed asserting the intent was consumed)
- **Issue:** Initial implementation read PendingFileIntent only inside `startImport()`. A user who navigated away from the staging page (e.g. closed the window then reopened the browser) would see the staging page re-render bound to a stale intent.
- **Fix:** Moved the read to `mount()` and captured it on a public `$pending` property. The intent is cleared at mount time, so the next visit shows the empty state.
- **Files modified:** `Modules/Desktop/Internal/Http/Livewire/FileStagingPage.php`
- **Verification:** all 12 FileOpenedFromOsTest cases green; the consume-once semantics test (`intent is null after staging visit`) passes.
- **Committed in:** `f4d184e` (Task 3 GREEN commit).

**3. [Rule 2 — Missing Critical] Renamed three plan-15-02-deferred deliverables that the brief asked this plan to consolidate**
- **Found during:** Post-Task-3 inventory of stated context items in the plan brief.
- **Issue:** Plan brief explicitly mentioned 15-02's `NotificationDeepLink` click listener + 15-03's close-intercept JS glue as deliverables to land here. The Task 1-4 surface didn't include either.
- **Fix:** Added `NavigateOnNotificationDeepLink` listener + `ApplyCloseWindowChoice` + `CloseActionController` + the in-layout JS hook. Full Electron-side intercept (preventing the OS-level close pre-prompt) carried forward as a documented stub for Phase 21 beta.
- **Files modified:** `Modules/Desktop/Internal/Listeners/{NavigateOnNotificationDeepLink, ApplyCloseWindowChoice}.php`, `Modules/Desktop/Internal/Http/CloseActionController.php`, `Modules/Desktop/Routes/web.php`, `resources/views/layouts/app.blade.php`, `Modules/Desktop/Providers/DesktopServiceProvider.php`, `tests/Contracts/BoundaryArchTest.php`, `phpstan.neon`.
- **Verification:** Larastan + Pest still green.
- **Committed in:** `e0f92eb`.

---

**Total deviations:** 3 auto-fixed (1 Rule 1 bug, 2 Rule 2 critical)
**Impact on plan:** All auto-fixes essential for correctness / brief-fidelity. No scope creep — every delivery maps to a stated plan or upstream-deferred deliverable.

## Issues Encountered

- **Initial `Modules/Desktop/Routes/web.php` registration failure** — adding the `FileStagingPage` route reference before the class existed caused a route-resolve error at test boot. Resolved by deferring the staging-route addition to the same commit that introduces the Livewire component (Task 3 GREEN), not as part of Task 2.
- **`Window::current()` typed as `object` by the NativePHP facade docblock** — `Larastan` flagged `Cannot call method url|hide on object::`. Resolved by extending the existing `Window::open()->...` chain `method.nonObject` ignore pattern with a peer entry for `object::url|hide` on the two new facade-bearing listeners; the carve-out idiom from 15-02 / 15-03 covers this exact case.

## User Setup Required

None — file association registration is OS-installer-driven and lands automatically when the user installs the bundle produced by `native:build`. The intake works under Herd / dev too (the feature tests cover the FileOpenIntake → FileStagingPage path end-to-end); the only thing dev mode doesn't exercise is the OS-level "Open With" surface.

## Known Stubs

- **Full Electron-side close-window intercept (`closable(false)` + `before-quit` JS) is NOT shipped.** The PHP-side apply step (CloseActionController + ApplyCloseWindowChoice) handles the post-choice action; the pre-prompt intercept (so the OS close button doesn't immediately quit before the Livewire prompt fires) requires main.js JS that ships with the bundle's electron-plugin and is only validatable against a real bundle close. Carried forward to Phase 21 beta as a HUMAN-UAT item. The persistence + Livewire prompt are fully functional today — what's deferred is the pre-prompt intercept JS.
- **Real cross-OS smoke on Windows / Linux argv paths.** The Electron-side `extractSupportedFilePath` + `second-instance` handler mirrors documented Electron behavior; the macOS path uses NativePHP's pre-existing plugin wiring. No Windows / Linux dev box was exercised this plan. Carried forward to Phase 17 (CI matrix) and Phase 21 (beta installs).

## Threat Flags

No new security-relevant surface introduced beyond the threat model in the plan brief.

## Next Phase Readiness

- **Phase 16 (Developer Mode UI):** ready. The focus-window-and-navigate seam (`Window::current()->url(...)`) is now well-trodden — Dev Mode UI surfaces (queue inspector toasts, alert deep-links) can reuse the `NotificationDeepLink` shape and the in-layout browser-event → POST → facade-bearing listener pipeline.
- **Phase 17 (CI/CD + code signing):** ready. The `electron-builder.mjs` `fileAssociations` block lands in the bundle's electron-builder config; the macOS notarisation entitlements + CI Windows / Linux smoke tests verify it.
- **Phase 21 (Invite-only beta):** the live HUMAN-UAT for cross-OS argv paths + the full close-intercept JS shape sits here. Both items documented as Known Stubs above.

## Self-Check: PASSED

**Files created (verified):**
- `Modules/Desktop/Internal/Native/FileAssociationSpike.md` — FOUND
- `Modules/Desktop/Internal/Native/FileOpenIntake.php` — FOUND
- `Modules/Desktop/Internal/Native/PendingFileIntent.php` — FOUND
- `Modules/Desktop/Internal/Http/FileOpenController.php` — FOUND
- `Modules/Desktop/Internal/Http/CloseActionController.php` — FOUND
- `Modules/Desktop/Internal/Http/Livewire/FileStagingPage.php` — FOUND
- `Modules/Desktop/Internal/Listeners/HandleNativeOpenFile.php` — FOUND
- `Modules/Desktop/Internal/Listeners/ContinuePendingFileIntentAfterLogin.php` — FOUND
- `Modules/Desktop/Internal/Listeners/NavigateOnNotificationDeepLink.php` — FOUND
- `Modules/Desktop/Internal/Listeners/ApplyCloseWindowChoice.php` — FOUND
- `Modules/Desktop/Public/Contracts/RemembersPendingFileIntent.php` — FOUND
- `Modules/Desktop/Resources/views/staging.blade.php` — FOUND
- `Modules/Import/Internal/Listeners/HandleFileOpenedFromOs.php` — FOUND
- `Modules/Receipts/Internal/Listeners/HandleFileOpenedFromOs.php` — FOUND

**Commits (verified):**
- `0dfee05` — FOUND (Task 1 spike doc)
- `048e620` — FOUND (Task 2 RED)
- `e2943d1` — FOUND (Task 2 GREEN)
- `f4d184e` — FOUND (Task 3 GREEN)
- `2b09e97` — FOUND (Task 4 GREEN)
- `c68e327` — FOUND (Pint cleanup)
- `e0f92eb` — FOUND (deferred items consolidation)

**Quality gates:**
- `composer analyse` — 0 errors
- `./vendor/bin/pest` — 2148 passed, 7 todos, 6 skipped, 0 failures
- `./vendor/bin/pest --filter="FileOpenedFromOs"` — 12 passed
- `./vendor/bin/pest --filter="FileStagingPage"` — 3 passed
- `./vendor/bin/pest --filter="pending intent survives login"` — 1 passed
- `./vendor/bin/pest --filter="single instance file open"` — 1 passed
- `./vendor/bin/pest --filter="noNativePhpImportsOutsideDesktopModule"` — 1 passed
- `vendor/bin/pint --test` — passed

---
*Phase: 15-desktop-shell-nativephp-integration*
*Completed: 2026-05-23*
