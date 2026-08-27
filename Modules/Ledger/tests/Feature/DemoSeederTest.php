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

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

/**
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

// Re-queried on every call: a single test body wipes and re-seeds the users
// table between two uses of this helper.
/**
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
    // 158 baseline rows + 6 type-coverage rows + 2 cross-account pair legs.
    expect($snap['transactions'])
        ->toBeGreaterThanOrEqual(160)
        ->toBeLessThanOrEqual(180);
    expect($snap['recurring_series'])->toBe(6);
    expect($snap['recurring_series_transitions'])->toBeGreaterThanOrEqual(3);
    expect($snap['forecast_scenarios'])->toBe(2);
    expect($snap['forecast_scenario_mutations'])->toBeGreaterThanOrEqual(1);
    expect($snap['forecast_shortfall_windows'])->toBeGreaterThanOrEqual(1);

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

    // The full set of kinds the chain-link trigger pair admits.
    $chainKinds = ChainLink::query()
        ->whereIn('user_id', demoSeedUserIds())
        ->pluck('kind')
        ->unique()
        ->values()
        ->all();
    foreach (['paypal_funding', 'ics_bulk_settle', 'funded_by_card_hint', 'refund_of_hint'] as $kind) {
        expect($chainKinds)->toContain($kind);
    }

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

    $systemAlertKinds = SystemAlert::query()
        ->whereIn('user_id', demoSeedUserIds())
        ->pluck('kind')
        ->unique()
        ->values()
        ->all();
    // Kinds the app can actually raise. `doctor_warning` and
    // `force_password_change` were neither: nothing writes them, so the banner
    // had no case for them and printed their English column on a Dutch screen.
    foreach (['backup_corrupt', 'wal_mode_missing', 'update.available', 'auth.recovery_code_failed'] as $kind) {
        expect($systemAlertKinds)->toContain($kind);
    }

    // Every status must appear or the ResumeStepResolver branches go uncovered.
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

    expect($snap['community_merchant_mappings'])->toBeGreaterThanOrEqual(3);
    expect($snap['merchant_aliases'])->toBeGreaterThanOrEqual(3);
    expect($snap['merchant_memories'])->toBeGreaterThanOrEqual(5);

    expect($snap['file_imports'])->toBeGreaterThanOrEqual(2);
    expect($snap['pending_enrichment_conflicts'])->toBeGreaterThanOrEqual(1);

    // The seed lays down 8 paired legs; the bound is 4 so a wall-clock shift
    // inside the rolling 90-day window cannot break the test.
    expect($snap['paired_transactions'])->toBeGreaterThanOrEqual(4);

    expect($snap['user_preferences'])->toBe(2);

    // Both demo users need the activation anchor and a current-period slate,
    // or /budgets renders an empty grid.
    $activatedDemoUsers = User::query()
        ->where('username', 'like', 'demo-%@beatrax.local')
        ->whereNotNull('envelope_activated_at')
        ->count();
    expect($activatedDemoUsers)->toBe(2);
    expect($snap['envelope_assignments'])->toBeGreaterThanOrEqual(11);

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

    $userIds = demoSeedUserIds();
    expect($userIds)->toHaveCount(2);

    $orphanTransitions = $this->db->connection()
        ->table('drift_alert_transitions')
        ->leftJoin('drift_alerts', 'drift_alert_transitions.drift_alert_id', '=', 'drift_alerts.id')
        ->whereNull('drift_alerts.id')
        ->count();
    expect($orphanTransitions)->toBe(0);

    $orphanRecurringTransitions = $this->db->connection()
        ->table('recurring_series_transitions')
        ->leftJoin('recurring_series', 'recurring_series_transitions.recurring_series_id', '=', 'recurring_series.id')
        ->whereNull('recurring_series.id')
        ->count();
    expect($orphanRecurringTransitions)->toBe(0);

    $orphanMutations = $this->db->connection()
        ->table('forecast_scenario_mutations')
        ->leftJoin('forecast_scenarios', 'forecast_scenario_mutations.forecast_scenario_id', '=', 'forecast_scenarios.id')
        ->whereNull('forecast_scenarios.id')
        ->count();
    expect($orphanMutations)->toBe(0);
});
