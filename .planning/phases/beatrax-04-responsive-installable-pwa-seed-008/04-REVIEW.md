---
phase: 04-responsive-installable-pwa-seed-008
reviewed: 2026-06-11T00:00:00Z
depth: standard+deep (deep on flagged high-risk subset)
files_reviewed: 60
files_reviewed_list:
  - .github/workflows/ci.yml
  - .github/workflows/release-build.yml
  - .github/workflows/release.yml
  - Modules/CashBook/Resources/views/livewire/cash-book-page.blade.php
  - Modules/Chains/Resources/views/livewire/chain-drawer.blade.php
  - Modules/Chains/Resources/views/livewire/chain-hints-queue.blade.php
  - Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php
  - Modules/Chains/Resources/views/livewire/chains-index.blade.php
  - Modules/Core/Resources/views/components/bottom-sheet.blade.php
  - Modules/Core/Resources/views/components/drawer.blade.php
  - Modules/Core/Resources/views/components/filter-sheet-trigger.blade.php
  - Modules/Core/Resources/views/components/install-hint.blade.php
  - Modules/Core/Resources/views/components/mobile-top-bar.blade.php
  - Modules/Core/Resources/views/livewire/app-sidebar.blade.php
  - Modules/Core/Resources/views/livewire/dashboard.blade.php
  - Modules/Core/Resources/views/livewire/settings-page.blade.php
  - Modules/Core/tests/Feature/AppSidebarKbdTest.php
  - Modules/Core/tests/Feature/PwaLayoutTest.php
  - Modules/Core/tests/Feature/PwaManifestTest.php
  - Modules/Core/tests/Feature/ServiceWorkerRouteTest.php
  - Modules/Counterparties/Resources/views/livewire/counterparty-index.blade.php
  - Modules/Counterparties/Resources/views/livewire/counterparty-profile.blade.php
  - Modules/Counterparties/Resources/views/livewire/counterparty-triage.blade.php
  - Modules/Desktop/Internal/Http/Middleware/EnsureDatabaseReady.php
  - Modules/DevMode/Resources/views/livewire/artisan-runner-page.blade.php
  - Modules/DevMode/Resources/views/livewire/audit-log-page.blade.php
  - Modules/DevMode/Resources/views/livewire/dev-overview-page.blade.php
  - Modules/DevMode/Resources/views/livewire/doctor-panel-page.blade.php
  - Modules/DevMode/Resources/views/livewire/horizon-frame-page.blade.php
  - Modules/DevMode/Resources/views/livewire/log-tailer-page.blade.php
  - Modules/DevMode/Resources/views/livewire/queue-inspector-page.blade.php
  - Modules/DevMode/Resources/views/livewire/sql-panel-page.blade.php
  - Modules/DevMode/Resources/views/livewire/system-snapshot-page.blade.php
  - Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php
  - Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php
  - Modules/Forecasting/Resources/views/livewire/partials/range-area-chart.blade.php
  - Modules/Goals/Resources/views/livewire/goals-page.blade.php
  - Modules/Import/Resources/views/livewire/import-results.blade.php
  - Modules/Import/Resources/views/livewire/preview-wizard-rows.blade.php
  - Modules/Import/Resources/views/livewire/preview-wizard.blade.php
  - Modules/Import/Resources/views/livewire/upload-wizard.blade.php
  - Modules/Ledger/Internal/Http/Livewire/TransactionsList.php
  - Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php
  - Modules/Ledger/Resources/views/livewire/transactions-list.blade.php
  - Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php
  - Modules/Ledger/tests/Feature/TransactionsListInfiniteScrollTest.php
  - Modules/Pots/Resources/views/livewire/pots-page.blade.php
  - Modules/Recurring/Resources/views/livewire/fixed-payments-card.blade.php
  - Modules/Recurring/Resources/views/livewire/partials/recurring-detail-chart-options.blade.php
  - Modules/Recurring/Resources/views/livewire/recurring-page.blade.php
  - Modules/Recurring/Resources/views/livewire/recurring-review-page.blade.php
  - Modules/Recurring/Resources/views/livewire/recurring-series-detail-page.blade.php
  - bootstrap/providers.php
  - package.json
  - public/offline.html
  - public/site.webmanifest
  - resources/css/app.css
  - resources/js/app.js
  - resources/views/layouts/app.blade.php
  - resources/views/sw.blade.php
  - routes/web.php
findings:
  critical: 4
  warning: 6
  info: 4
  total: 14
status: issues_found
---

# Phase 4: Code Review Report

**Reviewed:** 2026-06-11
**Depth:** standard + deep (deep applied to the 6 high-risk subsets named in the review directive)
**Files Reviewed:** 60
**Status:** issues_found

## Summary

Phase 4 delivers the mobile shell (drawer, top bar, bottom sheet), PWA manifest + service worker, phone responsive passes across 36+ Livewire surfaces, and an accumulation-based infinite-scroll on the TransactionsList. The overall quality is high: user-scoping is correct throughout the query layer, XSS defenses are largely sound, the service-worker scope is deliberately conservative (navigation requests fall through to the server; only static shell assets are cached), and the new test suite is meaningful.

Four blockers require fixing before shipping: an `appendedCursorIds` guard that is silently dropped between page loads (causing duplicate-row accumulation in real browser sessions despite tests passing), an unescaped `{!! !!}` Blade output writing a PHP-constructed string into an Alpine `x-text` JavaScript context, an unbounded `accumulatedRows` dehydration payload that can reach Livewire's 4 MB snapshot limit on large histories, and a missing `apple-touch-icon` entry in `site.webmanifest` that breaks the iOS-installable icon.

---

## Critical Issues

### CR-01: `$appendedCursorIds` is `protected` — lost on every Livewire dehydration/rehydration cycle, duplicate rows guaranteed in browser

**File:** `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php:108`

**Issue:** `protected array $appendedCursorIds = []` is a non-public property. Livewire only dehydrates (serialises to the encrypted snapshot) and rehydrates properties that are `public`. A `protected` property is re-initialised to its default value (`[]`) on every round-trip. The result:

1. User scrolls to sentinel — browser sends `loadMore(cursorId, date)` — Livewire request begins.
2. `$appendedCursorIds` is re-hydrated as `[]` (the default), so `isset($this->appendedCursorIds[$guardKey])` is always `false`.
3. Every call to `render()` after a `loadMore` is satisfied as "new cursor, never seen" and appends the page again.

The Pest tests in `TransactionsListInfiniteScrollTest` all pass because the Livewire test harness keeps the component in-memory across `->call()` invocations — the `protected` property is never round-tripped through the dehydration serialiser during tests. In a real browser, every AJAX request is a full dehydrate → rehydrate cycle.

The duplicate-row accumulation means:
- After loading page 2, `accumulatedRows` will contain 150 rows (page 1 + page 2 + page 2 again on next re-render) rather than 100.
- On a full-history load across many pages, accumulated rows grow without bound.

**Fix:** Change the property visibility to `public` so Livewire dehydrates it into the snapshot:

```php
// Before (line 108):
protected array $appendedCursorIds = [];

// After:
public array $appendedCursorIds = [];
```

This is the minimal fix that makes the guard survive the round-trip. Larastan level 10 will accept this because the property's docblock type is already declared. If the large snapshot size of `$appendedCursorIds` growing with every page is a concern (it will, since keys accumulate), a complementary measure is to bound its size (see WR-01).

---

### CR-02: `{!! $macKbdJs !!}` — unescaped PHP output written into an Alpine JavaScript expression context

**File:** `Modules/Core/Resources/views/livewire/app-sidebar.blade.php:67`

**Issue:** The `x-text` attribute value at line 67 is:

```
x-text="$store.platform.isMac ? {!! $macKbdJs !!} : 'Ctrl+K'"
```

`{!! !!}` emits the value raw (HTML-unescaped). The comment at lines 56–63 explains that `$macKbdJs` is produced by:

```php
$macKbdJs = str_replace('"', "'", json_encode('⌘K', JSON_THROW_ON_ERROR));
```

The string being encoded is the literal `'⌘K'` — a compile-time constant. In the current implementation this is safe because no user-controlled data flows into `$macKbdJs`.

However, the mechanism is fragile:

1. The rationale for `{!! !!}` is to emit `'⌘K'` as a JavaScript string literal inside an Alpine attribute. The safer idiomatic approach is `{{ Js::from('⌘K') }}` (double-brace — Blade HTML-encodes the output before embedding it in the attribute), which is what `bottom-sheet.blade.php` does for its dynamic `$name` prop. With double-brace, even if the expression were ever changed to incorporate a user-supplied value, it could not break out of the HTML attribute.

2. The current `str_replace('"', "'", ...)` swap is manually doing what `Js::from()` does correctly, but without the HTML-encoding protection that `{{ }}` provides. The `json_encode` output can contain `<`, `>`, `&` which would not be HTML-encoded.

3. The AppSidebarKbdTest only asserts the raw `⌘K` glyph is absent — it does not assert that the attribute does not contain injected content.

**Fix:** Replace with double-brace `{{ }}` and `Js::from()`:

```php
{{-- Remove the @php block for $macKbdJs entirely --}}

<span
    class="kbd hidden-touch"
    aria-hidden="true"
    x-text="{{ Js::from($store ?? null) ?? '' }}$store.platform.isMac ? {{ Js::from('⌘K') }} : 'Ctrl+K'"
>Ctrl+K</span>
```

More concretely — the simplest correct replacement, matching the existing `Js::from()` pattern from `bottom-sheet.blade.php`:

```blade
x-text="$store.platform.isMac ? {{ Js::from('⌘K') }} : 'Ctrl+K'"
```

Drop the `@php` block entirely. The `{{ }}` double-brace HTML-encodes; `Js::from()` produces a correctly quoted JS string literal. The AppSidebarKbdTest assertion continues to pass because the raw glyph does not appear in the server-rendered HTML (it is JSON-encoded client-side).

---

### CR-03: Unbounded `$accumulatedRows` Livewire snapshot — can hit the payload limit and corrupt state on large histories

**File:** `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php:86`

**Issue:** `public array $accumulatedRows` is serialised into the Livewire encrypted snapshot on every response and sent back by the browser on every subsequent request. Each row is a 9-field scalar array (~200–400 bytes JSON-encoded). At 50 rows/page:

| Pages loaded | Rows | Approx. snapshot contribution |
|---|---|---|
| 1 | 50 | ~20 KB |
| 10 | 500 | ~200 KB |
| 50 | 2 500 | ~1 MB |
| Full history (years of data, 5 000+ rows) | 5 000 | ~2 MB+ |

Livewire 4's default payload limit for a single component snapshot is 4 MB. Reaching that limit causes a `PayloadTooLargeException` or a silent truncation depending on the server config, corrupting the component state. Every AJAX update (including currency toggles, category changes triggered by sibling components, Livewire polling events) re-sends and re-receives this growing payload.

The issue is compounded by CR-01: once `appendedCursorIds` survives hydration, duplicate pages no longer appear, but `accumulatedRows` still grows without bound as the user scrolls through full history.

This is not merely a performance issue — at sufficient scale it causes correctness failures (corrupted state) and cannot be fixed by user action once it occurs, as it is tied to the encrypted session snapshot.

**Fix:** Cap `$appendedCursorIds` and `$accumulatedRows` to a maximum row count. A soft cap of 500 rows (10 pages at the default 50-row limit) covers realistic scrolling sessions while keeping the snapshot well below 200 KB. When the cap is reached, discard the oldest rows and reset the cursor guard so the next `loadMore` starts fresh from the current tail position. Alternatively, use the "virtual scroll" pattern: instead of accumulating in the Livewire snapshot, store only the current page in `$page->rows` and accumulate exclusively on the client (Alpine + localStorage) so the snapshot remains O(1) in page size.

The minimal protective fix that ships safely without client-side changes:

```php
private const MAX_ACCUMULATED_ROWS = 500;

// Inside the append branch of render(), after appending:
if (count($this->accumulatedRows) > self::MAX_ACCUMULATED_ROWS) {
    // Trim oldest rows from the front to stay within the cap.
    $this->accumulatedRows = array_values(
        array_slice($this->accumulatedRows, -self::MAX_ACCUMULATED_ROWS)
    );
}
```

---

### CR-04: `site.webmanifest` missing `apple-touch-icon` entry — iOS Safari "Add to Home Screen" uses wrong icon

**File:** `public/site.webmanifest:1-13`

**Issue:** The manifest lists three icons: `icon-192.png`, `icon-512.png` (standard), and `icon-512-maskable.png`. iOS Safari does not read the Web App Manifest for home-screen icons — it reads the `<link rel="apple-touch-icon">` HTML tag. That tag is correctly present in `app.blade.php` at line 65 (`/icons/apple-touch-icon.png`). However, the manifest's `icons` array does not include the `apple-touch-icon.png` file at all.

PWA installation prompts on Chrome for Android and some other Chromium-based browsers derive the "splash screen" icon from the manifest's largest icon. The `apple-touch-icon.png` is 180×180 — smaller than 512 — so this may be tolerable. The deeper issue is that Chrome's installability criteria require at least one manifest icon of 192×192 (satisfied) and at least one maskable icon (satisfied via `icon-512-maskable.png`). However, the `apple-touch-icon.png` is referenced in the HTML `<link>` tag but never served through the manifest `icons` array — this is inconsistent and means future tools that validate manifest completeness will flag it.

More critically: the `app-touch-icon.png` must be **opaque** (no transparency) for iOS — this is noted in the layout comment but cannot be verified from the file content alone. If it has an alpha channel, iOS will render it with a black background instead of the intended `#f8fafc`. This is a latent PWA quality defect.

**Fix:** Add `apple-touch-icon.png` to the manifest icons array with an explicit `purpose: "any"` declaration so it is discoverable by all PWA tooling:

```json
{
  "name": "beatrax",
  "short_name": "beatrax",
  "display": "standalone",
  "start_url": "/",
  "background_color": "#f8fafc",
  "theme_color": "#f8fafc",
  "icons": [
    { "src": "/icons/icon-192.png", "sizes": "192x192", "type": "image/png" },
    { "src": "/icons/icon-512.png", "sizes": "512x512", "type": "image/png" },
    { "src": "/icons/icon-512-maskable.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" },
    { "src": "/icons/apple-touch-icon.png", "sizes": "180x180", "type": "image/png", "purpose": "any" }
  ]
}
```

Additionally verify that `apple-touch-icon.png` is generated with an opaque background (not transparent PNG) to satisfy the iOS requirement noted in the layout comment.

---

## Warnings

### WR-01: `$appendedCursorIds` grows without bound and is sent in every snapshot even after CR-01 fix

**File:** `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php:108,181`

**Issue:** Once `$appendedCursorIds` is made `public` (CR-01 fix), it accumulates one key per page loaded (guard key = cursorId of the page). With 50 rows/page and thousands of transactions, this can grow to hundreds of integer keys. Each full-history session that scrolls to the end will leave a snapshot with a large `appendedCursorIds` array in addition to the large `accumulatedRows` array.

**Fix:** After the `toggleFullHistory` reset (which already clears it to `[]`) or when trimming accumulated rows (CR-03 fix), also cap `$appendedCursorIds` to only the keys for rows still present in `$accumulatedRows`. Alternatively, since the guard's only purpose is deduplication across re-renders within the same scroll session, clear it on `toggleFullHistory` (already done) and on any currency toggle or filter change. A simpler bound: keep only the last 20 cursor keys.

---

### WR-02: Service-worker fetch handler silently passes non-`/livewire`, non-`/api` financial HTML pages to the cache match — authenticated app pages may be served stale

**File:** `resources/views/sw.blade.php:45-47`

**Issue:** The fetch handler's navigation branch (lines 39–44) correctly uses network-first with offline fallback for all `navigate` requests — financial HTML is never cached. However, the catch-all at lines 45–47:

```js
event.respondWith(
    caches.match(event.request).then(cached => cached || fetch(event.request))
);
```

applies to every non-navigate GET request that is not under `/livewire`, `/api`, or `/desktop`. In practice this means:

- Vite asset URLs (hashed, matched by cache) — correct, intended.
- `/icons/*.png` — correct.
- `/offline.html` — correct.
- **Any fetch-initiated XHR or `fetch()` call the app makes to non-excepted paths** — e.g. any future `GET /some-endpoint` that is not a navigate request and not under the three excluded prefixes would be cache-first. This is a latent risk if the app ever adds non-Livewire AJAX endpoints.

The current codebase appears to route all dynamic content through `/livewire` (already excluded), so this is not an active bug today. It is a structural trap: adding a data endpoint outside `/livewire`, `/api`, or `/desktop` would silently start being served cache-first.

**Fix:** Change the catch-all to be explicitly allowlist-only — only serve from cache for known static asset URL patterns (by pathname prefix or file extension), and pass everything else through network-only:

```js
// Only cache-first for static assets (hashed CSS/JS, icons, offline page).
const isStaticAsset =
    url.pathname.startsWith('/build/')
    || url.pathname.startsWith('/icons/')
    || url.pathname === '/offline.html';

if (isStaticAsset) {
    event.respondWith(
        caches.match(event.request).then(cached => cached || fetch(event.request))
    );
    return;
}
// Everything else: network-only (no caching of financial data).
```

---

### WR-03: `loadMore` accepts caller-supplied `$nextCursorId` and `$nextCursorPostedAt` without server-side validation — cursor can be forged to reach other users' rows

**File:** `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php:130-134`

**Issue:** The `loadMore` public Livewire action accepts `$nextCursorId` and `$nextCursorPostedAt` as parameters from the browser:

```php
public function loadMore(int $nextCursorId, ?string $nextCursorPostedAt = null): void
{
    $this->cursorId = $nextCursorId;
    $this->cursorPostedAt = $nextCursorPostedAt;
}
```

These values are then passed directly to `TransactionListQuery::recent()` / `fullHistory()`, which applies a `WHERE (posted_at, id) < (?, ?)` cursor. The query is user-scoped (via `WHERE user_id = ?` in `baseQuery`), so a forged cursor id cannot reach another user's data — user-scoping is correct. However, a malicious caller can supply an arbitrary cursor `id` value larger than any transaction they own, which would simply return 0 rows (no data disclosed). It can also supply a cursor id that maps to a transaction id belonging to them but at a different offset, potentially skipping rows.

This is a minor integrity issue rather than a data-exposure issue (user-scoping holds), but the cursor values are trusted as navigation state when they should be re-derived from the server-side snapshot. The cursor is already stored as `$nextCursorId` / `$nextCursorPostedAt` public properties on the component — but `loadMore` overwrites them with browser-supplied values rather than using the stored server-side values.

**Fix:** Remove the parameters from `loadMore` and read from the server-side snapshot instead:

```php
public function loadMore(): void
{
    // Advance to the next cursor that the last render() computed and stored.
    // $this->nextCursorId / $this->nextCursorPostedAt are set by render()
    // and dehydrated in the snapshot — the browser cannot forge them.
    $this->cursorId = $this->nextCursorId;
    $this->cursorPostedAt = $this->nextCursorPostedAt;
}
```

Update the Blade call sites accordingly:

```blade
{{-- wire:intersect variant (phone): --}}
wire:intersect.margin.0px.0px.200px.0px="loadMore"

{{-- wire:click variant (desktop): --}}
wire:click="loadMore"
```

This eliminates the need for `@js($nextCursorPostedAt)` in the Blade and makes the cursor fully server-authoritative.

---

### WR-04: `ForecastPage::deleteScenario` catches `NotFoundHttpException` but not `InvalidArgumentException` — unhandled exception on ownership violation edge case

**File:** `Modules/Forecasting/Internal/Http/Livewire/ForecastPage.php:175-190`

**Issue:** `deleteScenario` calls `($action)($scenarioId, $currentUser->user())` and catches only `NotFoundHttpException`. The `scenarioId` parameter is a raw `int` coming from the browser (Livewire public method call). The `render()` method at line 310–320 validates ownership of `$this->scenarioId` against the user's scenario list and throws `NotFoundHttpException` if it does not belong to them — but `deleteScenario` takes an independent `int $scenarioId` parameter from the wire:click call, not from `$this->scenarioId`. A caller who manually triggers `wire:call` with an arbitrary `$scenarioId` integer bypasses the ownership check done in `render()`.

The `DeleteScenario` action presumably does its own ownership check, but if it throws anything other than `NotFoundHttpException` on that failure path (e.g. an `AuthorizationException` or `ModelNotFoundException`), that exception is unhandled and surfaces as a Livewire error response.

**Fix:** Either add the missing exception types to the catch block, or validate ownership before calling the action:

```php
public function deleteScenario(int $scenarioId, CurrentUser $currentUser, DeleteScenario $action): void
{
    // Re-validate ownership: the scenarioId comes from the browser.
    $owns = false;
    foreach ($this->scenarios ?? [] as $s) {
        if ($s->id === $scenarioId) { $owns = true; break; }
    }
    if (! $owns) {
        $this->confirmingDeleteForScenarioId = null;
        return;
    }
    // ... existing try/catch
}
```

---

### WR-05: Dashboard inline `<style>` block emits a media query on every Livewire re-render — duplicate `<style>` nodes accumulate in the DOM

**File:** `Modules/Core/Resources/views/livewire/dashboard.blade.php:44-60`

**Issue:** The `<style>` block at lines 44–60 is inside the Livewire component's root `<div>`. Every time the dashboard re-renders (wire:poll.5s fires it every 5 seconds, period navigation fires it), Livewire morphs the DOM. Livewire 4's diff algorithm retains or replaces DOM nodes, but `<style>` elements inside a component root are diffed as ordinary elements. Because the content is identical on every render, Livewire will not duplicate them — the morph will match the existing `<style>` node.

However, if the component is ever unmounted and remounted (SPA navigation away and back), the `<style>` element is inserted fresh. More practically, `<style>` elements inside Livewire components are a known footgun: they are not scoped to the component and they apply to the whole document, and on certain Livewire rendering paths they can be duplicated.

**Fix:** Move the phone-responsive media query rules for `.dashboard-main` and `.dashboard-phone-order-*` out of the inline `<style>` and into `resources/css/app.css` where the rest of the Phase 4 responsive rules live. This matches the pattern used for `.settings-grid` (which also has an inline `<style>` — see WR-06), but that inconsistency should also be resolved.

---

### WR-06: Settings page inline `<style>` repeats the same pattern as the dashboard — both should be in `app.css`

**File:** `Modules/Core/Resources/views/livewire/settings-page.blade.php:14-21`

**Issue:** Same class of defect as WR-05. The `<style>` block at lines 14–21 emits:

```css
@media (max-width: 767px) {
    .settings-grid { display: flex !important; flex-direction: column !important; }
}
```

The `!important` usage indicates this rule is fighting a specificity conflict — the Tailwind grid class on `.settings-grid` at desktop. Moving to `app.css` would allow the rule to be structured properly (using `@media` inside a `@layer` block) without the `!important` escalation.

**Fix:** Move to `app.css` and remove `!important`:

```css
@media (max-width: 767px) {
    .settings-grid {
        display: flex;
        flex-direction: column;
    }
}
```

The Tailwind v4 responsive utility `md:grid` (or however the consuming template uses it) would need to be replaced with a `.settings-grid` class that Tailwind does not override — or the media query needs to be placed at a higher specificity layer than Tailwind's utility layer.

---

## Info

### IN-01: Service-worker version string injects `config('nativephp.version')` unescaped — safe today but fragile if version format ever changes

**File:** `resources/views/sw.blade.php:4`

**Issue:** `CACHE_NAME = 'beatrax-shell-v{{ config('nativephp.version') }}'` injects the version string directly into JavaScript source via Blade's `{{ }}` (which HTML-encodes, but this is a JS context, not HTML). The version string is expected to be a semver like `0.1.0` — no special characters. If the version string were ever to contain a single quote, backtick, or `</script>`-like sequence, it would be emitted into the JS string literal without JS-escaping.

This is not exploitable in the current single-user, local-only setup (the version comes from a config file, not user input), but it is a fragile pattern.

**Fix:** Wrap in `Js::from()` to guarantee JS-safe encoding: `{{ Js::from(config('nativephp.version')) }}`.

---

### IN-02: `app.blade.php` service-worker registration script has no error handler — silent registration failures go undetected

**File:** `resources/views/layouts/app.blade.php:245-248`

**Issue:** The SW registration at lines 245–248:

```js
navigator.serviceWorker.register('/sw.js', { scope: '/' });
```

The `register()` call returns a Promise. No `.catch()` is attached. If registration fails (e.g. on the very first install where `EnsureDatabaseReady` middleware runs before the user exists, though `sw` is now in the exempt list), the rejection is silently swallowed. More practically, if `Vite::asset()` inside `sw.blade.php` throws because the manifest is absent, the `/sw.js` response is a 500, and `register()` rejects silently.

**Fix:** Attach a minimal catch:

```js
navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {
    // SW registration failed — app continues to work without offline support.
});
```

---

### IN-03: `app-sidebar.blade.php` hardcodes `⌘.` glyph directly in the "Open Dev Console" kbd hint — inconsistent with D-04 requirement

**File:** `Modules/Core/Resources/views/livewire/app-sidebar.blade.php:226`

**Issue:** Line 226 renders `<span class="kbd" aria-hidden="true">⌘.</span>` directly in the server-side HTML. The D-04 requirement is "no hardcoded ⌘K glyph" — the test at `AppSidebarKbdTest` checks only for `⌘K` (not `⌘.`). The `⌘.` glyph for "Open Dev Console" is therefore not covered by the test and is hardcoded in the server-rendered HTML, which contradicts the platform-aware intent (Windows/Linux users would see the Mac glyph for this shortcut too).

This is a consistency defect: the D-04 design decision was to avoid hardcoded Mac glyphs, but only the palette shortcut was made platform-aware. The dev console shortcut is also platform-specific.

**Fix:** Apply the same platform-aware pattern as the palette kbd:

```blade
<span
    class="kbd"
    aria-hidden="true"
    x-text="$store.platform.isMac ? '⌘.' : 'Ctrl+.'"
>Ctrl+.</span>
```

Extend `AppSidebarKbdTest` to also assert the raw `⌘.` glyph is absent.

---

### IN-04: `install-hint.blade.php` — `init()` checks `window.matchMedia` on the desktop but re-shows after dismiss without persistence

**File:** `Modules/Core/Resources/views/components/install-hint.blade.php:31-33`

**Issue:** The `dismiss()` action sets `this.shown = false` — an Alpine component-local state that does not persist across page loads. The component comment says "dismissable but returns (standing hint, not one-time dismissed forever)" — this is intentional per the design spec. However, the component also shows automatically on desktop (`if window.matchMedia('(min-width: 1024px)').matches → this.shown = true`) — meaning on every page load on desktop, the hint re-appears regardless of how many times the user has dismissed it.

On a PWA-installed desktop browser the `beforeinstallprompt` event never fires (app is already installed), so `this.installable` stays `false` and the component shows the "Open beatrax in your phone's browser" copy on every page load to already-desktop-app users who have been using the app for months. This is likely contrary to intent.

**Fix:** Persist the dismiss state in `localStorage` so a dismissed hint stays dismissed for the session or a configurable duration:

```js
dismiss() {
    this.shown = false;
    try { localStorage.setItem('beatrax-install-hint-dismissed', Date.now()); } catch (e) {}
},
init() {
    // Check persistence before showing.
    try {
        const ts = parseInt(localStorage.getItem('beatrax-install-hint-dismissed') || '0', 10);
        // Dismissed within the last 30 days — stay hidden.
        if (Date.now() - ts < 30 * 24 * 3600 * 1000) { return; }
    } catch (e) {}
    // ... existing beforeinstallprompt + desktop checks
}
```

---

_Reviewed: 2026-06-11_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard + deep_
