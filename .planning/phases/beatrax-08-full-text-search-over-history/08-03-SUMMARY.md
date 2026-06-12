---
phase: "08"
plan: "03"
subsystem: Search
tags: [fts5, sqlite, query-parser, cursor-pagination, phpstan-level-10]
dependency_graph:
  requires: [08-01]
  provides: [SearchQuery, QueryParser, DidYouMeanSuggester, EntityNameSearch, SearchResultsProviderImpl]
  affects: [Search module read path, SearchResultsProvider contract binding]
tech_stack:
  added: []
  patterns:
    - FTS5 trigram 3-char minimum filtering before MATCH expression
    - DatabaseManager (not Eloquent) throughout — required for PHPStan level 10 strict staticMethod.dynamicCall
    - Cursor pagination via (posted_at, id) row-value comparison
    - toIntList() helper to cast pluck()->all() from array<mixed> to list<int>
    - preg_match assigned to $count then checked >0 (PHPStan boolean-if restriction)
key_files:
  created:
    - Modules/Search/Internal/Services/QueryParser.php
    - Modules/Search/Internal/Services/DidYouMeanSuggester.php
    - Modules/Search/Public/Services/SearchQuery.php
    - Modules/Search/Internal/Services/EntityNameSearch.php
    - Modules/Search/Internal/Services/SearchResultsProviderImpl.php
  modified:
    - Modules/Search/tests/TestCase.php
    - Modules/Search/tests/Feature/SearchQueryTest.php
decisions:
  - FTS words shorter than 3 chars are excluded from MATCH expression (Pitfall-6 trigram minimum); queries become a LIKE fallback if ALL words are short
  - Tests seed transaction_search_docs/transaction_search_fts directly (no SearchIndexWriter dependency — Plan 02 runs in parallel worktree)
  - modules_statuses.json copied from main repo — absent file disables all nwidart modules, contrary to the parallel_execution note in the plan
  - toIntList() private helper converts pluck()->all() to list<int> for PHPStan level 10 compliance
  - DidYouMeanSuggester gates at strlen >= 4 and levenshtein <= 2 to avoid noise on very short queries
metrics:
  duration: "~3h (across context window)"
  completed: "2026-06-13"
  tasks_completed: 3
  files_changed: 7
---

# Phase 08 Plan 03: FTS5 Read Side (QueryParser, SearchQuery, EntityNameSearch) Summary

Read side of full-text search: typed-token parser, FTS5 MATCH + SQL filter engine with cursor pagination, palette fast-path, entity name search, and SearchResultsProvider binding — all 10 SearchQueryTest cases GREEN.

## What Was Built

### Task 1 — QueryParser + DidYouMeanSuggester (commit 36d4cf3)

`QueryParser` extracts structured tokens from a raw query string: `account:`, `after:`, `before:`, `amount:`, `category:` prefixed tokens are parsed into a typed `filters` map; remaining words form `textQuery`. Returns `array{textQuery: string, filters: array<string, mixed>}`.

`DidYouMeanSuggester` samples the top-1000 counterparty names for the user, tokenizes them, and finds words within levenshtein distance 2 of the last query word. Gates at `strlen($query) >= 4` to avoid noise.

### Task 2 — SearchQuery::search() (commit 6d3f1c6)

FTS5 MATCH expression built from text words, each individually double-quoted and with embedded quotes doubled (Pitfall-1). Words shorter than 3 chars are stripped from the MATCH expression before sending to the trigram tokenizer (Pitfall-6); if all words are too short, search falls back to a `LIKE '%...%'` scan. SQL filters for date range, account, amount range, and category applied on top. Account filter validates ownership via a sub-query against `accounts.user_id` (T-08-06 security requirement). Highlight and snippet extracted from FTS5 `highlight()` / `snippet()` auxiliary functions. Summary aggregate (total count, total in, total out) computed from a cloned query. Cursor pagination on `(posted_at, id)` newest-first.

### Task 3 — SearchQuery::palette() + EntityNameSearch + SearchResultsProviderImpl (commit 71da41e)

`SearchQuery::palette()` returns up to 5 transaction hits with highlight snippet, optimized for the ⌘K palette response time. `EntityNameSearch` does LIKE name search across counterparties, categories, goals, pots, and recurring series — capped at 3 results per type, never crossing module boundaries. `SearchResultsProviderImpl` implements the Plan 01 `SearchResultsProvider` contract and is auto-wired by `SearchServiceProvider`'s `class_exists()` guard.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] modules_statuses.json absent disables all modules**
- Found during: Task 1 (first test run)
- Issue: The plan's parallel_execution note stated that an absent modules_statuses.json means ALL modules are enabled. In practice, nwidart treats absent file as all modules DISABLED. The `tax_transaction_tags` table did not exist in the test DB.
- Fix: Copied modules_statuses.json from the main repo root into the worktree root. Then ran `php artisan migrate` to create all module tables.
- Files modified: modules_statuses.json (worktree only; gitignored)
- Commit: Not separately committed — prerequisite fix before first task commit

**2. [Rule 1 - Bug] FTS returns 0 results for queries with short words (Pitfall-6)**
- Found during: Task 2 (SearchQueryTest it_finds_transactions_by_counterparty_name)
- Issue: "User A Vendor" contains "A" (1 char), below the SQLite FTS5 trigram 3-char minimum. Passing it verbatim to MATCH produced zero results.
- Fix: Added `array_filter` on `$ftsWords` to drop words shorter than 3 chars. If the entire text query reduces to zero FTS words, fall back to `LIKE '%textQuery%'`.
- Files modified: Modules/Search/Public/Services/SearchQuery.php
- Commit: 6d3f1c6

**3. [Rule 1 - Bug] PHPStan level 10: `if(preg_match(...))` not boolean**
- Found during: Task 1
- Issue: Larastan/PHPStan strict-rules prohibit boolean-context on non-boolean returns. `preg_match` returns `int|false`, so `if (preg_match(...))` is flagged.
- Fix: Assigned to `$count = preg_match(...)` then checked `if ($count > 0)`.
- Files modified: QueryParser.php

**4. [Rule 1 - Bug] PHPStan level 10: `->pluck()->all()` returns `array<mixed>` not `list<int>`**
- Found during: Task 2
- Issue: Account and category filter code called `->pluck('id')->all()` but PHPStan inferred `array<mixed>` — not assignable to `list<int>` in strict mode.
- Fix: Added private static `toIntList(array $values): list<int>` helper that maps through `is_numeric()` casts.
- Files modified: SearchQuery.php

**5. [Rule 1 - Bug] SearchQueryTest missing columns on direct transaction insert**
- Found during: Task 2 (it_filters_by_account test)
- Issue: Direct `DB::table('transactions')->insert()` call in the test omitted `fingerprint_version`, `normalization_version`, `source_format`, `source_row_index` — NOT NULL columns added in later migrations.
- Fix: Added all missing columns; switched to `insertGetId()` + `seedFtsIndex()`.
- Files modified: Modules/Search/tests/Feature/SearchQueryTest.php

**6. [Rule 1 - Bug] SearchQueryTest it_filters_by_category used wrong column name `type` instead of `kind`**
- Found during: Task 2
- Issue: Categories table uses `kind` column; test inserted `'type' => 'expense'`.
- Fix: Changed to `'kind' => 'expense'`.
- Files modified: Modules/Search/tests/Feature/SearchQueryTest.php

**7. [Rule 1 - Bug] Tax note test seeded FTS before inserting the tax note**
- Found during: Task 2
- Issue: it_finds_transactions_by_tax_note called `searchTestTransaction()` (which seeds FTS immediately), then inserted the tax_transaction_tags row. The FTS body therefore had no note.
- Fix: Added an explicit `$this->seedFtsIndex($txId, $this->userAId)` call after the tax note insert.
- Files modified: Modules/Search/tests/Feature/SearchQueryTest.php

## Auth Gates

None encountered.

## Known Stubs

None. All data is read from live SQLite FTS5 tables populated by the test fixture helpers.

## Threat Flags

None. No new network endpoints or auth paths introduced. All queries validate `user_id` ownership. Account filter enforces ownership check (T-08-06) by re-validating IDs against `accounts.user_id` before applying the `whereIn`.

## Self-Check: PASSED

- Modules/Search/Internal/Services/QueryParser.php — exists
- Modules/Search/Internal/Services/DidYouMeanSuggester.php — exists
- Modules/Search/Public/Services/SearchQuery.php — exists
- Modules/Search/Internal/Services/EntityNameSearch.php — exists
- Modules/Search/Internal/Services/SearchResultsProviderImpl.php — exists
- Modules/Search/tests/TestCase.php — modified (seedFtsIndex added)
- Modules/Search/tests/Feature/SearchQueryTest.php — modified (10 cases pass)
- Commits 36d4cf3, 6d3f1c6, 71da41e — all present in git log
- SearchQueryTest: 10/10 passed
- PHPStan: clean on full Modules/Search
- Pint: passed
