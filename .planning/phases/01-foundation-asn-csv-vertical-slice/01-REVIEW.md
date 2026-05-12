---
phase: 01-foundation-asn-csv-vertical-slice
reviewed: 2026-05-13T00:00:00Z
depth: standard
files_reviewed: 209
files_reviewed_list:
  - Modules/Categorization/Database/Seeders/DefaultCategoryTreeSeeder.php
  - Modules/Categorization/Internal/Http/Livewire/InlineCategoryPicker.php
  - Modules/Categorization/Internal/Http/Livewire/TriageInbox.php
  - Modules/Categorization/Internal/Listeners/SeedDefaultCategoryTree.php
  - Modules/Categorization/Providers/CategorizationServiceProvider.php
  - Modules/Categorization/Public/Actions/AssignCategory.php
  - Modules/Categorization/Public/Contracts/AssignsCategory.php
  - Modules/Categorization/Public/Dto/CategoryOption.php
  - Modules/Categorization/Public/Dto/TriageBatch.php
  - Modules/Categorization/Public/Dto/TriageRow.php
  - Modules/Categorization/Public/Events/TransactionCategorized.php
  - Modules/Categorization/Public/Services/UncategorizedTriageQuery.php
  - Modules/Categorization/Resources/views/livewire/inline-category-picker.blade.php
  - Modules/Categorization/Resources/views/livewire/triage-inbox.blade.php
  - Modules/Categorization/Resources/views/triage.blade.php
  - Modules/Categorization/Routes/web.php
  - Modules/Categorization/composer.json
  - Modules/Categorization/module.json
  - Modules/Categorization/tests/Feature/AssignCategoryTest.php
  - Modules/Categorization/tests/Feature/TriagePageTest.php
  - Modules/Categorization/tests/Pest.php
  - Modules/Categorization/tests/TestCase.php
  - Modules/Categorization/tests/Unit/DefaultCategoryTreeSeederTest.php
  - Modules/Categorization/tests/Unit/UncategorizedTriageQueryTest.php
  - Modules/Core/Database/Migrations/2026_05_12_000001_create_users_table.php
  - Modules/Core/Database/Migrations/2026_05_12_000002_create_password_reset_tokens_table.php
  - Modules/Core/Database/Migrations/2026_05_12_000003_create_sessions_table.php
  - Modules/Core/Internal/Console/DoctorCommand.php
  - Modules/Core/Internal/Console/InstallCommand.php
  - Modules/Core/Internal/Http/Livewire/Dashboard.php
  - Modules/Core/Internal/Http/Livewire/LoginForm.php
  - Modules/Core/Internal/Http/Livewire/TopNav.php
  - Modules/Core/Internal/Http/Middleware/LoopbackOnly.php
  - Modules/Core/Internal/Http/Middleware/NoStoreFinancialData.php
  - Modules/Core/Internal/Providers/FortifyServiceProvider.php
  - Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php
  - Modules/Core/Models/User.php
  - Modules/Core/Providers/CoreServiceProvider.php
  - Modules/Core/Public/Concerns/BelongsToUser.php
  - Modules/Core/Public/Contracts/Clock.php
  - Modules/Core/Public/Contracts/CurrentUser.php
  - Modules/Core/Public/Events/UserInstalled.php
  - Modules/Core/Public/Exceptions/NotAuthenticatedException.php
  - Modules/Core/Public/Models/UserScopedModel.php
  - Modules/Core/Public/Scopes/UserScope.php
  - Modules/Core/Public/Services/CurrentUserService.php
  - Modules/Core/Public/Services/SystemClock.php
  - Modules/Core/Resources/views/auth/login.blade.php
  - Modules/Core/Resources/views/dashboard.blade.php
  - Modules/Core/Resources/views/livewire/dashboard.blade.php
  - Modules/Core/Resources/views/livewire/login-form.blade.php
  - Modules/Core/Resources/views/livewire/top-nav.blade.php
  - Modules/Core/Routes/web.php
  - Modules/Core/composer.json
  - Modules/Core/module.json
  - Modules/Core/tests/Feature/DoctorCommandTest.php
  - Modules/Core/tests/Feature/InstallCommandTest.php
  - Modules/Core/tests/Pest.php
  - Modules/Core/tests/TestCase.php
  - Modules/Core/tests/Unit/BelongsToUserTraitTest.php
  - Modules/Core/tests/Unit/CurrentUserServiceTest.php
  - Modules/Core/tests/Unit/SqlitePragmasTest.php
  - Modules/Import/Internal/Http/Livewire/ImportResults.php
  - Modules/Import/Internal/Http/Livewire/PreviewWizard.php
  - Modules/Import/Internal/Http/Livewire/UploadWizard.php
  - Modules/Import/Internal/Pipeline/ImportPipeline.php
  - Modules/Import/Internal/Pipeline/PreviewCache.php
  - Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php
  - Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php
  - Modules/Import/Internal/Pipeline/Stages/ParseStage.php
  - Modules/Import/Providers/ImportServiceProvider.php
  - Modules/Import/Public/Actions/ConfirmImport.php
  - Modules/Import/Public/Actions/DiscardImport.php
  - Modules/Import/Public/Actions/RunImport.php
  - Modules/Import/Public/Contracts/ConfirmsImports.php
  - Modules/Import/Public/Contracts/NamesAccounts.php
  - Modules/Import/Public/Contracts/RunsImports.php
  - Modules/Import/Public/Dto/ImportConfirmResult.php
  - Modules/Import/Public/Dto/ImportPreviewResult.php
  - Modules/Import/Public/Dto/PreviewRowDto.php
  - Modules/Import/Public/Dto/UnknownIban.php
  - Modules/Import/Public/Services/AccountNamer.php
  - Modules/Import/Public/Services/EloquentAccountResolver.php
  - Modules/Import/Resources/views/livewire/import-results.blade.php
  - Modules/Import/Resources/views/livewire/preview-wizard.blade.php
  - Modules/Import/Resources/views/livewire/upload-wizard.blade.php
  - Modules/Import/Resources/views/preview.blade.php
  - Modules/Import/Resources/views/results.blade.php
  - Modules/Import/Resources/views/wizard.blade.php
  - Modules/Import/Routes/web.php
  - Modules/Import/composer.json
  - Modules/Import/module.json
  - Modules/Import/tests/Feature/AsnCsvImportTest.php
  - Modules/Import/tests/Feature/PreviewWizardTest.php
  - Modules/Import/tests/Feature/UploadWizardTest.php
  - Modules/Import/tests/Pest.php
  - Modules/Import/tests/TestCase.php
  - Modules/Import/tests/Unit/AccountNamerTest.php
  - Modules/Import/tests/Unit/NormalizeStageTest.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnAmountParser.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnCsvColumnMap.php
  - Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php
  - Modules/Ingestion/Providers/IngestionServiceProvider.php
  - Modules/Ingestion/Public/Contracts/AccountResolver.php
  - Modules/Ingestion/Public/Contracts/SourceAdapter.php
  - Modules/Ingestion/Public/Dto/AccountResolution.php
  - Modules/Ingestion/Public/Dto/KnownAccount.php
  - Modules/Ingestion/Public/Dto/SniffResult.php
  - Modules/Ingestion/Public/Dto/SourceTransactionDto.php
  - Modules/Ingestion/Public/Dto/UnknownAccount.php
  - Modules/Ingestion/Public/Exceptions/InvalidAmountException.php
  - Modules/Ingestion/Public/Exceptions/SniffMismatchException.php
  - Modules/Ingestion/Public/Exceptions/UnsupportedFormatException.php
  - Modules/Ingestion/Public/Services/HeaderSniffer.php
  - Modules/Ingestion/Public/Services/SourceAdapterRegistry.php
  - Modules/Ingestion/composer.json
  - Modules/Ingestion/module.json
  - Modules/Ingestion/tests/Feature/HeaderSnifferTest.php
  - Modules/Ingestion/tests/Pest.php
  - Modules/Ingestion/tests/TestCase.php
  - Modules/Ingestion/tests/Unit/AsnAmountParserTest.php
  - Modules/Ingestion/tests/Unit/AsnCsvAdapterTest.php
  - Modules/Ledger/Database/Migrations/2026_05_12_010001_create_currencies_table.php
  - Modules/Ledger/Database/Migrations/2026_05_12_010002_create_accounts_table.php
  - Modules/Ledger/Database/Migrations/2026_05_12_010003_create_categories_table.php
  - Modules/Ledger/Database/Migrations/2026_05_12_010004_create_import_runs_table.php
  - Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php
  - Modules/Ledger/Database/Migrations/2026_05_12_010006_create_merchants_table.php
  - Modules/Ledger/Database/Migrations/2026_05_12_010007_create_merchant_memories_table.php
  - Modules/Ledger/Database/Seeders/CurrenciesSeeder.php
  - Modules/Ledger/Internal/Casts/MoneyMinorCast.php
  - Modules/Ledger/Internal/Http/Livewire/TransactionsList.php
  - Modules/Ledger/Models/Account.php
  - Modules/Ledger/Models/Category.php
  - Modules/Ledger/Models/Currency.php
  - Modules/Ledger/Models/ImportRun.php
  - Modules/Ledger/Models/Transaction.php
  - Modules/Ledger/Providers/LedgerServiceProvider.php
  - Modules/Ledger/Public/Actions/RecordTransactions.php
  - Modules/Ledger/Public/Actions/UpdateTransactionCategory.php
  - Modules/Ledger/Public/Contracts/RecordsTransactions.php
  - Modules/Ledger/Public/Contracts/UpdatesTransactionCategory.php
  - Modules/Ledger/Public/Dto/CanonicalTransaction.php
  - Modules/Ledger/Public/Dto/DashboardSummary.php
  - Modules/Ledger/Public/Dto/MoneyDto.php
  - Modules/Ledger/Public/Dto/Period.php
  - Modules/Ledger/Public/Dto/RecordResult.php
  - Modules/Ledger/Public/Dto/TopCategoryRow.php
  - Modules/Ledger/Public/Dto/TransactionListPage.php
  - Modules/Ledger/Public/Dto/TransactionRowDto.php
  - Modules/Ledger/Public/Services/FingerprintComposer.php
  - Modules/Ledger/Public/Services/PeriodQuery.php
  - Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php
  - Modules/Ledger/Public/Services/TopCategoriesByPeriodQuery.php
  - Modules/Ledger/Public/Services/TransactionListQuery.php
  - Modules/Ledger/Public/ValueObjects/Money.php
  - Modules/Ledger/Resources/views/livewire/transactions-list.blade.php
  - Modules/Ledger/Resources/views/transactions.blade.php
  - Modules/Ledger/Routes/web.php
  - Modules/Ledger/composer.json
  - Modules/Ledger/module.json
  - Modules/Ledger/tests/Feature/DashboardTest.php
  - Modules/Ledger/tests/Feature/MoneyMinorCastTest.php
  - Modules/Ledger/tests/Feature/RecordTransactionsTest.php
  - Modules/Ledger/tests/Feature/TransactionListTest.php
  - Modules/Ledger/tests/Feature/UpdateTransactionCategoryTest.php
  - Modules/Ledger/tests/Pest.php
  - Modules/Ledger/tests/TestCase.php
  - Modules/Ledger/tests/Unit/AccountModelTest.php
  - Modules/Ledger/tests/Unit/FingerprintComposerTest.php
  - Modules/Ledger/tests/Unit/MoneyValueObjectTest.php
  - Modules/Ledger/tests/Unit/PeriodQueryTest.php
  - Modules/Ledger/tests/Unit/ThisPeriodAtAGlanceQueryTest.php
  - Modules/Ledger/tests/Unit/TransactionTypeTest.php
  - app/PhpStan/Rules/BoundaryRule.php
  - app/PhpStan/Rules/Fixtures/BadBoundaryFixture.php
  - app/PhpStan/Rules/Fixtures/GoodBoundaryFixture.php
  - bootstrap/app.php
  - bootstrap/providers.php
  - composer.json
  - config/app.php
  - config/auth.php
  - config/database.php
  - config/fortify.php
  - config/modules.php
  - config/session.php
  - package.json
  - phpstan-fixtures.neon
  - phpstan.neon
  - pint.json
  - public/index.php
  - resources/css/app.css
  - resources/views/layouts/app.blade.php
  - routes/console.php
  - routes/web.php
  - tests/Contracts/BoundaryArchTest.php
  - tests/Contracts/IdempotencyContractTest.php
  - tests/Contracts/MoneyColumnsArchTest.php
  - tests/Contracts/NoExtImapTest.php
  - tests/Contracts/NoFloatMoneyArchTest.php
  - tests/Contracts/Stubs/AsnCsvAdapterStub.php
  - tests/Contracts/UserIdColumnArchTest.php
  - tests/Feature/Auth/LoginFlowTest.php
  - tests/Feature/LoopbackOnlyTest.php
  - tests/Pest.php
  - tests/TestCase.php
  - tests/Unit/PhpStanBoundaryRuleTest.php
  - vite.config.js
findings:
  critical: 12
  warning: 18
  info: 19
  total: 49
status: blockers_found
---

# Phase 1: Code Review Report

**Reviewed:** 2026-05-13
**Depth:** standard
**Files Reviewed:** 209
**Status:** blockers_found

## Summary

Substantive review of the entire Phase 1 vertical slice. The wiring is internally consistent and the Money / DI / boundary rules are taken seriously, but several real defects undermine project invariants that future phases will rely on. The two most important findings are: (1) `FingerprintComposer::compose` does not include `userId` in the fingerprint tuple while the SHA-256 UNIQUE index is global, so the very first second user importing any overlapping transaction will be silently rejected with `INSERT OR IGNORE` and counted as a duplicate — directly violating the "multi-user readiness" project invariant; and (2) two separate cursor pagination implementations skip rows whenever multiple transactions share a `posted_at` date, because the ORDER BY uses `(posted_at DESC, id DESC)` but the cursor filter is only `id < cursorId`.

A third systemic concern is widespread "planning identifier" pollution in production source — `D-04`, `D-16`, `D-17`, `D-18`, `Plan 02`, `Plan 03`, `Plan 05`, `Plan 07`, `UI-04`, `UI-SPEC`, `FND-04`, `ING-06`, `T-05-01`, `T-05-11`, `Pitfall 1`, `Pitfall 5`, `Phase 1 stub` — none of which a future reader of the codebase will be able to resolve without the planning artifacts, in direct conflict with the project's "codebase agnostic from GSD" invariant. Roughly 40 production files carry these references in PHPDocs, comments, or string literals.

Money handling is also weaker than the headline rule suggests: every dashboard query hardcodes `'EUR'` and every Blade money formatter both assumes 2-decimal currencies and performs `$minor / 100` (float arithmetic on money) — so as soon as any USD or JPY row lands (which the schema explicitly supports), totals will be wrong.

Security: `LoopbackOnly` does not handle IPv4-mapped IPv6 addresses (`::ffff:127.0.0.1`); `NoStoreFinancialData` is scoped to the `web` middleware group only (so the `/up` health route and any future non-web routes are exempt); the layout fetches webfonts from `fonts.bunny.net` (the only outbound HTTP traffic the local-only app makes); `InstallCommand`'s cloud-sync detector is path-substring-only and is bypassable via a symlink, and never validates that the supplied email or password are non-empty before creating the account.

The findings below are listed in severity order. Per the project's "fix every severity" rule, Info-level findings are surfaced too.

## Critical Issues

### CR-01: FingerprintComposer omits user_id; SHA-256 UNIQUE is global → multi-user idempotency collisions

**Severity:** Critical
**Category:** Bug / Multi-user readiness
**File:** `Modules/Ledger/Public/Services/FingerprintComposer.php:27-38` + `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php:71`

**Issue:** `compose()` builds the SHA-256 over `(accountId, postedAt, amountMinor, currency, counterpartyNormalized, sourceRef)`. `userId` is intentionally omitted from `CanonicalTransaction` here but `transactions_fingerprint_sha_uq` is a global UNIQUE index on `fingerprint` (no `user_id` prefix). Combined, this means: when partner-sharing arrives, user A and user B importing the same statement (same IBAN range or same merchant) will collide on the fingerprint. Because `RecordTransactions` uses `insertOrIgnore`, user B's import silently produces zero `inserted` rows and N `duplicates` — looking exactly like a no-op duplicate import, with no diagnostic surface to debug what went wrong. The composite UNIQUE `transactions_fingerprint_uq` has the same problem: it does not include `user_id`.

Project CLAUDE.md explicitly says: "Multi-user readiness: Single-user v1 but schema must permit a second user later without migration pain."

**Evidence:**
```php
// FingerprintComposer.php
$tuple = implode('|', [
    (string) $tx->accountId,           // not user-scoped
    $tx->postedAt->toDateString(),
    (string) $tx->amountMinor,
    $tx->currency,
    $tx->counterpartyNormalized,
    $tx->sourceRef ?? '',
]);
```
```php
// migration
DB::statement('CREATE UNIQUE INDEX transactions_fingerprint_sha_uq ON transactions(fingerprint)');
DB::statement('CREATE UNIQUE INDEX transactions_fingerprint_uq ON transactions(account_id, posted_at, amount_minor, currency, counterparty_normalized, source_ref)');
```

**Fix:** Prepend `(string) ($tx->userId ?? 0)` to the SHA-256 tuple AND switch both UNIQUE indexes to include `user_id` as the leading column. This is cheap to do now; once any production data exists it requires a migration that rewrites every fingerprint. Bump `NORMALIZATION_VERSION` so the column on `transactions` makes the regen detectable. The `account_id` does NOT substitute for `user_id`, because the planned partner-sharing model needs accounts to potentially be visible across users.

---

### CR-02: Cursor pagination skips rows whenever two transactions share `posted_at`

**Severity:** Critical
**Category:** Bug / Data integrity
**File:** `Modules/Ledger/Public/Services/TransactionListQuery.php:55-57, 66-68, 79-80` AND `Modules/Categorization/Public/Services/UncategorizedTriageQuery.php:33, 44-45`

**Issue:** Both query services apply `ORDER BY posted_at DESC, id DESC` but the cursor filter is `WHERE id < $cursorId` alone. When the last row of page 1 is `(2026-05-15, id=50)` and an older transaction with `(2026-05-10, id=999)` exists (perfectly possible — `id` is insertion order, not chronological), page 2 will EXCLUDE that transaction entirely because `999 > 50`. Rows can be silently dropped from the listing every time the user pages, especially after a back-fill import.

**Evidence:**
```php
// TransactionListQuery.php
->orderByDesc('transactions.posted_at')
->orderByDesc('transactions.id')
// ...
if ($cursorId !== null) {
    $query->where('transactions.id', '<', $cursorId);  // <-- ignores posted_at boundary
}
```

**Fix:** Either:
- Drop the secondary `id` ordering and switch to a tuple cursor: `(lastPostedAt, lastId)` with `WHERE (posted_at, id) < (?, ?)` (SQLite supports row-value compare).
- Or use a single-key cursor on `id` (drop `orderByDesc('posted_at')`) and accept the ordering change.

The first option preserves the UX. The current code is silently incorrect.

---

### CR-03: SQL aggregates mix currencies; hardcoded `'EUR'` ignores multi-currency rows

**Severity:** Critical
**Category:** Bug / Money handling / Multi-currency invariant
**File:** `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php:69-72, 90-92` AND `Modules/Ledger/Public/Services/TopCategoriesByPeriodQuery.php:52, 56, 90`

**Issue:** The dashboard's `inflow / outflow / net` aggregation does `SUM(amount_minor)` without grouping by `currency`. Then the result is wrapped in `Money::ofMinor($inflowMinor, 'EUR')`. A user with one USD Google Play row and ten EUR ASN rows in the period gets a `net.toMinor()` that is `sum(eur_minor) + sum(usd_minor)` rendered as EUR — i.e. silently wrong by the USD/EUR ratio. Same for `TopCategoriesByPeriodQuery`. The MoneyMinorCastTest at line 64-82 already proves the schema is multi-currency.

Project CLAUDE.md: "Multi-currency tracking required from v1 — Google Play (USD) and some ICS merchants charge non-EUR; preserving both currencies prevents losing FX information that can't be recovered later."

**Evidence:**
```php
->selectRaw(
    'COALESCE(SUM(CASE WHEN amount_minor > 0 THEN amount_minor ELSE 0 END), 0) AS inflow_minor,
     COALESCE(SUM(CASE WHEN amount_minor < 0 THEN -amount_minor ELSE 0 END), 0) AS outflow_minor,
     COALESCE(SUM(amount_minor), 0) AS net_minor'
)
// later:
inflow: Money::ofMinor($inflowMinor, 'EUR'),
```

**Fix:** Aggregate against `settled_amount_minor` + `settled_currency` (the project's MC-01 settled-pair specifically exists to make this safe), filter the SQL to `settled_currency = 'EUR'` for the dashboard's "your-eyes-on-this-screen total" tile, and present any non-EUR rows on a separate breakdown OR add a `currency` parameter to the query and call it once per currency. Either way, do not silently fold currencies into a single number.

---

### CR-04: `ConfirmImport` silently confirms an import with zero rows when the preview cache has expired

**Severity:** Critical
**Category:** Bug / Data loss
**File:** `Modules/Import/Public/Actions/ConfirmImport.php:55-87`

**Issue:** The 30-minute cache TTL in `PreviewCache` is a known window. If the user previews a 500-row file, leaves the wizard open for 31 minutes (a refill on lunch is enough), then clicks "Confirm import", `getCanonical($importRunId)` returns `[]` (line 76 of `PreviewCache.php`), `RecordTransactions` runs on the empty iterable, the `ImportRun` row is updated to `status='confirmed'` with `inserted_count=0`, and the cache is forgotten. From the user's point of view the file has been "imported" and the original CSV is no longer suggested. Re-uploading the same file then bumps the SHA-256 idempotency short-circuit (`file already imported`) and the user can never re-import that statement without manual DB intervention.

**Evidence:**
```php
$canonical = $this->cache->getCanonical($importRunId);  // returns [] on cache miss
// ...
$result = ($this->recorder)($canonical);                // inserts 0 rows
$importRun->update([
    'inserted_count' => $result->inserted,              // 0
    // ...
    'status' => 'confirmed',                            // user thinks it worked
]);
$this->cache->forget($importRunId);
```

**Fix:** Distinguish "cache hit with empty list" from "cache miss". Have `PreviewCache::getCanonical` return `?array` (null on miss) — or expose a `has($id)` probe. On miss in `ConfirmImport`, throw a typed `PreviewExpiredException` that the PreviewWizard component renders as "Re-upload the file to confirm" instead of silently confirming nothing. The same fix should reset the ImportRun row to `previewed` so the file's SHA-256 isn't permanently locked out.

---

### CR-05: `RunImport::runFromUpload` does NOT short-circuit when the file was already confirmed

**Severity:** Critical
**Category:** Bug
**File:** `Modules/Import/Public/Actions/RunImport.php:43-77`

**Issue:** The PHPDoc says "If the user already imported a file with the same hash AND that import landed (status='confirmed'), short-circuit with an empty preview". The code does NOT do this — when `$existing` exists, it reuses the row regardless of its status (line 56), then unconditionally re-runs `$this->pipeline->preview(...)` (line 66) and overwrites the cache (line 74). For a previously-confirmed run, this re-parses the file and re-populates the canonical batch in cache. Worse: re-clicking the wizard's "Confirm" path will hit `ConfirmImport::__invoke`, which checks `status === 'confirmed'` and returns early — but only AFTER `RunImport` has done the (idempotent but expensive) re-parse, AND only IF the user navigates through the wizard.

The actual failure mode: a user re-uploads a previously-confirmed file. They see a preview screen full of "Duplicate" rows. They click Confirm. `ConfirmImport` returns `inserted=0, duplicates=$importRun->inserted_count` — using the OLD `inserted_count` as the new "duplicates" total. If the file was re-shaped (different row order, or one row appended), the numbers shown to the user are arithmetically meaningless because the source-of-truth for the "duplicate" count is now stale.

**Evidence:**
```php
$existing = ImportRun::query()
    ->where('user_id', $user->id)
    ->where('sha256', $sha)
    ->first();

$importRun = $existing ?? ImportRun::create([ ... 'status' => 'previewed', ]);
// No branch on $existing->status here, despite PHPDoc claim.
```

**Fix:** Implement the documented short-circuit:
```php
if ($existing !== null && $existing->status === 'confirmed') {
    return new ImportPreviewResult(
        importRunId: $existing->id,
        rows: [],
        accountsToName: [],
    );
}
```
…or update the PHPDoc to match the code if the current behaviour is intentional. Right now docs and code disagree, which is the textbook signal that one of them is a bug.

---

### CR-06: `LoopbackOnly` middleware allows IPv4-mapped IPv6 addresses to be classified as non-loopback

**Severity:** Critical
**Category:** Security / Privacy
**File:** `Modules/Core/Internal/Http/Middleware/LoopbackOnly.php:20-28`

**Issue:** The allowlist is `['127.0.0.1', '::1']` only. On dual-stack systems and behind some proxies, `SERVER_ADDR` arrives as `::ffff:127.0.0.1` (RFC 4291 IPv4-mapped IPv6) — which is loopback by every reasonable interpretation, but the strict `in_array(..., true)` rejects it with a 404. The reverse is also true: if a misconfigured listener accepts WAN traffic and presents an `::ffff:<wan-ip>` to PHP, that case is correctly rejected — so the practical bug is "the privacy guard locks out legitimate localhost users on some Linux distros / Docker bridges". For a local-only app, this is a deployability failure mode that the comment thread will paper over with `unset($_SERVER['SERVER_ADDR'])` or similar, undoing the protection entirely.

The `tests/Feature/LoopbackOnlyTest.php` does not cover `::ffff:127.0.0.1` so this slipped through.

**Evidence:**
```php
private const LOOPBACK_ADDRESSES = ['127.0.0.1', '::1'];
// ::ffff:127.0.0.1, ::ffff:7f00:1, and similar dual-stack notations are rejected.
```

**Fix:** Normalize the SERVER_ADDR through `inet_pton()`, then compare against the binary forms of `127.0.0.1`, `::1`, and the v4-in-v6 prefix `::ffff:127.0.0.0/104`. Or accept the literal strings `::ffff:127.0.0.1`, `::ffff:7f00:1`, and the `127.0.0.0/8` CIDR range (`inet_aton($addr) & 0xff000000 === 0x7f000000`). Add tests for each variant.

---

### CR-07: `NoStoreFinancialData` is only on the `web` middleware group; `/up` and any non-web routes are exempt

**Severity:** Critical
**Category:** Security / Privacy
**File:** `bootstrap/app.php:19`

**Issue:** `$middleware->appendToGroup('web', NoStoreFinancialData::class);` attaches the header-setter only to routes that opt into the `web` group. The `/up` health route registered via `health: '/up'` in `withRouting()` is NOT in the `web` group — it gets no `Cache-Control: no-store`. Livewire's internal `/livewire/update` endpoint is in `web` so it's covered, but Fortify's `/login` and `/logout` are also in `web`, and any future API/CLI route added without `->middleware('web')` will silently leak financial state into browser cache. Per the phase context's "must apply to ALL authenticated responses, no exceptions."

**Evidence:**
```php
$middleware->prepend(LoopbackOnly::class);
$middleware->appendToGroup('web', NoStoreFinancialData::class);
```

**Fix:** Replace the group registration with a global append:
```php
$middleware->append(NoStoreFinancialData::class);
```
or, if the public `/up` health endpoint genuinely should be cacheable, leave it exempt explicitly and add an architecture test that asserts every other named route is covered. Either way, the current default is "miss by default" rather than "covered by default".

---

### CR-08: `InstallCommand` cloud-sync path detector is bypassable via symlinks

**Severity:** Critical
**Category:** Security / Privacy invariant
**File:** `Modules/Core/Internal/Console/InstallCommand.php:58-72`

**Issue:** The check uses `stripos($dbPath, $token)` on the raw configured path string. If a user (or attacker controlling `.env`) sets `database.sqlite = /Users/me/local/db.sqlite` where `/Users/me/local` is a symlink to `/Users/me/Dropbox/finance`, the check passes but the database lives in Dropbox. `realpath()` is the standard way to resolve the actual location before substring checks. The current implementation explicitly fails the "data must never leave the machine" project invariant.

The other concern in this command is that `resolveStringInput('email', 'Email')` returns an empty string when neither the option nor the prompt produces input, and `User::create(['email' => ''])` then succeeds (the UNIQUE constraint is satisfied because an empty string is a single distinct value). The same path lets a user be installed with a hash of the empty password — `$hasher->check('', $user->password)` then returns true, and the login form will accept any user who types an empty password. The "always check non-empty" check is missing in two adjacent places.

**Evidence:**
```php
foreach (self::CLOUD_SYNC_TOKENS as $token) {
    if (stripos($dbPath, $token) !== false) {
        // ...
        return self::FAILURE;
    }
}
// later
$email = $this->resolveStringInput('email', 'Email');        // can be ''
$password = $this->resolveStringInput('password', 'Password', secret: true); // can be ''
$user = User::create([
    'email' => $email,           // ''
    'password' => $password,     // '' → hashed → check('', $hash) === true
    ...
]);
```

**Fix:** Resolve the realpath of the database path's directory before token-matching; if `realpath()` returns false, treat the parent dir to detect the symlink target. Reject empty email and empty password with a clear error before `User::create`. Add tests for symlink-to-Dropbox and empty-input cases.

---

### CR-09: Webfont fetched from `https://fonts.bunny.net` — only outbound traffic the local-only app makes

**Severity:** Critical
**Category:** Security / Privacy invariant
**File:** `resources/views/layouts/app.blade.php:8-9`

**Issue:** The project's hard constraint is "Local only (localhost) — Privacy requirement; financial data must never leave the machine." The layout includes:
```html
<link rel="preconnect" href="https://fonts.bunny.net" />
<link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />
```
Every browser session for diederik issues at least two HTTPS requests to a third-party CDN, leaking the user's IP, the existence of the app, and the timing of every session — to a German CDN operator. While `bunny.net` is privacy-friendlier than Google Fonts, the project's invariant is total isolation, not "isolation except for fonts". The Referer header on the font CSS will also include the local hostname.

**Fix:** Self-host the Inter font: place the woff2 files under `resources/fonts/`, declare them with `@font-face` in `resources/css/app.css`, and remove the two external `<link>` tags. The `font-family` fallback chain `'Inter', system-ui, -apple-system, sans-serif` already provides graceful degradation when the local file is missing during dev.

---

### CR-10: `view()` global helper used in routes; `route()` and `csrf_token()` global helpers used in Blade — violates DI-only invariant

**Severity:** Critical
**Category:** Project invariant violation
**File:** `Modules/Import/Routes/web.php:11, 17` AND `resources/views/layouts/app.blade.php:6` AND every Blade view that uses `route()` (top-nav, dashboard, login-form)

**Issue:** Project CLAUDE.md rule #1 explicitly bans `view()`, `route()`, `url()`, `csrf_token()` etc. The `phpstan.neon` `allowedGlobalFunctions` list (lines 23-31) silently whitelists `view` — which the user-facing rule does not. This is the codebase signing off on a deviation from its own stated invariant; an enforcement rule is supposed to enforce the rule, not paper over its absence.

`Modules/Import/Routes/web.php:11, 17`:
```php
Route::get('/imports/{id}/preview', static function (string $id) {
    return view('import::preview', ['id' => (int) $id]);   // view() helper
})
```
The route at `Modules/Core/Routes/web.php:14-29` does the same thing correctly with `ViewFactory` injection (`$views->make(...)->render()`) — so the pattern is known and possible.

**Fix:** Either (a) inject `ViewFactory` into the route closures of `Import/Routes/web.php` to match `Core/Routes/web.php`, then remove `'view'` from the `phpstan.neon` `allowedGlobalFunctions` list; or (b) if the user has changed their mind and `view()` is now acceptable, update CLAUDE.md to reflect the new policy. Inconsistency between policy and enforcement is the problem; pick one.

The Blade `route()` and `csrf_token()` calls are harder to remove (Livewire's own scaffold uses them) — flag the policy decision back to the user.

---

### CR-11: PreviewWizard re-uses the original Livewire temp upload path; file may have been garbage-collected

**Severity:** Critical
**Category:** Bug / Data loss
**File:** `Modules/Import/Internal/Http/Livewire/PreviewWizard.php:49-60`

**Issue:** When the user names an unknown IBAN inline, `nameAccount` calls `$importer->runFromUpload($importRun->raw_file_path, ...)`. `raw_file_path` is whatever Livewire's `WithFileUploads` returned for the original temp file — typically `/tmp/livewire-tmp/xxxxxxx`. Livewire's `TemporaryUploadedFile` is garbage-collected after 24h (or sooner if the temp filesystem cycles); the file is also moved/cleaned by the host OS's `/tmp` policy on macOS reboot. If the user opens the wizard, walks away, comes back the next day, and types in the account name, the re-preview goes RED with "File not readable" inside the `HeaderSniffer::sniff` precheck — but the error surface in `PreviewWizard::nameAccount` is bare (no try/catch), so the exception bubbles to a 500.

A second concern: the wizard persists `raw_file_path = the Livewire temp path` into `import_runs.raw_file_path`. That value is therefore worthless for audit ("from which file did this ImportRun derive?") because the file no longer exists.

**Evidence:**
```php
$importRun = ImportRun::query() /* ... */ ->firstOrFail();
$importer->runFromUpload(
    $importRun->raw_file_path,                  // /tmp/livewire-tmp/xxxx, may be gone
    $importRun->source_format,
    $user,
    basename($importRun->raw_file_path),
);
```

**Fix:** When the upload first lands, move the file to `storage/app/imports/{importRunId}.csv` (use the sanitized filename if needed) and persist that stable path. The wizard's `nameAccount` then reads from a path the app owns, not from Livewire's temporary store. Either re-preview from the stable file, or — better — keep the canonical batch in cache long enough that re-preview isn't necessary at all.

---

### CR-12: `AsnAmountParser` allows internal whitespace between sign and digits; accepts amounts the bank cannot have emitted

**Severity:** Critical
**Category:** Bug / Input validation
**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnAmountParser.php:23-26` (with `tests/AsnAmountParserTest.php` row `'+ 1.23' → 123`)

**Issue:** The parser strips `'+'`, regular space, and U+00A0 from anywhere in the input before regex-matching. The test even encodes this as a correct outcome:
```php
'internal space between sign and digits' => ['+ 1.23', 123],
```
Real ASN exports never produce `'+ 1.23'`. The relaxation means: a malformed CSV cell `"+ 1.23"` is happily accepted as `123`, and any input where a fragment looks numeric after whitespace stripping silently parses. This is the opposite of the project's "Pitfall 1 — integer-only by construction" stance; the parser should reject any unexpected shape so corrupt CSVs are loud rather than quietly mis-importing. Worse: this same stripping turns `'1 0.00'` into `'10.00'` — silently merging two distinct cells if the CSV was concatenated by mistake.

**Evidence:**
```php
$normalized = str_replace(['+', ' ', "\u{A0}"], '', trim($raw));
if (preg_match('/^(-?)(\d+)\.(\d{2})$/', $normalized, $m) !== 1) { ... }
```

**Fix:** Only trim leading/trailing whitespace (the regex already requires `^...$`). Drop the global `str_replace(' ', '', …)`. Accept a single leading `'+'` explicitly in the regex (`'/^([+-]?)(\d+)\.(\d{2})$/'`), not via pre-strip. Update the AsnAmountParserTest to expect `InvalidAmountException` for `'+ 1.23'` and `'1 0.00'`.

---

## Warnings

### WR-01: PHPDoc references undefined "Plan N" / "D-NN" / "UI-SPEC" identifiers throughout production code

**Severity:** Warning
**Category:** Project invariant violation (codebase agnostic from GSD)
**File:** Approximately 40 files. Representative offenders:
- `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php:14, 23, 33`
- `Modules/Ledger/Public/Services/TransactionListQuery.php:23, 32`
- `Modules/Ledger/Public/Actions/UpdateTransactionCategory.php:13`
- `Modules/Ledger/Public/Contracts/RecordsTransactions.php:12`
- `Modules/Ledger/Public/Contracts/UpdatesTransactionCategory.php:11`
- `Modules/Ledger/Public/Services/FingerprintComposer.php:12`
- `Modules/Ledger/Public/ValueObjects/Money.php:16`
- `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php:23, 27, 41, 44, 50, 64, 67, 70`
- `Modules/Categorization/Public/Actions/AssignCategory.php:16, 18`
- `Modules/Categorization/Public/Events/TransactionCategorized.php:10`
- `Modules/Categorization/Database/Seeders/DefaultCategoryTreeSeeder.php:18`
- `Modules/Core/Internal/Http/Livewire/Dashboard.php:17, 26, 33`
- `Modules/Core/Internal/Http/Livewire/TopNav.php:19`
- `Modules/Core/Public/Events/UserInstalled.php:10`
- `Modules/Core/Routes/web.php:22`
- `Modules/Ledger/Internal/Http/Livewire/TransactionsList.php:14, 20`
- `Modules/Import/Internal/Pipeline/PreviewCache.php:16`
- `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php:18, 22, 26`
- `Modules/Import/Internal/Http/Livewire/UploadWizard.php:24, 92`
- `Modules/Import/Internal/Http/Livewire/PreviewWizard.php:26`
- `Modules/Import/Providers/ImportServiceProvider.php:31`
- `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php:12, 15`
- `Modules/Ingestion/Public/Services/HeaderSniffer.php:17`
- `Modules/Ingestion/Public/Exceptions/SniffMismatchException.php:13`
- `Modules/Ingestion/Public/Contracts/AccountResolver.php:14`
- `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php:31, 33`
- `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php:16-19` (also a "Three values had to be corrected" historical narrative)
- `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvColumnMap.php:9-17` (historical "added in 2026" narrative)
- `Modules/Core/Internal/Console/InstallCommand.php:80-81` ("would cross the module boundary")
- `Modules/Ledger/Models/Transaction.php:50` (LED-02)
- `Modules/Ledger/Public/Dto/CanonicalTransaction.php:15` (Pitfall 5)
- `Modules/Ledger/Public/Dto/DashboardSummary.php:16` (D-18)
- `Modules/Ledger/Database/Seeders/CurrenciesSeeder.php` — clean
- `config/session.php:17` (D-11)
- `config/database.php:21` (FND-06)
- `routes/web.php:5` (Plan 02)
- `routes/console.php:5` (Plan 02)
- `Modules/{Core,Ingestion,Import,Ledger,Categorization}/Routes/console.php:5` and `web.php` ("attach in later plans")
- `Modules/Core/Public/Concerns/BelongsToUser.php` — clean
- Several test files repeat the pattern (`tests/Contracts/Stubs/AsnCsvAdapterStub.php:11`, every `Modules/*/tests/TestCase.php`).

**Issue:** CLAUDE.md rule #2: "No references to `.planning/`, `PLAN.md`, `RESEARCH.md`, `gsd-*` slash commands, or GSD workflow concepts in production code, PHPDocs, or comments." Identifiers like `D-04`, `UI-04`, `T-05-11`, `FND-06`, `LED-02`, `Plan 03`, `Phase 7 MerchantMemory`, `Pitfall 5`, `Plan 05's Livewire convention` are all GSD workflow concepts — a developer reading this codebase six months from now without the `.planning/` directory open cannot resolve any of them. The rule is specifically about reading the source code in isolation.

**Fix:** Sweep every PHPDoc and comment and rewrite the rationale in self-contained terms. For each identifier:
- `D-04` / `D-16` / `D-17` / `D-18` / `LED-02` / `FND-04` / `FND-06` / `FND-07` / `ING-06` / `ING-07` / `ING-08` / `MC-01` / `CAT-01` / `CAT-03` / `CAT-05` / `UI-04` / `T-05-01` / `T-05-03` / `T-05-11` / `A9` / `A13` → describe what the code does, not which plan identifier owns the rule
- `Plan N` / `Phase N` / `in later plans` / "attach here in later plans" → replace with "(reserved for future modules)" or delete the comment entirely
- "Pitfall 1" / "Pitfall 5" / "Pitfall 10" → describe the engineering hazard in the comment ("integer-only because IEEE-754 corrupts cent-precision")
- "UI-SPEC" → either inline the relevant rule or drop the reference
- "the phase research's Dutch-aware default set" → just say "Dutch-aware default category tree"
- `1-VALIDATION.md` reference in `tests/TestCase.php` doc — remove
- `01-02-SUMMARY.md` references — remove

This is one large mechanical pass. Treat it as a single sweep, not as 40 separate fixes.

---

### WR-02: Comments narrate the project's edit history ("would cross the boundary", "auto-fixed", "Three values had to be corrected", "Pre-wired here in Plan 03")

**Severity:** Warning
**Category:** Project invariant violation (docs describe current state, never history)
**File:**
- `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvHeaderProfile.php:16-19` ("Three values had to be corrected away from earlier community-reported shapes…")
- `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvColumnMap.php:9-17` ("indices 18 (Afschriftnummer) and 19 (Categorie) were added in the 2026 export")
- `Modules/Ledger/Public/Actions/UpdateTransactionCategory.php:13` ("Pre-wired here in Plan 03 so Plan 07's categorization UI…")
- `Modules/Categorization/Public/Events/TransactionCategorized.php:10` ("Phase 1 has no listener attached")
- `Modules/Core/Public/Concerns/BelongsToUser.php:19` (acknowledges the workaround)
- `Modules/Core/Internal/Providers/FortifyServiceProvider.php:32` ("Defense-in-depth: even if a future change drops…")
- `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php:22-23` ("Phase 4 introduces transfer detection which replaces this mapping")
- `Modules/Categorization/Public/Actions/AssignCategory.php:17` ("later phases (Phase 7 MerchantMemory)")

**Issue:** CLAUDE.md rule #3: "Docs describe current state, never history". The current docs read like a changelog — "this used to be X, then we changed it to Y because Z". A reader six months later doesn't care about the journey; they care about what the code does now.

**Fix:** Rewrite each offending PHPDoc to be timeless. Example pattern:
- ❌ "Three values had to be corrected away from earlier community-reported shapes:"
- ✅ "The ASN 'CSV met IBAN' export uses these settings:"
- ❌ "Phase 4 introduces transfer detection which replaces this mapping"
- ✅ (delete the comment, or replace with "Sign → type mapping; the transfer-pair detector overrides this for matched cross-account flows when configured.")

---

### WR-03: `Category` global SELECT in InlineCategoryPicker and TriageInbox leaks user categories cross-user

**Severity:** Warning
**Category:** Security / Multi-user readiness
**File:** `Modules/Categorization/Internal/Http/Livewire/InlineCategoryPicker.php:54-66` AND `Modules/Categorization/Internal/Http/Livewire/TriageInbox.php:79-91`

**Issue:** Both Livewire components load categories with `$db->connection()->table('categories as c')->leftJoin('categories as p', ...)` without filtering by `user_id`. The default seeded categories have `user_id = NULL`, so v1 works — but the moment a user creates a custom category (which the data model already supports — the column is on the migration), every other user sees it in the picker. The fix is trivial and project CLAUDE.md explicitly demands "schema must permit a second user later without migration pain."

**Fix:** Add `->where(function ($q) use ($userId) { $q->whereNull('c.user_id')->orWhere('c.user_id', $userId); })` to both queries. Inject the current user's id via the existing `CurrentUser` contract. The DRY angle (see WR-04) means doing this once in a shared service.

---

### WR-04: Identical 40-line `loadCategoryOptions` / `mapOption` / `toInt` / `toString` block duplicated in two Livewire components

**Severity:** Warning
**Category:** Code quality
**File:** `Modules/Categorization/Internal/Http/Livewire/InlineCategoryPicker.php:54-97` AND `Modules/Categorization/Internal/Http/Livewire/TriageInbox.php:79-122`

**Issue:** The two components implement byte-for-byte identical "fetch categories, flatten parent/child, map to CategoryOption" logic. Any fix to one (e.g. user-scoping per WR-03) has to happen in both, and one of them is going to drift.

**Fix:** Extract a `CategoryOptionsQuery` Public service in the Categorization module, wire it as a singleton, and have both components inject it on `render()`. The DTO `CategoryOption` already lives in the Public namespace so the service can return `list<CategoryOption>` directly.

---

### WR-05: `NormalizeStage` hardcodes `sourceFormat: 'asn-csv'` — every future adapter's rows will be mislabeled

**Severity:** Warning
**Category:** Bug / Forward-compatibility
**File:** `Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php:70`

**Issue:** The stage builds a `CanonicalTransaction` from any `SourceTransactionDto`, but the `sourceFormat` field is a string literal `'asn-csv'`. When the next adapter (CAMT.053, MT940, ICS, PayPal, Google Play) lands, every row imported through that adapter will be persisted with `source_format = 'asn-csv'` — and the per-adapter audit story breaks. The unit test `NormalizeStageTest.php:129` even pins the literal as correct (`expect($canonical->sourceFormat)->toBe('asn-csv')`).

**Fix:** Plumb the format through the pipeline. Either accept it as a parameter on `NormalizeStage::run(...)`, or surface it on `SourceTransactionDto` (the adapter knows its own format already). Update the test to assert "matches the originating adapter's format".

---

### WR-06: `accounts.iban` and `accounts.slug` are UNIQUE globally, not per-user — blocks future multi-user joint accounts

**Severity:** Warning
**Category:** Multi-user readiness / Schema
**File:** `Modules/Ledger/Database/Migrations/2026_05_12_010002_create_accounts_table.php:17-19`

**Issue:**
```php
$table->string('slug')->unique();
$table->string('iban', 34)->unique();
```
Both indexes are global. If two users on the same diederik instance own different ASN accounts that share an IBAN (joint account, family share), the second `Account::create` errors with a UNIQUE violation. Project CLAUDE.md: "schema must permit a second user later without migration pain". This is exactly the "migration pain" case.

**Fix:** Replace both with `$table->unique(['user_id', 'iban'])` and `$table->unique(['user_id', 'slug'])`. The current `AccountNamer::slug = slug($name) . '-' . last4(iban)` derivation is then no longer load-bearing.

---

### WR-07: `TopCategoriesByPeriodQuery::fullPath` can infinite-loop on a parent-cycle in `categories`

**Severity:** Warning
**Category:** Bug / Robustness
**File:** `Modules/Ledger/Public/Services/TopCategoriesByPeriodQuery.php:138-153`

**Issue:** The category tree is stored as `parent_id`-references in the same table. The default seeder never creates a cycle, but Eloquent doesn't enforce acyclicity — a user importing categories from an external dump, or a buggy edit screen later, could persist `A.parent_id = B; B.parent_id = A`. `fullPath` does `while (isset($byId[$current]))` with `$current = $parentId`; on a cycle, `$byId[$current]` is always set and the loop never terminates. The same risk exists in `loadCategories` (lines 107-133), but there the `isset($known[$parentId])` guard prevents re-queueing, so that one is safe.

**Fix:** Add a depth guard or a visited set:
```php
$visited = [];
while (isset($byId[$current]) && ! isset($visited[$current])) {
    $visited[$current] = true;
    // ...
}
```
or simply bound the loop to 16 iterations (the tree depth ceiling) and throw on overflow.

---

### WR-08: `RecordTransactions::insertOrIgnore` bypasses Eloquent's `creating` hook → type validation drift risk

**Severity:** Warning
**Category:** Bug / Defense in depth
**File:** `Modules/Ledger/Public/Actions/RecordTransactions.php:33-52` + `Modules/Ledger/Models/Transaction.php:115-124`

**Issue:** The action does an explicit `if (! in_array($row->type, Transaction::TYPES, true))` check at line 35. Good. But the `Transaction` model also has a `static::creating(...)` listener that performs the same check — which is dead code in this path because `insertOrIgnore` does NOT fire model events. The result: there are two enforcement points; if a developer ever changes `RecordTransactions` to use `insertOrIgnore` indirectly via Eloquent (or simply forgets the explicit check), the `creating` hook will silently not fire and bad types reach the DB. The migration comment correctly notes the SQLite CHECK constraint workaround, but the design then relies on two redundant Application-layer checks, only one of which is actually live.

**Fix:** Either
- Drop the `creating` hook (it's a footgun — looks like enforcement, isn't), and keep only `RecordTransactions`'s explicit guard, OR
- Add a runtime CHECK trigger via `DB::statement('CREATE TRIGGER ...')` in the migration so the DB itself rejects bad types regardless of which write path is used.

Option two is the right one — the trigger is a single statement and makes the invariant truly load-bearing.

---

### WR-09: `LedgerServiceProvider` registers `PeriodQuery` as a singleton, but it depends on per-request `CurrentUser`

**Severity:** Warning
**Category:** Bug / Cross-request state leak risk
**File:** `Modules/Ledger/Providers/LedgerServiceProvider.php:35`

**Issue:** `$this->app->singleton(PeriodQuery::class)`. `PeriodQuery` injects `CurrentUser` in its constructor (`Modules/Ledger/Public/Services/PeriodQuery.php:27-30`). In a vanilla php-fpm request lifecycle the container is rebuilt per request so this is benign — but the moment the project goes Octane / queue worker / long-lived process, the singleton resolves `CurrentUser` ONCE (from whoever the first request's user was) and reuses that resolution forever. The same applies to any future singleton in the same module that takes a per-request collaborator. Strict reading: a singleton that depends on a non-singleton is fragile by construction.

**Fix:** Drop the singleton on `PeriodQuery` (`$this->app->bind(...)` is the default — and `PeriodQuery` is cheap to construct). Or, if singleton is preferred, change `PeriodQuery` to take `CurrentUser` per-method rather than per-instance. Same pattern review for `ThisPeriodAtAGlanceQuery` (which depends on `TopCategoriesByPeriodQuery` and `TransactionListQuery`, both of which take `User $user` per call — so they're singleton-safe).

---

### WR-10: Session encryption is disabled (`'encrypt' => false`) for a finance app — sensitive flash data passes through the session as cleartext

**Severity:** Warning
**Category:** Security
**File:** `config/session.php:34`

**Issue:** For a financial dashboard, session flash payloads commonly carry sensitive data (validation errors with email values, recent search inputs, redirect intents). On disk, the `sessions` table stores a `longText('payload')` (`Modules/Core/Database/Migrations/2026_05_12_000003_create_sessions_table.php:18`) which is base64-of-serialised-array — not encrypted. If a non-diederik process reads the SQLite file (backup tool, malware) the session contents are recoverable. Encryption would mitigate that.

**Fix:** `'encrypt' => true` in `config/session.php`. APP_KEY is already required by Laravel; this is one boolean change and a session reset. Acceptable tradeoff for local-only since the user owns the box, but the current default is the weaker option.

---

### WR-11: `HeaderSniffer` rejects files with a UTF-8 BOM even though the project says ASN exports may carry one

**Severity:** Warning
**Category:** Robustness / Bug
**File:** `Modules/Ingestion/Public/Services/HeaderSniffer.php:57-82`

**Issue:** `strtok($head, "\r\n")` returns the raw first line. If ASN ever ships a UTF-8 BOM (`\xEF\xBB\xBF`) prefix — which the spec doesn't forbid, and `file -bI` is the project's documented detection method — `$columns[0]` will be `"\xEF\xBB\xBFDatum"`. The HEADER_SIGNATURE comparison `$columns[0] !== 'Datum'` will fire, throwing `SniffMismatchException` with the message "This CSV doesn't match the expected ASN column layout (header starts with 'Datum,Je rekening', got 'Datum,Je rekening')" — the displayed strings look identical but differ by the BOM, leaving the user staring at an impossible error message.

**Fix:** Strip a leading BOM from `$head` before parsing:
```php
if (str_starts_with($head, "\xEF\xBB\xBF")) {
    $head = substr($head, 3);
}
```

---

### WR-12: `LoginForm` Livewire component is dead state — the form `POST`s to Fortify directly, `wire:model` does nothing

**Severity:** Warning
**Category:** Code quality
**File:** `Modules/Core/Internal/Http/Livewire/LoginForm.php:18-28` + `Modules/Core/Resources/views/livewire/login-form.blade.php:7`

**Issue:** The form's `action="{{ route('login') }}"` posts to Fortify's `/login` directly. The `wire:model="email"`/`wire:model="password"`/`wire:model="remember"` declarations bind to public Livewire properties that nothing reads — every form submission goes through the regular HTTP POST channel. The Livewire component buys nothing. It also costs a Livewire round-trip per keystroke (which Livewire 4 batches but is still wire traffic) and keeps the password in component state for the brief moment between input and submit.

**Fix:** Either fully Livewire-ify the form (handle submit in PHP, dispatch to Fortify's `authenticateUsing` callback, take advantage of validation messaging), or strip the `wire:model` declarations and turn `LoginForm` into a plain Blade partial (no Livewire component at all). Don't carry both.

---

### WR-13: Cloud-sync token list misses common spelling variants (e.g. lowercase `dropbox`, `google_drive` with underscore)

**Severity:** Warning
**Category:** Security / Privacy invariant
**File:** `Modules/Core/Internal/Console/InstallCommand.php:40-47`

**Issue:** `stripos` is case-insensitive but the token list uses the canonical product names. A path like `/Users/me/google_drive_local/db.sqlite` does NOT match `'Google Drive'` (different separator). `OneDrive Personal` matches `'OneDrive'` (good). `My Drive` (Google Drive's default mount name on some configurations) does not match anything. `Box.com Sync`, `pCloud Drive`, `Sync.com`, `MEGAsync` are not on the list.

**Fix:** Add a defense-in-depth check: scan for any directory in the path that is a known macOS cloud-sync mountpoint (`~/Library/CloudStorage/` is the canonical macOS Monterey+ location for ALL cloud-sync vendors — checking just that one path catches every Apple-supported provider). Combine with the per-vendor list for non-CloudStorage installs.

---

### WR-14: `FingerprintStage::isExistingFingerprint` doesn't filter by user — could read across users in tests/CLI without auth

**Severity:** Warning
**Category:** Defense in depth
**File:** `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php:24-29`

**Issue:** `Transaction::query()->where('fingerprint', $fingerprint)->first()` relies on the `BelongsToUser` global scope to do user-scoping. The `UserScope` falls through to "no scope applied" when `CurrentUser::id()` throws (i.e. when not authenticated — see `Modules/Core/Public/Scopes/UserScope.php:30-34`). In CLI tests, scripts, queue workers, or any unauthenticated context, this query then becomes a cross-user lookup. Combined with CR-01 (the fingerprint already collides across users), preview rows could be marked "duplicate" because a different user's row exists. The fix is defense-in-depth — even if CR-01 is fixed, this query should explicitly filter by user.

**Fix:** Take `User $user` as a parameter and filter:
```php
public function isExistingFingerprint(CanonicalTransaction $tx, User $user): bool
{
    $fingerprint = $this->fingerprints->compose($tx);
    return Transaction::query()
        ->where('user_id', $user->id)
        ->where('fingerprint', $fingerprint)
        ->exists();
}
```
And use `->exists()` instead of `->first() !== null` — cheaper.

---

### WR-15: `Dashboard::previousPeriod` and `nextPeriod` accept arbitrary user-controlled `$periodStartStr` from the wire state without validation

**Severity:** Warning
**Category:** Bug / Robustness
**File:** `Modules/Core/Internal/Http/Livewire/Dashboard.php:42-65, 75-77`

**Issue:** `$periodStartStr` is a public Livewire property hydrated from the client. `CarbonImmutable::parse($this->periodStartStr)` will throw `Carbon\Exceptions\InvalidFormatException` (which is `RuntimeException`) on any malformed string, resulting in a 500 response when an attacker (or a buggy upgrade) submits `periodStartStr = 'not-a-date'`. The same happens on any other unexpected input. No exception handling, no input validation.

**Fix:** Validate with `Carbon::canBeCreatedFromFormat($value, 'Y-m-d')` (or `Carbon::hasFormat(...)` in v3) before parsing. On invalid input, fall through to `$periods->current()`.

---

### WR-16: `Modules/Core/Public/Models/UserScopedModel.php` is dead — no model extends it

**Severity:** Warning
**Category:** Code quality / Dead code
**File:** `Modules/Core/Public/Models/UserScopedModel.php:17`

**Issue:** The class was added with the rationale "Plan 03+ domain models extend `UserScopedModel`", but every Phase-1 domain model (`Account`, `Category`, `Transaction`, `ImportRun`, `Merchant` if it existed) extends `Model` directly and uses `BelongsToUser` independently. The abstract base has zero subclasses. It's a public API surface that exists only as a future-promise — and a developer reading the codebase will assume it's load-bearing.

**Fix:** Either (a) make `Account` / `Category` / `Transaction` / `ImportRun` extend `UserScopedModel` (removes the duplicated `use BelongsToUser` line on each), or (b) delete `UserScopedModel`. Don't keep a public API with no users.

---

### WR-17: `tests/Contracts/Stubs/AsnCsvAdapterStub.php` is dead test infrastructure

**Severity:** Warning
**Category:** Dead code
**File:** `tests/Contracts/Stubs/AsnCsvAdapterStub.php:15`

**Issue:** The stub is referenced from nowhere in the codebase. It was created as a placeholder during planning but never wired to a test. Delete it — it's confusing noise.

**Fix:** `git rm tests/Contracts/Stubs/AsnCsvAdapterStub.php`.

---

### WR-18: Vacuous assertion in `AsnCsvImportTest`'s "Pitfall-5 sentinel duplicate" test

**Severity:** Warning
**Category:** Test correctness
**File:** `Modules/Import/tests/Feature/AsnCsvImportTest.php:53-62`

**Issue:** The body is:
```php
$first = $this->importer->runAndConfirm($fixture, 'asn-csv', $this->fixtureUser);
$sentinelRows = Transaction::query()->where('counterparty_normalized', '_no_counterparty')->count();
expect($sentinelRows + ($first->inserted - $sentinelRows))->toBe($first->inserted);
```
`$sentinelRows + ($first->inserted - $sentinelRows)` is algebraically `$first->inserted` for any value of `$sentinelRows` (including 0). The test always passes regardless of whether the sentinel substitution happened. The intended assertion is presumably `expect($sentinelRows)->toBeGreaterThan(0)` (the fixture has at least one nameless BEA row) or `expect($sentinelRows)->toBe(<known fixture count>)`.

**Fix:** Replace with `expect($sentinelRows)->toBeGreaterThan(0)` and add a tighter check against the known fixture row count if available.

---

## Info

### IN-01: Blade money formatter assumes 2-decimal currencies; performs float arithmetic on money

**Severity:** Info
**Category:** Multi-currency invariant / Money handling
**File:** `Modules/Core/Resources/views/livewire/dashboard.blade.php:8`, `Modules/Ledger/Resources/views/livewire/transactions-list.blade.php:2`, `Modules/Categorization/Resources/views/livewire/triage-inbox.blade.php:2`, `Modules/Import/Resources/views/livewire/preview-wizard.blade.php:59`

**Issue:** `$fmt = static fn (int $minor): string => '€' . "\u{00A0}" . number_format($minor / 100, 2, '.', ',');`
The `'€'` prefix is hardcoded — USD or GBP rows render with a Euro sign. The `/100` assumes minor_unit=2 — JPY (0) and KWD (3) would render wrong. And `$minor / 100` is a float operation on money, which the project's "no float arithmetic on money" stance presumably disapproves of (even if the precision loss is invisible at 2 decimals on int64 values, the rule exists for a reason).

**Fix:** Use the `Money` value object's `format()` method (`brick/money`'s `formatTo(locale)`) at the Blade boundary, which dispatches on the currency's actual minor_unit. Replace every `$fmt(...->toMinor())` call with `$money->format('nl_NL')`.

---

### IN-02: `CanonicalTransaction::toAttributes()` uses `CarbonImmutable::now()` directly, bypassing the `Clock` contract

**Severity:** Info
**Category:** DI consistency
**File:** `Modules/Ledger/Public/Dto/CanonicalTransaction.php:55`

**Issue:** Domain code is supposed to inject `Clock` so time is mockable. The DTO is not injectable, so it can't take a Clock — but the cleaner shape is to leave `created_at` / `updated_at` to Eloquent's `$timestamps` machinery (or to the recorder action), so the DTO doesn't need to know the time.

**Fix:** Drop `created_at` / `updated_at` from `toAttributes()`. Have `RecordTransactions` add them via `$this->clock->now()` if necessary. The cleaner answer is to let SQLite-via-Eloquent fill these (but `insertOrIgnore` bypasses Eloquent — so explicit set in the action is fine). Either way, remove the direct `CarbonImmutable::now()` call from the DTO.

---

### IN-03: `MoneyMinorCast::get()` silently defaults to `(0, 'EUR')` on missing attributes

**Severity:** Info
**Category:** Defense in depth
**File:** `Modules/Ledger/Internal/Casts/MoneyMinorCast.php:32-40`

**Issue:** When the underlying `amount_minor` or `currency` column is missing from `$attributes` (Eloquent's internal misuse, hydrating from a partial SELECT, etc.), the cast returns `Money::ofMinor(0, 'EUR')` — silently fabricating a value rather than failing. The downstream code can't distinguish "real zero" from "no data".

**Fix:** Throw a typed `MoneyColumnMissingException` when either column is absent. The `??` defaults make the cast lenient at the cost of obscuring bugs.

---

### IN-04: ` AccountNamer` does not handle the slug-collision race when two unknown IBANs share the same `last4` and `name`

**Severity:** Info
**Category:** Bug / Edge case
**File:** `Modules/Import/Public/Services/AccountNamer.php:23-39`

**Issue:** Slug derivation is `Str::slug($trimmed).'-'.strtolower($tail)`. If two distinct IBANs happen to end in the same 4 chars AND the user types the same name for both (very unlikely but possible), `Account::create` errors with a UNIQUE violation on `slug`. No retry, no surfacing the cause to the wizard, no test coverage for the case.

**Fix:** Catch the UNIQUE-violation exception and append a numeric suffix; or simply use `last8(iban)` for the slug tail (last 4 of a Dutch IBAN is the bank check digit, not the discriminator — last 8 covers two BBAN groups).

---

### IN-05: Test files use `auth()`, `Event::fake()`, `Config::set()` facades — invariant unclear

**Severity:** Info
**Category:** Project invariant ambiguity
**File:** `tests/Feature/Auth/LoginFlowTest.php:31` (`auth()`), `Modules/Categorization/tests/Feature/AssignCategoryTest.php:81` (`Event::fake`), `Modules/Core/tests/Feature/InstallCommandTest.php:11` (`Event::fake`), etc.

**Issue:** CLAUDE.md says "no facades / no helpers in production code". The phase context confirms `Event::fake` etc. are fine in tests — but tests still use `auth()` (a global helper). The project invariant doesn't explicitly carve out helpers in tests; the user may consider this a violation. Flag for explicit policy decision.

**Fix:** Either (a) accept that tests can use Laravel's `auth()` / `route()` etc. for ergonomic reasons (and document it in CLAUDE.md), or (b) replace `auth()->check()` with `$this->app->make(AuthFactory::class)->guard()->check()` for consistency.

---

### IN-06: `PreviewWizard` blade view renders two unrelated error messages concatenated

**Severity:** Info
**Category:** UX bug
**File:** `Modules/Import/Resources/views/livewire/preview-wizard.blade.php:9`

**Issue:**
```html
<p>The preview has expired. <a href="/imports/new" class="underline">Re-upload the file</a> to try again. That file doesn't look like a CSV. Drop in the ASN CSV export you downloaded from the ASN portal.</p>
```
The "preview has expired" message and "that file doesn't look like a CSV" message are concatenated as if they are one sentence. The user reads "Re-upload the file to try again. That file doesn't look like a CSV." which has no logical connection.

**Fix:** Split into two `<p>` tags, or remove the irrelevant sniffer-error sentence.

---

### IN-07: `BoundaryRule` doesn't analyze module Routes files (they are excluded in phpstan.neon)

**Severity:** Info
**Category:** Test coverage / enforcement gap
**File:** `phpstan.neon:17` (`Modules/*/Routes/*` exclusion) + `app/PhpStan/Rules/BoundaryRule.php`

**Issue:** Module Routes files are excluded from PHPStan analysis. They are also the only place where `Route::view(...)` and `Route::middleware(...)` calls happen — which is intentional. But the boundary rule is also therefore not enforced inside Routes files. If someone writes `use Modules\Ledger\Internal\Casts\MoneyMinorCast;` inside `Modules/Import/Routes/web.php`, no rule catches it. The current code doesn't do this, but the gap is real.

**Fix:** Add a separate phpstan config that runs the BoundaryRule only over `Modules/*/Routes/*`, or change the main config to exclude only Laravel-Route-class method calls rather than the whole files.

---

### IN-08: `BoundaryRule` allows imports of `Modules\Y\Routes\` and `Modules\Y\Resources\` from any module

**Severity:** Info
**Category:** Boundary enforcement scope
**File:** `app/PhpStan/Rules/BoundaryRule.php:36-42`

**Issue:** `FORBIDDEN_SUFFIXES` is `['Internal', 'Database', 'Providers']`. `Resources/` and `Routes/` are NOT in the list. A consumer module could `use Modules\Ledger\Routes\…` or `use Modules\Ledger\Resources\…` without tripping the rule. In practice no PHP class lives under Routes or Resources, but the rule's claim of "anything not under Public/ or Models/ is private" is broader than the rule's implementation.

**Fix:** Either tighten `BoundaryRule` to a whitelist (`Public`, `Models` only — everything else forbidden), or update the PHPDoc on the rule to reflect that the blacklist is the real spec. The current PHPDoc reads "Anything else under `Modules\Y\` … is private" which is inaccurate.

---

### IN-09: `AsnCsvAdapter::normaliseRow` silently rewrites booleans and numbers to strings — `league/csv` shouldn't yield non-strings, but the path exists

**Severity:** Info
**Category:** Defense in depth
**File:** `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php:113-133`

**Issue:** `league/csv` 9.x always yields strings (or null for trailing empty cells). The `is_bool($cell) ? ... : (string) $cell` branch handles a case that cannot happen with the current dependency. Either the branch is dead, or it papers over a real future bug. Reading it suggests the author wasn't sure about league/csv's contract.

**Fix:** Replace the branches with a precondition assertion (`if (! is_string($cell) && $cell !== null) { throw new InvalidAmountException(...); }`). Failing loud is the right call here.

---

### IN-10: `BelongsToUser` trait uses `Container::getInstance()` — flagged in its own PHPDoc as the exception

**Severity:** Info
**Category:** Project invariant edge
**File:** `Modules/Core/Public/Concerns/BelongsToUser.php:19-28`

**Issue:** The trait acknowledges in PHPDoc that `Container::getInstance()->make()` is "not a Laravel facade" but is effectively the same as `app()`. Project invariant #1 bans `app()` and `resolve()`; whether the static container accessor counts is a policy call. The current comment is honest about it but the user may want a different solution (e.g., the model's own `service` resolver hook).

**Fix:** Confirm with the user whether `Container::getInstance()` is an accepted exception. If not, the alternative is a lazy initializer that defers scope binding until the first query is built (which Eloquent already does internally — `addGlobalScope` accepts a closure that's invoked at query-build time, when the container is available via the standard means).

---

### IN-11: `bootstrap/providers.php` skips final newline at line 3 / declare block

**Severity:** Info
**Category:** Code style
**File:** `bootstrap/providers.php:3`

**Issue:** The file has `declare(strict_types=1);` on line 3 followed immediately by `use ...` declarations on line 4 with no blank line. Pint laravel preset usually inserts a blank line. Minor style nit; Pint should auto-fix on the next run.

**Fix:** `pint` should fix it automatically; commit the result.

---

### IN-12: `Modules/Core/Internal/Console/InstallCommand.php` does not refuse to install if id=1 was deleted but other users exist

**Severity:** Info
**Category:** Edge case
**File:** `Modules/Core/Internal/Console/InstallCommand.php:90-95`

**Issue:** `if (User::find(1) !== null) { return SUCCESS; }` — if user id=1 was deleted in a partial cleanup, but id=2 exists, this branch doesn't fire and the install attempts to create a SECOND user with whatever email was supplied. The "single-user app" invariant is local convention, not enforced.

**Fix:** Replace with `if (User::query()->exists()) { ... }`. The intent is "any user already installed", not "user id=1 specifically".

---

### IN-13: `PreviewWizard::nameAccount` wire:click string interpolation of `$unknown->iban` into JavaScript

**Severity:** Info
**Category:** Security / Defense in depth
**File:** `Modules/Import/Resources/views/livewire/preview-wizard.blade.php:32`

**Issue:** `wire:click="nameAccount('{{ $unknown->iban }}', $wire.accountName)"` interpolates the IBAN as a JavaScript single-quoted string. IBANs are alphanumeric so this is currently safe, but if a future adapter ever yields a string containing `'` (apostrophe) or `<` it will break the JS or open an XSS surface. Blade `{{ }}` HTML-escapes but the resulting HTML attribute is parsed as JS by Livewire.

**Fix:** Pass the IBAN through a Livewire `$dispatch` or store it in the component property rather than templating it into the wire:click expression.

---

### IN-14: Comment in `Modules/Core/Providers/CoreServiceProvider.php:38-41` mentions "legacy Laravel idioms" without explaining what they are

**Severity:** Info
**Category:** Doc clarity
**File:** `Modules/Core/Providers/CoreServiceProvider.php:38-41`

**Issue:** The PHPDoc says "aliases `App\Models\User` to the canonical `Modules\Core\Models\User` so legacy Laravel idioms keep working alongside the module-namespaced model." The reader is left wondering which legacy idioms — Notification routing? `auth.php` config? `password_reset_tokens`? Saying "so the test TestCase and `auth.providers.users.model` references work" would be more useful.

**Fix:** Cite the two or three concrete consumers (`tests/TestCase.php:7`, `config/auth.php:5`, `notification routing`) in the comment.

---

### IN-15: Currency formatter on dashboard handles 0% bar widths poorly — `max(2, min(100, …))` always shows a 2% sliver even for 0% rows

**Severity:** Info
**Category:** UX
**File:** `Modules/Core/Resources/views/livewire/dashboard.blade.php:87`

**Issue:** `style="width: {{ max(2, min(100, (int) round($cat->percentageOfTotal * 100))) }}%"`. The `max(2, …)` floor means a category with literally 0% spend in the displayed period still draws a 2% wide bar. Visually misleading — bars should be zero when the value is zero.

**Fix:** Apply the floor conditionally: `$percentageOfTotal === 0 ? 0 : max(2, …)`.

---

### IN-16: `tests/TestCase.php:68` writes a `currency` attribute that `Account` doesn't have

**Severity:** Info
**Category:** Test infrastructure / Code smell
**File:** `tests/TestCase.php:68`

**Issue:** `seedFixtureUserAndAccount` passes both `'currency' => 'EUR'` and `'default_currency' => 'EUR'` to `Account::query()->updateOrCreate`. The `Account` model only has `default_currency` in `$fillable`; `currency` is silently dropped by mass-assignment protection. Vestigial.

**Fix:** Remove the `'currency' => 'EUR'` key.

---

### IN-17: `LoopbackOnly` allows requests with no SERVER_ADDR (CLI / fixtures) — intentional, but the comment hides the implication

**Severity:** Info
**Category:** Doc clarity / Security
**File:** `Modules/Core/Internal/Http/Middleware/LoopbackOnly.php:14-16`

**Issue:** The PHPDoc says "Requests without `SERVER_ADDR` (CLI invocations, Pest fixtures that have not set the variable explicitly) pass through." This is true and necessary for testing — but a future deployment behind a custom php-fpm dispatcher (or a Laravel Octane bridge that doesn't populate `SERVER_ADDR`) silently bypasses the entire loopback guard. The decision is defensible for the local-only app, but the security implication isn't called out.

**Fix:** Strengthen the PHPDoc to say "WARNING: when `SERVER_ADDR` is absent, the request passes through. Ensure the production listener (Herd, nginx) always populates it." Or, more defensively, deny by default and require an opt-in for CLI tests.

---

### IN-18: `DoctorCommand` runs Composer/Node/sqlite3 via shell with no `set -e` discipline — exit codes are surfaced but messages can be misleading

**Severity:** Info
**Category:** UX clarity
**File:** `Modules/Core/Internal/Console/DoctorCommand.php:118-137`

**Issue:** `$process->run()` returns regardless of stderr; the code then uses `getOutput()` falling back to `getErrorOutput()`. If a binary writes a deprecation notice to stderr but exits 0, the version display gets truncated by the trim and the user sees a confusing first line. Composer in particular can be chatty.

**Fix:** Always use `getOutput()` for the version, and reserve `getErrorOutput()` for the warning case (`$process->isSuccessful() === false`).

---

### IN-19: `module.json` `priority: 0` for Core may not be sufficient if any future module ships an earlier provider

**Severity:** Info
**Category:** Forward-compatibility
**File:** `Modules/Core/module.json:6`

**Issue:** `nwidart/laravel-modules` registers providers in `priority` order (lower first). Core's `priority: 0` ensures it boots before everything else IF the other modules don't also use 0. The other modules' `module.json` files (not reviewed in depth) should be checked to ensure none of them is `priority: -1` or `priority: 0`, otherwise Core's `class_alias` for `App\Models\User` could fire AFTER another module tries to use the class.

**Fix:** Audit every `Modules/*/module.json` and ensure Core is strictly less than the others. Add a regression test that asserts `Modules\Core\Providers\CoreServiceProvider` is the first entry in the loaded provider list.

---

_Reviewed: 2026-05-13_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
