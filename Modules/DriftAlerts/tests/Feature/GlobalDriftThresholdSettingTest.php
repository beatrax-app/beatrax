<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\DriftEvaluator;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\Ledger\Models\Currency;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // SettingsPage::save() validates baseCurrency against
    // `exists:currencies,code`; seed EUR so the default passes.
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);
});

/*
 * /settings global drift threshold field tests. Persists to
 * users.drift_alert_threshold_percent; the value propagates to
 * DriftEvaluator's effective-threshold resolution on subsequent
 * detection sweeps.
 */

function gdtUser(string $username, int $threshold = 5): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'drift_alert_threshold_percent' => $threshold,
    ]);
}

it('renders /settings with the current threshold pre-selected from users.drift_alert_threshold_percent', function (): void {
    $user = gdtUser('gdt-10', threshold: 10);

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->assertSet('driftAlertThresholdPercent', 10)
        ->assertSee('Default drift alert threshold');
});

it('saves a new threshold via Livewire and persists to the users row', function (): void {
    $user = gdtUser('gdt-save', threshold: 5);

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->set('driftAlertThresholdPercent', 25)
        ->call('save')
        ->assertSet('saved', true);

    $fresh = User::query()->findOrFail($user->id);
    expect($fresh->drift_alert_threshold_percent)->toBe(25);
});

it('rejects an out-of-whitelist threshold value with a validation error and leaves the DB row untouched', function (): void {
    $user = gdtUser('gdt-invalid', threshold: 5);

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->set('driftAlertThresholdPercent', 7)
        ->call('save')
        ->assertHasErrors(['driftAlertThresholdPercent']);

    $fresh = User::query()->findOrFail($user->id);
    expect($fresh->drift_alert_threshold_percent)->toBe(5);
});

it('persists 1 as the lowest valid threshold', function (): void {
    $user = gdtUser('gdt-low', threshold: 5);

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->set('driftAlertThresholdPercent', 1)
        ->call('save')
        ->assertHasNoErrors();

    $fresh = User::query()->findOrFail($user->id);
    expect($fresh->drift_alert_threshold_percent)->toBe(1);
});

it('persists 50 as the highest valid threshold', function (): void {
    $user = gdtUser('gdt-high', threshold: 5);

    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->set('driftAlertThresholdPercent', 50)
        ->call('save')
        ->assertHasNoErrors();

    $fresh = User::query()->findOrFail($user->id);
    expect($fresh->drift_alert_threshold_percent)->toBe(50);
});

it('changing the global threshold without a per-series override changes subsequent drift detection behaviour', function (): void {
    // The integration: with threshold=10, a 7% drift produces no alert;
    // with threshold=5, the same 7% drift produces an alert.
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user = gdtUser('gdt-integration', threshold: 10);
    $suffix = bin2hex(random_bytes(4));

    // Seed an approved series with NULL per-series override + 7% drift
    // (1000 -> 1070).
    $seriesId = $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'GDT series',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1070,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1070,
        'variance_tolerance_percent' => 25,
        'drift_threshold_percent' => null,
        'cluster_key' => 'gdt::'.$suffix,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'ASN GDT',
        'slug' => 'gdt-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00GDTBANK'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/gdt-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'gdt-run-'.$suffix),
        'uploaded_at' => '2026-05-19 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ]);

    $seedOccurrence = function (string $date, int $amount) use ($db, $user, $accountId, $runId, $seriesId, $suffix): void {
        static $counter = 0;
        $counter++;
        $txId = $db->connection()->table('transactions')->insertGetId([
            'user_id' => $user->id,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'fingerprint' => hash('sha256', 'gdt-tx-'.$suffix.'-'.$counter),
            'posted_at' => $date,
            'booked_at' => $date.' 00:00:00',
            'value_date' => $date,
            'amount_minor' => $amount,
            'currency' => 'EUR',
            'settled_amount_minor' => $amount,
            'settled_currency' => 'EUR',
            'counterparty_normalized' => 'gdt-merchant',
            'counterparty_name' => 'GDT MERCHANT',
            'normalization_version' => 1,
            'description' => 'gdt fixture',
            'type' => 'expense',
            'source_format' => 'asn-csv',
            'source_row_index' => $counter,
            'fingerprint_version' => 3,
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);

        $db->connection()->table('recurring_series_occurrences')->insert([
            'user_id' => $user->id,
            'recurring_series_id' => $seriesId,
            'transaction_id' => $txId,
            'observed_at' => $date,
            'observed_amount_minor' => $amount,
            'observed_currency' => 'EUR',
            'created_at' => '2026-05-19 00:00:00',
            'updated_at' => '2026-05-19 00:00:00',
        ]);
    };

    // Two occurrences: 1000 cents (Apr) → 1070 cents (May). 7% drift.
    $seedOccurrence('2026-04-15', -1000);
    $seedOccurrence('2026-05-15', -1070);

    /** @var DriftEvaluator $evaluator */
    $evaluator = app(DriftEvaluator::class);

    // With user global threshold = 10, the 7% drift is below — no alert.
    $evaluator->evaluateForSeries($seriesId, $user->refresh());
    expect(DriftAlert::query()->where('user_id', $user->id)->count())->toBe(0);

    // Lower the user-global threshold to 5 via Livewire and re-run.
    Livewire::actingAs($user)
        ->test(SettingsPage::class)
        ->set('driftAlertThresholdPercent', 5)
        ->call('save')
        ->assertHasNoErrors();

    $evaluator->evaluateForSeries($seriesId, $user->fresh());
    expect(DriftAlert::query()->where('user_id', $user->id)->count())->toBe(1);

    CarbonImmutable::setTestNow();
});
