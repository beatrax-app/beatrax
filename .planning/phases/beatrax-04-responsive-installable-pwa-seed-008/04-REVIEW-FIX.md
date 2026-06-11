---
phase: 04-responsive-installable-pwa-seed-008
fixed_at: 2026-06-11T00:00:00Z
review_path: .planning/phases/beatrax-04-responsive-installable-pwa-seed-008/04-REVIEW.md
iteration: 1
findings_in_scope: 14
fixed: 14
skipped: 0
status: all_fixed
---

# Phase 4: Code Review Fix Report

**Fixed at:** 2026-06-11
**Source review:** .planning/phases/beatrax-04-responsive-installable-pwa-seed-008/04-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 14 (4 critical, 6 warning, 4 info)
- Fixed: 14
- Skipped: 0

## Fixed Issues

### CR-01: `$appendedCursorIds` visibility changed to `public`

**Files modified:** `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php`
**Commit:** f6fc87f
**Applied fix:** Changed `protected array $appendedCursorIds = []` to `public array $appendedCursorIds = []` and updated the docblock to explain why public visibility is required for Livewire dehydration/rehydration round-trips. Added detailed comment explaining that the protected guard was silently reset to `[]` on every browser round-trip.

---

### CR-02: `{!! $macKbdJs !!}` replaced with `{{ Js::from('⌘K') }}`

**Files modified:** `Modules/Core/Resources/views/livewire/app-sidebar.blade.php`
**Commit:** ddb0124
**Applied fix:** Removed the `@php` block that manually constructed `$macKbdJs` via `json_encode` + `str_replace`. Replaced the `{!! $macKbdJs !!}` unescaped output with `{{ Js::from('⌘K') }}` double-brace (HTML-encoded) pattern identical to `bottom-sheet.blade.php`. AppSidebarKbdTest confirmed: all 3 assertions pass — raw glyph absent, `$store.platform.isMac` present, `Ctrl+K` fallback present.

---

### CR-03: Unbounded `$accumulatedRows` capped at `MAX_ACCUMULATED_ROWS = 500`

**Files modified:** `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php`
**Commit:** e64663d
**Applied fix:** Added `private const MAX_ACCUMULATED_ROWS = 500` class constant with documented rationale. In `render()`, after the append block, added a cap check: when `count($accumulatedRows) > MAX_ACCUMULATED_ROWS`, the oldest rows are trimmed via `array_slice` and `$appendedCursorIds` is reset to only the current guard key (preventing trimmed pages from being re-appended). All 4 existing `TransactionsListInfiniteScrollTest` tests pass.

---

### CR-04: `apple-touch-icon` added to `site.webmanifest`

**Files modified:** `public/site.webmanifest`, `Modules/Core/tests/Feature/PwaManifestTest.php`
**Commit:** 28b0dbd
**Applied fix:** Added `{ "src": "/icons/apple-touch-icon.png", "sizes": "180x180", "type": "image/png", "purpose": "any" }` to the manifest `icons` array. Extended `PwaManifestTest` with a new assertion that verifies the apple-touch-icon entry is present with the correct sizes and type. All existing PwaManifestTest assertions continue to pass.

---

### WR-01: `$appendedCursorIds` growth bounded by CR-03 fix

**Files modified:** `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php`
**Commit:** e64663d (same commit as CR-03)
**Applied fix:** The CR-03 cap mechanism already handles WR-01: when `$accumulatedRows` is trimmed at the 500-row boundary, `$appendedCursorIds` is simultaneously reset to only `[$guardKey => true]` — a single key, far tighter than the "keep last 20" bound suggested by WR-01. No separate commit is needed.

---

### WR-02: SW catch-all changed to static-asset allowlist

**Files modified:** `resources/views/sw.blade.php`
**Commit:** 7ad4afd
**Applied fix:** Replaced the open-ended `caches.match(event.request)` catch-all with an explicit `isStaticAsset` check (`/build/`, `/icons/`, `/offline.html`). Only those paths get cache-first treatment; all other non-navigate GETs fall through to network-only. Added comment clarifying no financial data is ever cached. All 4 `ServiceWorkerRouteTest` assertions still pass.

---

### WR-03: `loadMore` no longer accepts client-supplied cursor args

**Files modified:** `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php`, `Modules/Ledger/Resources/views/livewire/transactions-list.blade.php`, `Modules/Ledger/tests/Feature/TransactionsListInfiniteScrollTest.php`
**Commit:** 365519f
**Applied fix:** Changed `loadMore(int $nextCursorId, ?string $nextCursorPostedAt = null): void` to `loadMore(): void`. The method now reads `$this->nextCursorId` and `$this->nextCursorPostedAt` from the server-side snapshot (set by the previous `render()`). Updated both blade call sites (phone `wire:intersect` sentinel and desktop `wire:click` button) to call `loadMore` with no arguments. Updated all 4 test calls from `call('loadMore', $nextCursorId, $nextCursorPostedAt)` to `call('loadMore')`. All 4 infinite-scroll tests pass.

---

### WR-04: `deleteScenario` validates ownership before deleting

**Files modified:** `Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php`
**Commit:** c92140c
**Applied fix:** Added `ScenarioQuery $scenarioQuery` injection to `deleteScenario`. Before invoking the action, re-fetches the user's own scenarios and checks that the requested `$scenarioId` is in the result set. If not, silently resets `$confirmingDeleteForScenarioId` and returns — no error is disclosed to the caller. This closes the integer-forge path without leaking information.

---

### WR-05: Dashboard inline `<style>` moved to `app.css`

**Files modified:** `Modules/Core/Resources/views/livewire/dashboard.blade.php`, `resources/css/app.css`
**Commit:** deb91af
**Applied fix:** Removed the inline `<style>` block from `dashboard.blade.php` containing the `.dashboard-main` and `.dashboard-phone-order-*` media query rules. Moved the rules into a new "Phase 4: Per-page phone-responsive rules" section at the end of `app.css`. Updated the blade comment to note the CSS is now in `app.css`.

---

### WR-06: Settings page inline `<style>` moved to `app.css`

**Files modified:** `Modules/Core/Resources/views/livewire/settings-page.blade.php`, `resources/css/app.css`
**Commit:** deb91af (same commit as WR-05)
**Applied fix:** Removed the inline `<style>` block from `settings-page.blade.php` containing the `.settings-grid` media query rule. Moved to the same new `app.css` section as WR-05. Removed the `!important` declarations — the media query's normal specificity is sufficient when the rule lives in the stylesheet rather than fighting a Livewire-emitted inline style.

---

### IN-01: SW version string wrapped with `Js::from()`

**Files modified:** `resources/views/sw.blade.php`
**Commit:** 2894b91
**Applied fix:** Changed `'beatrax-shell-v{{ config('nativephp.version') }}'` to `'beatrax-shell-v' + {{ Js::from(config('nativephp.version')) }}`. `Js::from()` guarantees JS-safe encoding (single quotes, backticks, and `</script>`-like sequences are escaped) regardless of future version string format changes. All 4 `ServiceWorkerRouteTest` assertions pass.

---

### IN-02: `.catch()` added to SW registration promise

**Files modified:** `resources/views/layouts/app.blade.php`
**Commit:** 90ce383
**Applied fix:** Added `.catch(function () { /* SW registration failed — app continues to work without offline support. */ })` to the `navigator.serviceWorker.register('/sw.js', { scope: '/' })` call. Silently swallows registration failures so unhandled promise rejections don't surface as console errors.

---

### IN-03: `⌘.` dev-console hint made platform-aware

**Files modified:** `Modules/Core/Resources/views/livewire/app-sidebar.blade.php`, `Modules/Core/tests/Feature/AppSidebarKbdTest.php`
**Commit:** 2951902
**Applied fix:** Replaced `<span class="kbd" aria-hidden="true">⌘.</span>` with a platform-aware `x-text` binding using `{{ Js::from('⌘.') }}` (same pattern as the palette hint fix in CR-02). SSR fallback text is `Ctrl+.`. Extended `AppSidebarKbdTest` with a new assertion that the raw `⌘.` glyph is absent from the server-rendered HTML.

---

### IN-04: Install-hint dismissal persisted in `localStorage`

**Files modified:** `Modules/Core/Resources/views/components/install-hint.blade.php`
**Commit:** e00ddd7
**Applied fix:** Added `localStorage` persistence to the Alpine component. `dismiss()` now writes a timestamp to `beatrax-install-hint-dismissed`. `init()` reads the timestamp and skips showing the hint if it was dismissed within the last 30 days. Both read and write are wrapped in `try/catch` to guard against `localStorage` being unavailable (private browsing mode, storage quota exhausted).

---

## Skipped Issues

None — all 14 findings fixed.

---

_Fixed: 2026-06-11_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
