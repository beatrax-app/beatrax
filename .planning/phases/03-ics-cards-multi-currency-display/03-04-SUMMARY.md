---
phase: 03-ics-cards-multi-currency-display
plan: 04
subsystem: ui
tags: [settings, livewire, multi-currency, user-preferences, period-start-day, phase-3]

requires:
  - phase: 01-foundation-asn-csv-vertical-slice
    provides: User model + period_start_day column + CurrentUser DI contract + layouts.app shell
  - phase: 03-01
    provides: SettingsPageTest scaffolds (6 Red placeholders driven Green here)

provides:
  - "users.default_currency_view column (string(16), default 'eur_only') — storage half of MC-02"
  - "User model exposes default_currency_view as typed (Larastan @property + casts() + fillable)"
  - "/settings route (auth middleware) → Livewire core.settings-page → calm form (slate-50 inputs, emerald-600 submit)"
  - "Top-nav Settings link between Uncategorized and the user-email block"
  - "Locked UI-SPEC validation copy: 'Choose a day from 1 to 28.' / 'Pick one of the available options.'"

affects: ["03-05 (TransactionsList default-mode fallback reads default_currency_view)", "03-06 (Dashboard group-by-currency reads default_currency_view)"]

tech-stack:
  added: []
  patterns:
    - "Page-level Blade wrapper + livewire alias + Route::view (matches dashboard/wizard/triage); not class-as-handler"
    - "Livewire messages() method co-located with #[Validate] attributes for UI-SPEC-locked error copy (mirrors UploadWizard pattern)"

key-files:
  created:
    - "Modules/Core/Database/Migrations/2026_05_13_010001_add_default_currency_view_to_users.php"
    - "Modules/Core/Internal/Http/Livewire/SettingsPage.php"
    - "Modules/Core/Resources/views/livewire/settings-page.blade.php"
    - "Modules/Core/Resources/views/settings.blade.php"
  modified:
    - "Modules/Core/Models/User.php (@property + fillable + casts())"
    - "Modules/Core/Providers/CoreServiceProvider.php (core.settings-page Livewire alias)"
    - "Modules/Core/Routes/web.php (Route::view /settings → core::settings)"
    - "Modules/Core/Resources/views/livewire/top-nav.blade.php (Settings link)"
    - "Modules/Core/tests/Feature/SettingsPageTest.php (6 scaffolds → Green)"

key-decisions:
  - "Phase 03 Plan 04: /settings route registration uses Route::view('/settings', 'core::settings')->name('settings') — mirrors the existing dashboard / wizard / triage page pattern (page-level Blade wrapper + @livewire alias). The plan's `class-as-handler` alternative was rejected because no existing route in the codebase uses that shape; consistency over micro-optimisation."
  - "Phase 03 Plan 04: needed a new core::settings page-level Blade (Modules/Core/Resources/views/settings.blade.php) because the project's standing pattern is layouts.app + @livewire('alias'); the plan's files_modified list did not enumerate this file but it follows directly from the Route::view choice."
  - "Phase 03 Plan 04: messages() method on SettingsPage maps both required + integer + min + max failures of periodStartDay to the SAME locked copy 'Choose a day from 1 to 28.' so the user sees one calm sentence regardless of which sub-rule flagged the value."
  - "Phase 03 Plan 04: beforeEach in SettingsPageTest seeds default_currency_view='eur_only' explicitly rather than relying on the migration default, so each test's starting state is unambiguous and survives a fixture-default refactor."
  - "Phase 03 Plan 04: Test 6 ('round-trips default_currency_view = original into the user row') asserts ONLY the user-row half; the TransactionsList consumption half is owned by plan 03-05 (deliberate split per the plan's execution_guidance)."

patterns-established:
  - "Pattern: messages() + #[Validate] attribute coexistence — when UI-SPEC locks specific error copy, place a messages() method on the Livewire Component that maps validation rule keys to the locked strings; attributes still declare the rules, messages() just overrides the surfacing"
  - "Pattern: explicit fixture-row defaults — when a column has a non-null DB default, tests should still set it explicitly in beforeEach so the test's intent is independent of migration history"

requirements-completed:
  - MC-02

duration: ~6min
completed: 2026-05-15
---

# Phase 3 Plan 04: Minimal /settings page (default_currency_view + period_start_day) Summary

**Local-only Settings page lands the storage half of the EUR-only vs original-currency toggle, plus surfaces Phase 1's deferred `period_start_day` field — six SettingsPageTest scaffolds driven Green; phase-3 group 47→53 Green, 22→16 Red exactly as the plan predicted.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-05-15T17:29:08Z
- **Completed:** 2026-05-15T17:35:16Z
- **Tasks:** 3
- **Files created:** 4
- **Files modified:** 5

## Accomplishments

- Storage column shipped: `users.default_currency_view` (string(16), default `'eur_only'`) via a forward migration that mirrors the standing `$this->schema()` helper pattern. Every existing user row is backfilled atomically with `'eur_only'`; new rows default to `'eur_only'` until the user touches the toggle.
- User model exposes the new field as typed: `@property string $default_currency_view` for Larastan, plus `'default_currency_view'` in both `$fillable` and `casts()`.
- `/settings` route reachable under the existing `Route::middleware(['web', 'auth'])->group(...)` block, named `settings`. Visiting `/settings` renders the calm form (slate-50 inputs, slate-200 borders, emerald-600 submit, inline `Saved.` confirmation with `wire:transition.duration.4000ms`).
- Livewire SFC `SettingsPage` is constructor-free; `CurrentUser` and `ViewFactory` arrive on method parameters (mount / save / render) — the project-wide DI-only invariant is preserved.
- Top nav grows a `Settings` link between Uncategorized and the user-email block, sharing the existing `$isActive('/settings')` helper.
- All 6 `SettingsPageTest` scaffolds Green (1 pre-fill assertion, 2 persistence round-trips, 2 validation rejections with verbatim UI-SPEC error copy, 1 dual-field round-trip).

## Task Commits

Each task was committed atomically:

1. **Task 1: Migration + User model — add default_currency_view column with default 'eur_only'** — `80cff4a` (feat)
2. **Task 2: SettingsPage Livewire SFC + Blade view + /settings route + top-nav link** — `a3d010d` (feat)
3. **Task 3: Drive SettingsPageTest scaffolds Green (six round-trip + validation assertions)** — `e6af452` (test)

**Plan metadata commit:** appended after this summary is written (see Self-Check below).

## Files Created/Modified

### Created
- `Modules/Core/Database/Migrations/2026_05_13_010001_add_default_currency_view_to_users.php` — Forward column-add migration; up() adds `string(16) default 'eur_only' after period_start_day`, down() drops the column. Uses the standing `$this->schema()` helper pattern (sole `app()` exception for anonymous migrations).
- `Modules/Core/Internal/Http/Livewire/SettingsPage.php` — Final Livewire 4 component; `#[Validate]` attributes for both fields; method-DI for `CurrentUser`; `messages()` method maps validation failures to locked UI-SPEC copy; `mount()` pre-fills; `save()` persists + sets `$saved = true` + dispatches `settings-saved` event.
- `Modules/Core/Resources/views/livewire/settings-page.blade.php` — Calm form view: `<section>` per Currency display / Period; locked headings + helper text verbatim per UI-SPEC; `@error` slots in `text-rose-600` below each field; `@if ($saved)` inline `Saved.` confirmation in `text-emerald-700`.
- `Modules/Core/Resources/views/settings.blade.php` — Page-level Blade wrapper; `@extends('layouts.app', ['title' => 'Settings · diederik'])` + `@livewire('core.settings-page')`. Mirrors the existing dashboard / wizard / triage page shape exactly.

### Modified
- `Modules/Core/Models/User.php` — Added `'default_currency_view'` to `$fillable`; `'default_currency_view' => 'string'` in `casts()`; `@property string $default_currency_view` in the class docblock for Larastan-strict typing.
- `Modules/Core/Providers/CoreServiceProvider.php` — Added `use Modules\Core\Internal\Http\Livewire\SettingsPage;` and `$livewire->component('core.settings-page', SettingsPage::class);` next to the existing dashboard alias.
- `Modules/Core/Routes/web.php` — Added `Route::view('/settings', 'core::settings')->name('settings');` inside the existing auth-middleware group.
- `Modules/Core/Resources/views/livewire/top-nav.blade.php` — Inserted a Settings `<a>` between Uncategorized and the user-email block, sharing the `$isActive('/settings')` helper already in the file.
- `Modules/Core/tests/Feature/SettingsPageTest.php` — Six `expect(true)->toBe(false, 'scaffold …')` placeholders replaced with real assertions; `beforeEach` creates a User with explicit `default_currency_view='eur_only'`; locked error copy asserted verbatim via `$component->errors()->first(...)`.

## Decisions Made

The plan's `<output>` section explicitly asks for four follow-up observations. Answers below.

### 1. Route registration pattern (closure vs class-as-handler)

**Decision: `Route::view('/settings', 'core::settings')->name('settings');`** (page-level Blade wrapper + `@livewire()` alias).

The plan offered three shapes: a class-as-handler (`Route::get('/settings', SettingsPage::class)`), a closure with `Livewire::mount(SettingsPage::class)`, or the existing pattern. The codebase's existing convention is `Route::view($url, $pageBlade)` + `@livewire('alias')` inside that page Blade — verified across `Modules/Core/Routes/web.php` (dashboard), `Modules/Import/Routes/web.php` (wizard), `Modules/Ledger/Routes/web.php` (transactions), `Modules/Categorization/Routes/web.php` (uncategorized). No existing route resolves a Livewire component class directly. Consistency with the dashboard / wizard / triage pages won.

### 2. Was the existing top-nav `$isActive('/settings')` helper already in place, or did it need extending?

**Already in place.** The top-nav.blade.php defines `$isActive = static fn (string $path): string => $currentPath === $path ? '<active-classes>' : '<inactive-classes>'` — a path-string generic helper, not a per-link allow-list. Adding the Settings link required no helper change; the new `<a>` simply calls `$isActive('/settings')` and the closure does the rest. Plan 03-03 did NOT add a Settings link (confirmed by reading the file before editing — only Dashboard / Transactions / Imports / Uncategorized links existed pre-edit), so this plan is the sole owner of the nav change.

### 3. UI-SPEC copy deviations

**None.** Every UI-SPEC-locked string is present verbatim:
- "Settings" / "Preferences for how your finances appear in the app."
- "Currency display" / "Default view on the transactions list" / "You can still switch per page from the transactions list."
- "Period" / "Period starts on day" / the full 1-to-28 helper paragraph (with ASCII double quotes around "your month")
- "Save settings" / "Saved."
- "Choose a day from 1 to 28." / "Pick one of the available options." (locked error copy)
- Field options "EUR only" (value `eur_only`) and "Original currency" (value `original`)

The Blade uses Tailwind's `space-y-12` for the form vertical rhythm rather than the plan's `space-y-lg` token because the project does NOT define a `space-y-lg` Tailwind utility — `space-y-12` matches the spacing scale's `lg=24px` doubled (this matches the dashboard's `space-y-12` outer shell exactly, which is the calm-aesthetic reference). This is a token-naming clarification, not a copy deviation, and does not affect the design contract.

### 4. messages() vs translation file

**In-component `messages()` method.** Same shape as `UploadWizard::messages()` (plan 03-03) — no translation files in the project yet, no `lang/` directory in scope. The `messages()` method maps validation rule keys to the locked strings: `periodStartDay.required`, `periodStartDay.integer`, `periodStartDay.min`, `periodStartDay.max` all yield `"Choose a day from 1 to 28."`; `defaultCurrencyView.required` and `defaultCurrencyView.in` yield `"Pick one of the available options."`. The single-message-for-multiple-sub-rules pattern is deliberate so the user sees one calm sentence regardless of which boundary the input crossed.

## Deviations from Plan

**None functionally**, but two small documentation-style notes for the next executor:

1. **Page-level Blade was not in the plan's `files_modified` list.** The plan enumerated `Modules/Core/Resources/views/livewire/settings-page.blade.php` (the Livewire component view) but not `Modules/Core/Resources/views/settings.blade.php` (the page-level Route::view target). This file is required by the chosen route pattern and is the analogue of `Modules/Core/Resources/views/dashboard.blade.php`. Treated as a Rule 3 auto-fix (blocking issue: cannot reach the SettingsPage without it).
2. **Livewire alias registration in CoreServiceProvider was not in the plan's `files_modified` list.** Required so `@livewire('core.settings-page')` resolves. Matches the existing `core.dashboard` alias registration line directly above. Also a Rule 3 auto-fix.

Both are mechanical consequences of decision #1 above (route pattern choice); no plan rewrite would change the SettingsPage / form / migration / test files.

## Tooling Compliance

- **Pint:** clean on every new and modified file.
- **PHPStan level=max strict:** clean on `Modules/Core/Models/User.php`, `Modules/Core/Internal/Http/Livewire/SettingsPage.php`, `Modules/Core/Providers/CoreServiceProvider.php`. (A pre-existing `BelongsToUser` trait-unused warning under `Modules/Core/Public/Concerns/` is out of scope per the executor's scope-boundary rule — verified present on `main` before this plan's commits.)
- **DI-only:** SettingsPage has zero `auth()` / `Auth::user()` / facade references; method-DI throughout.
- **GSD-agnostic:** zero `.planning/` / `D-XX` / `PLAN.md` references in any committed PHP/Blade source.

## Test Posture

After this plan:

- **Phase-3 group** (`vendor/bin/pest --group=phase-3 --exclude-group=integration`): **53 Green / 16 Red** (was 47 Green / 22 Red on `main`). +6 Green / -6 Red driven by the six SettingsPageTest cases. The 16 remaining Red cases are owned by 03-05 (TransactionsListCurrencyToggleTest = 7), 03-06 (DashboardCurrencyModeTest = 5), 03-07 (TransactionDetailFxRateTest = 4).
- **ASN Phase 1/2 regression:** 443 Green, 3 skipped — zero regression on User / InstallCommand / LoginFlow / Auth / Adapter / Pipeline / Wizard tests.

## Known Stubs

None. The Settings page wires real data end-to-end through the User row; nothing renders placeholder values.

## Threat Flags

None — `<threat_model>` covered the full surface (T-03-04-01 through T-03-04-06). No new endpoints, no new file-access paths, no new schema rows at trust boundaries beyond the `default_currency_view` column, which is server-side-validated by the `in:eur_only,original` rule before any DB write.

## Self-Check: PASSED

Verified post-write:

- All 10 declared files exist on disk (migration, SettingsPage SFC, settings-page.blade, page-level settings.blade, modified User model, CoreServiceProvider, Routes/web.php, top-nav.blade, SettingsPageTest, this SUMMARY.md).
- All 3 task commits resolved against `git log --oneline --all`:
  - `80cff4a` Task 1 (migration + User model)
  - `a3d010d` Task 2 (SettingsPage SFC + route + nav)
  - `e6af452` Task 3 (tests Green)
