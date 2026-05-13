---
phase: 3
slug: ics-cards-multi-currency-display
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-05-13
reviewed_at: 2026-05-13
---

# Phase 3 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 3.x (on PHPUnit 11) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `vendor/bin/pest --group=phase-3 --stop-on-failure` |
| **Full suite command** | `php artisan test --parallel && composer larastan && composer pint:test` |
| **Estimated runtime** | <= 90 seconds for the full Phase-3 test suite (`vendor/bin/pest --group=phase-3`) on Herd PHP 8.5 + SQLite WAL on a 2024 MacBook Air |

---

## Sampling Rate

- **After every task commit:** Run `vendor/bin/pest --group=phase-3 --stop-on-failure`
- **After every plan wave:** Run full suite (`test --parallel && larastan && pint:test`)
- **Before `/gsd-verify-work`:** Full suite must be green at Larastan level 10 strict + Pint clean
- **Max feedback latency:** <= 5 seconds for a 200-row ICS CSV ingestion (the slowest single-task path); <= 90 seconds for the entire phase-3 test group

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Dimension | Automated Command | Status |
|---------|------|------|-------------|-----------|-------------------|--------|
| 03-01-T1 | 03-01 | 1 | ING-04 / LED-03 / MC-02 / UI-06 | human-action (raw-CSV handoff) | n/a (checkpoint:human-action) | ⬜ pending |
| 03-01-T2 | 03-01 | 1 | ING-04 (fixture) | fixture + Pest group registration | `test -f Modules/Ingestion/tests/fixtures/ics/ics-sample-1.csv && [ "$(grep -Ec '[0-9]{12,}' Modules/Ingestion/tests/fixtures/ics/ics-sample-1.csv)" = "0" ] && grep -q 'phase-3' tests/Pest.php` | ⬜ pending |
| 03-01-T3 | 03-01 | 1 | ING-04 / LED-03 / MC-02 / UI-06 (scaffolds) | failing test scaffolds (>= 30 it() invocations) | `grep -c "^it(" Modules/Ingestion/tests/Unit/Adapters/Ics/IcsCsvAdapterTest.php Modules/Import/tests/Feature/IcsCsvImportTest.php Modules/Core/tests/Feature/SettingsPageTest.php Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php \| awk -F: '{s+=$2} END{exit !(s>=30)}' && ! vendor/bin/pest --group=phase-3 --stop-on-failure 2>&1 \| grep -q 'OK ('` | ⬜ pending |
| 03-02-T1 | 03-02 | 2 | LED-03 | NormalizeStage FX derivation + DTO extension | `vendor/bin/pest --filter='NormalizeStage' --stop-on-failure && vendor/bin/phpstan analyse Modules/Ingestion/Public/Dto/SourceTransactionDto.php Modules/Import/Internal/Pipeline/Stages/NormalizeStage.php --memory-limit=1G` | ⬜ pending |
| 03-02-T2 | 03-02 | 2 | ING-04 | IcsCsvAdapter + HeaderSniffer + registry | `vendor/bin/pest Modules/Ingestion/tests/Unit/Adapters/Ics/IcsCsvAdapterTest.php --stop-on-failure && vendor/bin/phpstan analyse Modules/Ingestion/Internal/Adapters/Ics --memory-limit=1G` | ⬜ pending |
| 03-02-T3 | 03-02 | 2 | ING-04 / LED-03 | IdempotencyContractTest + wire-level IcsCsvImportTest | `vendor/bin/pest Modules/Import/tests/Feature/IcsCsvImportTest.php --filter='imports every parsed row\|returns zero new rows when re-importing\|persists settled_amount_minor and settled_currency\|persists native + settled' --stop-on-failure && vendor/bin/pest tests/Contracts/IdempotencyContractTest.php --stop-on-failure` | ⬜ pending |
| 03-03-T1 | 03-03 | 3 | ING-04 | UploadWizard cascade refactor | `vendor/bin/phpstan analyse Modules/Import/Internal/Http/Livewire/UploadWizard.php --memory-limit=1G && vendor/bin/pint --test Modules/Import/Internal/Http/Livewire/UploadWizard.php` | ⬜ pending |
| 03-03-T2 | 03-03 | 3 | ING-04 | Blade two-step picker | `grep -q 'Drop in an ASN or ICS export\.' Modules/Import/Resources/views/livewire/upload-wizard.blade.php && grep -q 'wire:model\.live="issuer"' Modules/Import/Resources/views/livewire/upload-wizard.blade.php && grep -q 'aria-live="polite"' Modules/Import/Resources/views/livewire/upload-wizard.blade.php` | ⬜ pending |
| 03-03-T3 | 03-03 | 3 | ING-04 | UploadWizardTest cascade assertions | `vendor/bin/pest Modules/Import/tests/Feature/UploadWizardTest.php --stop-on-failure` | ⬜ pending |
| 03-03-T4 | 03-03 | 3 | ING-04 | PreviewWizard ICS-Account naming step + IcsCsvImportTest wizard scaffolds GREEN | `vendor/bin/pest Modules/Import/tests/Feature/IcsCsvImportTest.php --stop-on-failure && grep -q "Name your ICS card account" Modules/Import/Internal/Http/Livewire/PreviewWizard.php && [ "$(grep -c 'toBe(false' Modules/Import/tests/Feature/IcsCsvImportTest.php)" = "0" ]` | ⬜ pending |
| 03-04-T1 | 03-04 | 3 | MC-02 | users.default_currency_view migration + User model | `php artisan migrate:fresh --force --env=testing 2>&1 \| grep -q 'add_default_currency_view_to_users' && grep -q "@property string \\$default_currency_view" Modules/Core/Models/User.php` | ⬜ pending |
| 03-04-T2 | 03-04 | 3 | MC-02 | SettingsPage SFC + Blade + route + top-nav | `vendor/bin/phpstan analyse Modules/Core/Internal/Http/Livewire/SettingsPage.php Modules/Core/Routes/web.php --memory-limit=1G && ! grep -E 'auth\(\)\|Auth::user\(\|\\\\Auth\\\\Facades' Modules/Core/Internal/Http/Livewire/SettingsPage.php >/dev/null && grep -q "Route::get('/settings'" Modules/Core/Routes/web.php` | ⬜ pending |
| 03-04-T3 | 03-04 | 3 | MC-02 | SettingsPageTest round-trip + validation | `vendor/bin/pest Modules/Core/tests/Feature/SettingsPageTest.php --stop-on-failure && [ "$(grep -c 'toBe(false' Modules/Core/tests/Feature/SettingsPageTest.php)" = "0" ]` | ⬜ pending |
| 03-05-T1 | 03-05 | 4 | UI-06 / MC-02 | TransactionRowDto + TransactionListQuery secondaryAmount | `vendor/bin/pest --filter='TransactionListQuery\|secondaryAmount' --stop-on-failure && vendor/bin/phpstan analyse Modules/Ledger/Public/Dto/TransactionRowDto.php Modules/Ledger/Public/Services/TransactionListQuery.php --memory-limit=1G` | ⬜ pending |
| 03-05-T2 | 03-05 | 4 | MC-02 / UI-06 | TransactionsList #[Url] + Blade segmented + dual-line stack | `vendor/bin/pest Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php --stop-on-failure && [ "$(grep -c 'toBe(false' Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php)" = "0" ]` | ⬜ pending |
| 03-06-T1 | 03-06 | 5 | MC-02 | ThisPeriodAtAGlanceQuery::forByCurrency + PerCurrencyTile + DashboardCurrencyModeTest | `vendor/bin/pest Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php --stop-on-failure && [ "$(grep -c 'toBe(false' Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php)" = "0" ]` | ⬜ pending |
| 03-06-T2 | 03-06 | 5 | MC-02 / UI-06 | Money formatter locale-aware default | `vendor/bin/pest --filter='MoneyFormat\|Money formatter' --stop-on-failure && vendor/bin/phpstan analyse Modules/Ledger/Public/ValueObjects/Money.php --memory-limit=1G` | ⬜ pending |
| 03-06-T3 | 03-06 | 5 | MC-02 | Dashboard branching + DashboardOriginalModeRenderTest | `vendor/bin/pest Modules/Core/tests/Feature/DashboardOriginalModeRenderTest.php --stop-on-failure && grep -q 'forByCurrency' Modules/Core/Internal/Http/Livewire/Dashboard.php` | ⬜ pending |
| 03-07-T1 | 03-07 | 5 | UI-06 / LED-03 | TransactionDetail SFC + Blade + route + TransactionDetailFxRateTest | `vendor/bin/pest Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php --stop-on-failure && [ "$(grep -c 'toBe(false' Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php)" = "0" ] && vendor/bin/pest --filter='NoFloatMoney' --stop-on-failure && grep -q "Route::get('/transactions/{transactionId}'" Modules/Ledger/Routes/web.php` | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

**Dimension key** (from RESEARCH.md §"Validation Architecture"): correctness (Pest scaffold GREEN), type-safety (Larastan level 10 strict), style (Pint), security (no-facade greps + architecture tests + UserIdColumnArchTest + NoFloatMoneyArchTest), idempotency (IdempotencyContractTest dataset row).

---

## Wave 0 Requirements

- [ ] `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.csv` — anonymised ICS CSV fixture (raw user file → redacted card numbers / cardholder names; preserve dates / amounts / currencies / merchants verbatim per D-32)
- [ ] `Modules/Ingestion/tests/fixtures/ics/ics-sample-1.md` — fixture record (column map, encoding, delimiter, D-34 / D-35 / D-40 dispositions)
- [ ] `Modules/Ingestion/tests/fixtures/ics/anonymize_ics.py` — re-runnable anonymisation script
- [ ] `tests/Pest.php` — registers `phase-3` group covering the six scaffold paths
- [ ] `Modules/Ingestion/tests/Unit/Adapters/Ics/IcsCsvAdapterTest.php` — failing scaffolds for ING-04 + LED-03 (driven GREEN in plan 03-02)
- [ ] `Modules/Import/tests/Feature/IcsCsvImportTest.php` — failing scaffolds for ING-04 end-to-end (four driven GREEN in 03-02; two in 03-03)
- [ ] `Modules/Core/tests/Feature/SettingsPageTest.php` — failing scaffolds for MC-02 settings round-trip (driven GREEN in plan 03-04)
- [ ] `Modules/Ledger/tests/Feature/TransactionsListCurrencyToggleTest.php` — failing scaffolds for D-44 + D-47 (driven GREEN in plan 03-05)
- [ ] `Modules/Ledger/tests/Feature/DashboardCurrencyModeTest.php` — failing scaffolds for D-46 (driven GREEN in plan 03-06)
- [ ] `Modules/Ledger/tests/Feature/TransactionDetailFxRateTest.php` — failing scaffolds for D-48 (driven GREEN in plan 03-07)
- [ ] `tests/Contracts/IdempotencyContractTest.php` — dataset row for `ics-csv` (added in plan 03-02 Task 3)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Calm aesthetic of /settings page matches Linear/Notion feel | UI-05 (cross-cut) | Subjective design review — no automated check for "calm" | Open `/settings` on Herd at `https://diederik.test/settings`; confirm spacing, single accent color, no dense form chrome |
| Two-step wizard picker UX feels natural with PayPal/Google Play headroom | D-33 | Forward-looking UX judgment | Walk through `Upload Statement` wizard; confirm issuer dropdown reads cleanly and the format dropdown only shows the leaf for the chosen issuer |
| Foreign-currency row visual balance in original-currency view | UI-06 | Subjective two-line stack readability | Import the Wave 0 ICS fixture, switch list to "Original", inspect a USD row — primary `$X` line + muted `€Y` line should not visually crowd |
| Per-currency dashboard tile rows visually rhyme with Phase 1 single-row layout | D-46 | Subjective visual rhythm | Open `/` with `default_currency_view='original'`; confirm the EUR row matches Phase 1 chrome exactly and additional currency rows visually rhyme (same card border, same spacing) |
| Effective-rate row on detail page reads as informational, not focal | D-48 | Subjective hierarchy judgment | Open `/transactions/{id}` for a USD transaction; confirm `€0.929 / USD` reads as supporting metadata under the amount stack (never the focal element) |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency <= 90 seconds for the phase-3 group, <= 5 seconds for a 200-row ICS CSV ingestion
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved — 2026-05-13
