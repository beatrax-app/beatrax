---
phase: 01-foundation-asn-csv-vertical-slice
reviewed: 2026-05-13T08:15:58Z
depth: standard
iteration: 6
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
  - Modules/Categorization/Public/Services/CategoryOptionsQuery.php
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
  - Modules/Core/Public/Scopes/UserScope.php
  - Modules/Core/Public/Services/CurrentUserService.php
  - Modules/Core/Public/Services/SystemClock.php
  - Modules/Core/Resources/views/auth/login.blade.php
  - Modules/Core/Resources/views/auth/login-form.blade.php
  - Modules/Core/Resources/views/dashboard.blade.php
  - Modules/Core/Resources/views/livewire/dashboard.blade.php
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
  - Modules/Import/Public/Exceptions/ImportAlreadyConfirmedException.php
  - Modules/Import/Public/Exceptions/InvalidAccountNameException.php
  - Modules/Import/Public/Exceptions/PreviewExpiredException.php
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
  - Modules/Import/tests/Feature/DiscardImportTest.php
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
  - Modules/Ledger/Public/Exceptions/MoneyColumnMissingException.php
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
findings:
  blocker: 0
  warning: 0
  total: 0
status: clean
---

# Phase 1: Code Review Report (iteration 6)

**Reviewed:** 2026-05-13T08:15:58Z
**Depth:** standard
**Files Reviewed:** 209
**Iteration:** 6
**Status:** clean

## Summary

All three CI quality gates pass against the iter-5 fix series (commits `b924786`, `0942125`, `ccdddc9`, `20258a8`). The seven iter-5 findings — B-01 through B-04 plus W-01 through W-03 — landed correctly, with no observable regressions in the surrounding code.

Independently re-verified the three gates against the working tree:

```
vendor/bin/phpstan analyse  -> No errors (116 files analysed)
vendor/bin/pest             -> 1 skipped, 239 passed (6746 assertions)
vendor/bin/pint --test      -> passed
```

The CI quality bar mandated by `CLAUDE.md` ("Larastan at level 10 (max) with strict mode + Laravel Pint formatting + Pest unit/feature tests") is green for the first time since iteration 2.

### Verification of the iter-5 contract change

The most consequential iter-5 change is the new precondition in `RecordTransactions::__invoke` that throws `InvalidArgumentException` when `CanonicalTransaction.userId` is null. Verified that:

- The production caller graph (`RunImport` -> `ImportPipeline::preview` -> `NormalizeStage::run` -> `RecordTransactions::__invoke`) threads a non-null `User $user` through every hop and writes `userId: $user->id` into every CanonicalTransaction. `NormalizeStage::run`'s signature requires `User $user` (non-nullable), so the production path is statically prevented from producing a null-user row.
- All seven `$this->canonical([...])` call sites in `RecordTransactionsTest` now pass `'userId' => $this->user->id` explicitly; the new regression test `it refuses a batch that contains a row with a null user_id` pins the throw with both exception class and message-substring assertions.
- The existing `IdempotencyContractTest` exercises the full pipeline through `seedFixtureUserAndAccount` + `runAndConfirm`, so the project's headline idempotency invariant is now provably covered for the only path that can reach the recorder in production.
- `FingerprintComposerTest` continues to exercise `compose()` in isolation with the default null-user fixture; the existing `(string) ($tx->userId ?? 0)` fallback inside `compose()` keeps that path stable for unit tests without weakening the recorder's precondition.

### Verification of the iter-5 PHPStan fixes

Six independent defects were addressed across `Dashboard`, `InstallCommand`, `FingerprintStage`, `UpdateTransactionCategory`, `CategoryOptionsQuery`, and `TopCategoriesByPeriodQuery`. Verified that:

- `Dashboard::resolvePeriod` now compares `$parsed === null` against `CarbonImmutable::createFromFormat`'s actual Carbon-3 return type. The post-parse round-trip check at line 108 also catches `2026-02-30`-style strings that parse but normalise away. Combined, the contract from the method's PHPDoc — fall back to the current period on any mismatch — now holds against every malformed wire payload class.
- `InstallCommand` injects `DatabaseManager` and uses `$this->db->connection()->table('users')->exists()` in place of `User::query()->exists()`. The `User` model has no global scope, so the semantics are unchanged. The two `realpath()`-related `!== ''` clauses were dropped without changing behaviour (Larastan refines `realpath()` to `non-empty-string|false`, so the secondary check was always true).
- `FingerprintStage::isExistingFingerprint` injects `DatabaseManager` and uses raw `table('transactions')->where('user_id', ...)->where('fingerprint', ...)->exists()` in place of `Transaction::query()->...->exists()`. The explicit `user_id` filter preserves the per-user duplicate-detection semantics; `UserScope` was redundant on this path because the lookup needs the same user even in unauthenticated CLI / queue contexts.
- `UpdateTransactionCategory` injects `DatabaseManager` and uses raw `table('categories')` for the visibility predicate. The `(user_id IS NULL OR user_id = $userId)` rule is reproduced verbatim, so the W-09 ownership contract is preserved (Category's `UserScope` would have hidden global default-tree rows, which the action correctly admits). The closure parameter is typed as `Illuminate\Database\Query\Builder` so PHPStan resolves the inner `whereNull()` / `orWhere()` chain.
- `CategoryOptionsQuery::for` and `TopCategoriesByPeriodQuery::loadCategories` both type their `where(...)` callback parameter as `Illuminate\Database\Query\Builder`, matching the pre-existing typed-`JoinClause` example on line 40 of the former.

### Project-convention checks

- **Constructor DI:** Every non-test class touched by iter-5 (RecordTransactions, FingerprintStage, InstallCommand, UpdateTransactionCategory, Dashboard's `render()`, CategoryOptionsQuery, TopCategoriesByPeriodQuery) takes its collaborators through constructor injection or Livewire's method-injection convention. No facade calls, no global helpers.
- **No GSD references in source:** `grep` over `Modules/` and `app/` for `.planning/`, `PLAN.md`, `RESEARCH.md`, `REVIEW.md`, `SUMMARY.md`, `VERIFICATION.md`, `GSD`, and iteration markers returns no hits.
- **No history-narrative comments:** `grep` for "previously", "was changed", "originally", "used to be", "prior to", "migration history", "git log" against `Modules/` and `app/` returns no hits in source code.
- **Modular boundaries:** `BoundaryArchTest` enforces `Modules\X\Internal` is only used inside `Modules\X` and forbids `Illuminate\Support\Facades` under `Modules\`. Both clauses still pass.
- **Money via brick/money:** `NoFloatMoneyArchTest` and `MoneyColumnsArchTest` still pass; the iter-5 changes did not touch the money path.
- **Quality gates:** PHPStan level max + strict-rules, Pint, and Pest all pass — independently re-run against the working tree as part of this review.

## Findings

_None._ All previously surfaced issues are closed and the iter-5 fixes introduced no observable regressions or sibling defects.

---

_Reviewed: 2026-05-13T08:15:58Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
_Iteration: 6_
