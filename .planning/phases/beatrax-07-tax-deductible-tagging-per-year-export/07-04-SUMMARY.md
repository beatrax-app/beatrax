---
phase: 07-tax-deductible-tagging-per-year-export
plan: "04"
subsystem: Tax
tags: [corpus, yaml, livewire, settings, category-management, provenance]
dependency_graph:
  requires: [07-01]
  provides: [TaxCorpusLoader, TaxCategoryWriter, TaxSettingsSection, tax.settings-section]
  affects: [settings-page, tax_deduction_categories, users.tax_country_code]
tech_stack:
  added: []
  patterns:
    - INSERT-only provenance-safe corpus seeding (check before insert, never UPDATE)
    - Livewire method-param DI (no constructor injection on Livewire components)
    - Allow-list validation for user-supplied country codes (T-07-12)
    - 404-not-403 ownership pattern for category CRUD (T-07-13)
    - YAML::PARSE_EXCEPTION_ON_INVALID_TYPE for YAML safety (T-07-11)
key_files:
  created:
    - resources/corpus/tax/nl.yaml
    - resources/corpus/tax/de.yaml
    - resources/corpus/tax/be.yaml
    - resources/corpus/tax/fr.yaml
    - resources/corpus/tax/gb.yaml
    - resources/corpus/tax/us.yaml
    - Modules/Tax/Internal/Corpus/TaxCorpusLoader.php
    - Modules/Tax/Internal/Actions/TaxCategoryWriter.php
    - Modules/Tax/Database/Migrations/2026_06_12_000004_alter_tax_deduction_categories_short_name_nullable.php
    - Modules/Tax/Internal/Http/Livewire/TaxSettingsSection.php
    - Modules/Tax/Resources/views/livewire/tax-settings-section.blade.php
  modified:
    - Modules/Tax/Providers/TaxServiceProvider.php
    - Modules/Core/Resources/views/livewire/settings-page.blade.php
    - Modules/Tax/tests/Unit/TaxCorpusLoaderTest.php
    - Modules/Tax/tests/Feature/TaxSettingsTest.php
decisions:
  - INSERT-only corpus seeding: check (user_id, corpus_key) existence before insert, never UPDATE — preserves user renames on re-seed (Pitfall-4/T-07-14)
  - Allow-list for country codes in setTaxCountry: unknown codes silently no-op rather than error to prevent client-side injection (T-07-12)
  - No constructor DI on Livewire component: all collaborators injected as method parameters per project Livewire rules
  - short_name column fixed to nullable via corrective migration (Plan 01 created it NOT NULL; PATTERNS.md specifies nullable)
metrics:
  duration: ~40 minutes
  completed: "2026-06-12T16:36:12Z"
  tasks_completed: 3
  files_changed: 14
---

# Phase 07 Plan 04: Tax corpus YAML (6 countries) + TaxCorpusLoader, TaxCategoryWriter, TaxSettingsSection Summary

**One-liner:** Six-country YAML corpus + provenance-safe category seeder + TaxSettingsSection Livewire component wired into settings page, with INSERT-only corpus upserts preserving user renames.

## What Was Built

### Task 1: Tax corpus YAML + TaxCorpusLoader (commit bada965)

Six `resources/corpus/tax/{cc}.yaml` files (nl, de, be, fr, gb, us) — each with 6+ per-country deduction entries using the schema: `key` (machine slug), `name`, optional `short_name` (≤12 chars), optional `hint`. Keys are namespaced by country code (e.g. `nl_zorgkosten`, `de_sonderausgaben`).

`TaxCorpusLoader` reads them via `Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE)`, logs a warning and returns `[]` on ParseException or missing `entries:` key, and validates each entry has non-empty `key` and `name` strings. Unknown country codes return `[]` (no exception).

9 unit tests passing (TaxCorpusLoaderTest): Dutch corpus loads with correct fields, unknown country returns [], 6 countries parse ≥3 entries each (dataset), malformed YAML produces warning + [].

### Task 2: TaxCategoryWriter + provenance-safe seed (commit ed8961d)

`TaxCategoryWriter` provides:
- `seedFromCorpus(User, string): int` — checks `(user_id, corpus_key)` existence before each INSERT; never UPDATE branch ensures user renames survive corpus re-seeds (Pitfall-4/T-07-14)
- `add(int, string, ?string, ?string): int` — user-created category (corpus_key null); rejects duplicate (user_id, name) with RuntimeException
- `rename(int, int, string): void` — user-scoped; throws NotFoundHttpException on cross-user id (T-07-13)
- `archive(int, int): void` — sets status='archived'; user-scoped; throws NotFoundHttpException on cross-user id
- `listForUser(int, bool): array` — active (+ optionally archived) categories ordered by sort_order then name

Also created corrective migration `000004_alter_tax_deduction_categories_short_name_nullable` to fix the NOT NULL constraint from Plan 01 (PATTERNS.md specifies nullable).

10 feature tests passing (TaxSettingsTest writer slice): seed inserts, idempotency, Pitfall-4 rename preservation, additive country switching, add CRUD, cross-user 404 for rename/archive.

### Task 3: TaxSettingsSection + settings-page integration (commit f1c5d22)

`TaxSettingsSection` Livewire component:
- `mount(CurrentUser, DatabaseManager)` loads `tax_country_code` from `users` table
- `setTaxCountry(string, CurrentUser, DatabaseManager, Clock, TaxCategoryWriter)` validates allow-list first, seeds corpus, persists preference
- `addCategory`, `renameCategory`, `archiveCategory` delegate to TaxCategoryWriter with user-scoped ownership
- `render(CurrentUser, TaxCategoryWriter, ViewFactory)` passes categories list to view

Blade template uses `.settings-section` primitive: tax country select row + deduction categories row with scrollable active list, "from corpus" chip, inline add form, archived disclosure.

Registered as `tax.settings-section` in TaxServiceProvider boot. Injected into `settings-page.blade.php` after the notification preferences section.

4 additional feature tests passing (TaxSettingsTest Livewire slice): mount exposes tax_country_code, setTaxCountry seeds + persists, allow-list rejection is no-op, settings-page contains the @livewire tag.

## Test Results

```
php artisan test --filter=Tax
Tests: 21 skipped (future plans), 37 passed (179 assertions)
```

- TaxCorpusLoaderTest: 9/9 passed
- TaxSettingsTest: 14/14 passed (10 writer + 4 Livewire)
- TaxTagActionTest: 11/11 passed (Plan 01, unchanged)
- TaxBoundaryTest: 1/1 passed (arch test)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] short_name column was NOT NULL in Plan 01 migration**
- **Found during:** Task 2 — TaxCategoryWriter add() uses `short_name` as nullable
- **Issue:** Plan 01 migration created `short_name VARCHAR(32) NOT NULL DEFAULT ''`. PATTERNS.md (line ~712) specifies `->nullable()`. Add without short_name would always supply empty string, silently corrupting null semantics.
- **Fix:** Created corrective migration `2026_06_12_000004_alter_tax_deduction_categories_short_name_nullable.php` to change column to nullable
- **Files modified:** Modules/Tax/Database/Migrations/2026_06_12_000004_alter_...
- **Commit:** ed8961d

**2. [Rule 1 - Bug] TaxCorpusLoader test path manipulation via resource_path() override**
- **Found during:** Task 1 TDD RED phase
- **Issue:** `app()->bind('path.resources', ...)` does not affect the `resource_path()` helper; it calls `app()->resourcePath()` which uses `$this->basePath('resources')`, bypassing the container binding
- **Fix:** Malformed YAML test directly writes bad YAML to the real corpus file path and restores it in a `finally` block. This is a pragmatic workaround for hermetic testing when resource_path() is not mockable.
- **Files modified:** Modules/Tax/tests/Unit/TaxCorpusLoaderTest.php

**3. [Rule 1 - Bug] Blade view MultipleRootElementsDetectedException**
- **Found during:** Task 3 Livewire tests
- **Issue:** tax-settings-section.blade.php had two top-level `<div>` elements; Livewire requires a single root element
- **Fix:** Wrapped both sections in a `<div class="space-y-4">` outer wrapper
- **Files modified:** Modules/Tax/Resources/views/livewire/tax-settings-section.blade.php

**4. [Rule 1 - Bug] Test path calculation for settings-page blade**
- **Found during:** Task 3 test for "settings page contains @livewire tag"
- **Issue:** Initial path used `dirname(__DIR__, 5)` (too many levels) then `dirname(__DIR__, 4)` without `Modules/` prefix — both produced wrong paths
- **Fix:** Changed to `dirname(__DIR__, 4).'/Modules/Core/Resources/views/livewire/settings-page.blade.php'`
- **Files modified:** Modules/Tax/tests/Feature/TaxSettingsTest.php

## Known Stubs

None. All implemented functionality is wired end-to-end.

## Threat Surface Scan

All threats from the plan's threat register are mitigated:
- T-07-11: `Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE` in TaxCorpusLoader — implemented
- T-07-12: Allow-list validation in `setTaxCountry` before seeding — implemented
- T-07-13: 404-not-403 ownership check in rename/archive — implemented
- T-07-14: INSERT-only corpus seeding (no UPDATE branch) — implemented and tested with Pitfall-4 scenario

No new threat surfaces introduced beyond those in the plan.

## Self-Check: PASSED

Files verified:
- FOUND: resources/corpus/tax/nl.yaml
- FOUND: Modules/Tax/Internal/Corpus/TaxCorpusLoader.php
- FOUND: Modules/Tax/Internal/Actions/TaxCategoryWriter.php
- FOUND: Modules/Tax/Internal/Http/Livewire/TaxSettingsSection.php
- FOUND: Modules/Tax/Resources/views/livewire/tax-settings-section.blade.php

Commits verified (git log):
- FOUND: bada965 feat(07-04): Tax corpus YAML (6 countries) + TaxCorpusLoader
- FOUND: ed8961d feat(07-04): TaxCategoryWriter — provenance-safe seed + CRUD actions
- FOUND: f1c5d22 feat(07-04): TaxSettingsSection Livewire component + settings-page integration
