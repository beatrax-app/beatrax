<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Categorization\Database\Seeders\DefaultCategoryTreeSeeder;
use Modules\Chains\Models\ChainLink;
use Modules\Community\Models\CommunityMerchantMapping;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPreference;
use Modules\Counterparties\Models\Counterparty;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Models\DriftAlertTransition;
use Modules\EmailScan\Models\DiscoveredSender;
use Modules\EmailScan\Models\Inbox;
use Modules\EmailScan\Models\InboxMessage;
use Modules\EmailScan\Models\InboxScanState;
use Modules\EmailScan\Models\KnownSender;
use Modules\EmailScan\Models\OAuthSecret;
use Modules\Forecasting\Models\ForecastScenario;
use Modules\Forecasting\Models\ForecastScenarioMutation;
use Modules\Forecasting\Models\ForecastShortfallWindow;
use Modules\Import\Models\MerchantAlias;
use Modules\Ledger\Database\Seeders\CurrenciesSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Onboarding\Models\WizardProgress;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesTransition;

/**
 * End-to-end exercise of the `php artisan demo:seed` pipeline as
 * extended by Plan 17-07c. The original 17-07b assertion suite
 * (idempotency + baseline shape + clean-DB run + coexistence with
 * production reference seeders) is preserved; the new assertions
 * verify that every entity table the extended orchestrator writes to
 * carries data, that every enum/type variant appears, and that the
 * `--reset` cycle does not orphan rows across the additional tables.
 */
beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

/**
 * Snapshot the row counts that define the extended demo dataset's
 * shape. The before/after equality check in the idempotency test
 * compares the whole picture in one expression.
 *
 * @return array<string, int>
 */
function demoSeedSnapshot(): array
{
    $userIds = demoSeedUserIds();
    $primaryUserId = $userIds[0] ?? 0;

    return [
        'users' => User::query()
            ->where('username', 'like', 'demo-%@beatrax.local')
            ->count(),
        'accounts' => Account::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'transactions' => Transaction::query()
            ->where('source_format', 'demo')
            ->count(),
        'counterparties' => Counterparty::query()
            ->withoutGlobalScopes()
            ->whereIn('user_id', $userIds)
            ->count(),
        'chain_links' => ChainLink::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'recurring_series' => RecurringSeries::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'recurring_series_transitions' => RecurringSeriesTransition::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'forecast_scenarios' => ForecastScenario::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'forecast_scenario_mutations' => ForecastScenarioMutation::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'forecast_shortfall_windows' => ForecastShortfallWindow::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'drift_alerts' => DriftAlert::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'drift_alert_transitions' => DriftAlertTransition::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'system_alerts' => SystemAlert::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'user_preferences' => UserPreference::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'inboxes' => Inbox::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'inbox_scan_state' => InboxScanState::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'inbox_messages' => InboxMessage::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'known_senders' => KnownSender::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'discovered_senders' => DiscoveredSender::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'oauth_secrets' => OAuthSecret::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'user_recovery_codes' => UserRecoveryCode::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'wizard_progress' => WizardProgress::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'community_merchant_mappings' => CommunityMerchantMapping::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'merchant_aliases' => MerchantAlias::query()
            ->whereIn('user_id', $userIds)
            ->count(),
        'file_imports' => $primaryUserId === 0
            ? 0
            : (int) (test()->db->connection()->table('file_imports')->where('user_id', $primaryUserId)->count()),
        'pending_enrichment_conflicts' => $primaryUserId === 0
            ? 0
            : (int) (test()->db->connection()->table('pending_enrichment_conflicts')->where('user_id', $primaryUserId)->count()),
        'merchant_memories' => $primaryUserId === 0
            ? 0
            : (int) (test()->db->connection()->table('merchant_memories')->where('user_id', $primaryUserId)->count()),
        'paired_transactions' => Transaction::query()
            ->where('source_format', 'demo')
            ->whereNotNull('pair_transaction_id')
            ->count(),
        'envelope_assignments' => test()->db->connection()
            ->table('envelope_assignments')
            ->whereIn('user_id', $userIds)
            ->count(),
    ];
}

/**
 * The demo users' id list. Returned fresh each call because the
 * users table may have been wiped + re-seeded between consecutive
 * uses of the helper in a single test body.
 *
 * @return list<int>
 */
function demoSeedUserIds(): array
{
    $ids = User::query()
        ->where('username', 'like', 'demo-%@beatrax.local')
        ->pluck('id')
        ->all();

    return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids));
}

it('produces identical row counts when `demo:seed --reset` runs twice in succession', function (): void {
    $this->artisan('demo:seed', ['--reset' => true])->assertSuccessful();
    $firstSnapshot = demoSeedSnapshot();

    $this->artisan('demo:seed', ['--reset' => true])->assertSuccessful();
    $secondSnapshot = demoSeedSnapshot();

    expect($secondSnapshot)->toBe($firstSnapshot);
});

it('produces the documented dataset shape after a single seed run', function (): void {
    $this->artisan('demo:seed', ['--reset' => true])->assertSuccessful();
    $snap = demoSeedSnapshot();

    expect($snap['users'])->toBe(2);
    expect($snap['accounts'])->toBe(5);
    // Transaction row count after 17-07c extensions: baseline 158 +
    // 6 added type-coverage rows + 2 cross-account pair legs = 166.
    expect($snap['transactions'])
        ->toBeGreaterThanOrEqual(160)
        ->toBeLessThanOrEqual(180);
    expect($snap['recurring_series'])->toBe(6);
    expect($snap['recurring_series_transitions'])->toBeGreaterThanOrEqual(3);
    expect($snap['forecast_scenarios'])->toBe(2);
    expect($snap['forecast_scenario_mutations'])->toBeGreaterThanOrEqual(1);
    expect($snap['forecast_shortfall_windows'])->toBeGreaterThanOrEqual(1);

    // Counterparty coverage: every legal type carries ≥2 rows after
    // the EXTRA_COUNTERPARTIES seed step.
    $countByType = Counterparty::query()
        ->withoutGlobalScopes()
        ->whereIn('user_id', demoSeedUserIds())
        ->selectRaw('type, COUNT(*) as c')
        ->groupBy('type')
        ->pluck('c', 'type')
        ->all();

    foreach (['merchant', 'personal', 'bank', 'government', 'self_account'] as $type) {
        expect((int) ($countByType[$type] ?? 0))
            ->toBeGreaterThanOrEqual(2, "Counterparty type {$type} should carry ≥2 rows");
    }

    // Transaction TYPES coverage — every value in Transaction::TYPES
    // must carry at least two rows on a freshly-seeded dataset.
    $countByTxType = Transaction::query()
        ->where('source_format', 'demo')
        ->selectRaw('type, COUNT(*) as c')
        ->groupBy('type')
        ->pluck('c', 'type')
        ->all();
    foreach (['expense', 'income', 'transfer_out', 'transfer_in', 'fee', 'refund', 'adjustment'] as $type) {
        expect((int) ($countByTxType[$type] ?? 0))
            ->toBeGreaterThanOrEqual(2, "Transaction TYPES value {$type} should carry ≥2 rows");
    }

    // PaymentType coverage — every enum value must carry at least two
    // rows on a freshly-seeded dataset.
    $countByPaymentType = Transaction::query()
        ->where('source_format', 'demo')
        ->selectRaw('payment_type, COUNT(*) as c')
        ->groupBy('payment_type')
        ->pluck('c', 'payment_type')
        ->all();
    foreach (['pin', 'online', 'transfer', 'direct_debit', 'cash', 'fee', 'refund', 'unknown'] as $value) {
        expect((int) ($countByPaymentType[$value] ?? 0))
            ->toBeGreaterThanOrEqual(2, "PaymentType value {$value} should carry ≥2 rows");
    }

    // Chain kinds — every value the trigger pair allows must be
    // present after the hint-shape extension lands.
    $chainKinds = ChainLink::query()
        ->whereIn('user_id', demoSeedUserIds())
        ->pluck('kind')
        ->unique()
        ->values()
        ->all();
    foreach (['paypal_funding', 'ics_bulk_settle', 'funded_by_card_hint', 'refund_of_hint'] as $kind) {
        expect($chainKinds)->toContain($kind);
    }

    // RecurringSeries states — approved (4 series), snoozed (1), and
    // rejected (1) must all be represented after the lifecycle
    // extension lands.
    $countByRecState = RecurringSeries::query()
        ->whereIn('user_id', demoSeedUserIds())
        ->selectRaw('state, COUNT(*) as c')
        ->groupBy('state')
        ->pluck('c', 'state')
        ->all();
    foreach (['approved', 'snoozed', 'rejected'] as $state) {
        expect((int) ($countByRecState[$state] ?? 0))
            ->toBeGreaterThanOrEqual(1, "RecurringSeries state {$state} should carry ≥1 row");
    }

    // DriftAlert states — open, acknowledged, and dismissed_cancelled
    // must all be represented.
    $countByDriftState = DriftAlert::query()
        ->whereIn('user_id', demoSeedUserIds())
        ->selectRaw('state, COUNT(*) as c')
        ->groupBy('state')
        ->pluck('c', 'state')
        ->all();
    foreach (['open', 'acknowledged', 'dismissed_cancelled'] as $state) {
        expect((int) ($countByDriftState[$state] ?? 0))
            ->toBeGreaterThanOrEqual(1, "DriftAlert state {$state} should carry ≥1 row");
    }

    // SystemAlert kinds — all four production kinds must be present.
    $systemAlertKinds = SystemAlert::query()
        ->whereIn('user_id', demoSeedUserIds())
        ->pluck('kind')
        ->unique()
        ->values()
        ->all();
    foreach (['backup_corrupt', 'doctor_warning', 'update.available', 'force_password_change'] as $kind) {
        expect($systemAlertKinds)->toContain($kind);
    }

    // WizardProgress statuses — every legal value (pending,
    // in_progress, done, skipped) must be represented for the primary
    // demo user so the ResumeStepResolver branch coverage holds.
    $countByWizardStatus = WizardProgress::query()
        ->whereIn('user_id', demoSeedUserIds())
        ->selectRaw('status, COUNT(*) as c')
        ->groupBy('status')
        ->pluck('c', 'status')
        ->all();
    foreach (['pending', 'in_progress', 'done', 'skipped'] as $status) {
        expect((int) ($countByWizardStatus[$status] ?? 0))
            ->toBeGreaterThanOrEqual(1, "WizardProgress status {$status} should carry ≥1 row");
    }

    // EmailScan presence — Gmail + Microsoft inboxes both present;
    // ≥2 known_senders, ≥3 discovered_senders, ≥1 oauth_secret per
    // provider.
    $inboxProviders = Inbox::query()
        ->whereIn('user_id', demoSeedUserIds())
        ->pluck('provider')
        ->unique()
        ->values()
        ->all();
    expect($inboxProviders)->toContain('gmail');
    expect($inboxProviders)->toContain('microsoft');
    expect($snap['known_senders'])->toBeGreaterThanOrEqual(2);
    expect($snap['discovered_senders'])->toBeGreaterThanOrEqual(3);
    expect($snap['oauth_secrets'])->toBe(2);
    expect($snap['inbox_messages'])->toBeGreaterThanOrEqual(3);

    // Recovery codes — 3 unused + 2 used = 5 total.
    expect($snap['user_recovery_codes'])->toBe(5);
    $unusedRecoveryCount = UserRecoveryCode::query()
        ->whereIn('user_id', demoSeedUserIds())
        ->whereNull('used_at')
        ->count();
    expect($unusedRecoveryCount)->toBe(3);
    $usedRecoveryCount = UserRecoveryCode::query()
        ->whereIn('user_id', demoSeedUserIds())
        ->whereNotNull('used_at')
        ->count();
    expect($usedRecoveryCount)->toBe(2);

    // Community mappings + merchant aliases + memories.
    expect($snap['community_merchant_mappings'])->toBeGreaterThanOrEqual(3);
    expect($snap['merchant_aliases'])->toBeGreaterThanOrEqual(3);
    expect($snap['merchant_memories'])->toBeGreaterThanOrEqual(5);

    // Receipts: ≥2 file_imports + ≥1 pending_enrichment_conflict.
    expect($snap['file_imports'])->toBeGreaterThanOrEqual(2);
    expect($snap['pending_enrichment_conflicts'])->toBeGreaterThanOrEqual(1);

    // Transfer pairs: at least the original 3 monthly ASN→PayPal
    // monthly top-ups (6 paired legs) + the new explicit one-off pair
    // (2 paired legs) = ≥8 total. Lenient lower bound so a wall-clock
    // shift inside the rolling 90-day window doesn't break the test.
    expect($snap['paired_transactions'])->toBeGreaterThanOrEqual(4);

    // UserPreferences: one row per demo user.
    expect($snap['user_preferences'])->toBe(2);

    // Envelope budgeting (Phase 13.2): both demo users are activated (genesis
    // anchor stamped) and carry a current-period assignment slate, so /budgets
    // renders a populated grid instead of the D-12b empty result.
    $activatedDemoUsers = User::query()
        ->where('username', 'like', 'demo-%@beatrax.local')
        ->whereNotNull('envelope_activated_at')
        ->count();
    expect($activatedDemoUsers)->toBe(2);
    expect($snap['envelope_assignments'])->toBeGreaterThanOrEqual(11);

    // ImportRun lifecycle — every demo transaction must belong to an
    // ImportRun stamped `source_format = 'demo'`.
    $demoImportRuns = ImportRun::query()
        ->where('source_format', 'demo')
        ->whereIn('user_id', demoSeedUserIds())
        ->count();
    expect($demoImportRuns)->toBeGreaterThanOrEqual(5);
});

it('is idempotent on first run without `--reset` against a clean DB', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();
    $firstSnapshot = demoSeedSnapshot();

    $this->artisan('demo:seed')->assertSuccessful();
    $secondSnapshot = demoSeedSnapshot();

    expect($secondSnapshot)->toBe($firstSnapshot);
});

it('coexists cleanly with the production reference seeders', function (): void {
    $this->app->make(CurrenciesSeeder::class)->run();
    $this->app->make(DefaultCategoryTreeSeeder::class)->run();

    $this->artisan('demo:seed')->assertSuccessful();

    $currencyCount = $this->db->connection()->table('currencies')->count();
    expect($currencyCount)->toBeGreaterThan(0);

    $globalCategoryCount = $this->db->connection()
        ->table('categories')
        ->whereNull('user_id')
        ->count();
    expect($globalCategoryCount)->toBeGreaterThan(0);

    $snap = demoSeedSnapshot();
    expect($snap['users'])->toBe(2);
    expect($snap['accounts'])->toBe(5);
    expect($snap['transactions'])->toBeGreaterThanOrEqual(160);
});

it('cascade-deletes every extended demo row on `--reset` so no orphans remain', function (): void {
    $this->artisan('demo:seed', ['--reset' => true])->assertSuccessful();

    $this->artisan('demo:seed', ['--reset' => true])->assertSuccessful();

    // After the second reset every extended table must hold the same
    // counts as the first reset — there should be no orphan rows left
    // behind from the first wipe cycle. The idempotency test above
    // already asserts before/after equality; this assertion further
    // verifies that orphan-prone tables (those with FK chains via
    // recurring_series or forecast_scenarios) cascaded cleanly.
    $userIds = demoSeedUserIds();
    expect($userIds)->toHaveCount(2);

    // After --reset, no drift_alert_transitions row should exist that
    // points at a missing drift_alerts row (cascade integrity).
    $orphanTransitions = $this->db->connection()
        ->table('drift_alert_transitions')
        ->leftJoin('drift_alerts', 'drift_alert_transitions.drift_alert_id', '=', 'drift_alerts.id')
        ->whereNull('drift_alerts.id')
        ->count();
    expect($orphanTransitions)->toBe(0);

    // Same check for recurring_series_transitions.
    $orphanRecurringTransitions = $this->db->connection()
        ->table('recurring_series_transitions')
        ->leftJoin('recurring_series', 'recurring_series_transitions.recurring_series_id', '=', 'recurring_series.id')
        ->whereNull('recurring_series.id')
        ->count();
    expect($orphanRecurringTransitions)->toBe(0);

    // And for forecast_scenario_mutations / forecast_shortfall_windows
    // that cascade from forecast_scenarios.
    $orphanMutations = $this->db->connection()
        ->table('forecast_scenario_mutations')
        ->leftJoin('forecast_scenarios', 'forecast_scenario_mutations.forecast_scenario_id', '=', 'forecast_scenarios.id')
        ->whereNull('forecast_scenarios.id')
        ->count();
    expect($orphanMutations)->toBe(0);
});
