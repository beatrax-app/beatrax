<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;

uses(RefreshDatabase::class);

// The "muted" toast dispatched its own `undo`/`undoArg` params, which neither
// the shared trait nor the toast host ever read, so the Undo the user was
// offered called back into nothing.

function driftUndoUser(): User
{
    return User::query()->create([
        'username' => 'drift-undo-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function driftUndoAlert(DatabaseManager $db, User $user): int
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $user->id, 'name' => 'Checking', 'slug' => 'drift-undo-'.$suffix, 'kind' => 'bank',
        'iban' => 'NL00BANK'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id, 'source_format' => 'manual', 'raw_file_path' => '/tmp/drift-undo-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'drift-undo-run-'.$suffix), 'uploaded_at' => '2026-06-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $counterpartyId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id, 'type' => 'merchant', 'slug' => 'acme-'.$suffix, 'display_name' => 'Acme',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $txnId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'drift-undo-'.$suffix),
        'posted_at' => '2026-06-15', 'booked_at' => '2026-06-15 00:00:00', 'value_date' => '2026-06-15',
        'amount_minor' => -2349, 'currency' => 'EUR', 'settled_amount_minor' => -2349, 'settled_currency' => 'EUR',
        'counterparty_id' => $counterpartyId, 'counterparty_normalized' => 'acme', 'counterparty_name' => 'ACME',
        'normalization_version' => 1, 'description' => 'acme', 'type' => 'expense',
        'source_format' => 'manual', 'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    $alert = AnomalyAlert::factory()->create([
        'user_id' => $user->id, 'transaction_id' => $txnId, 'state' => 'open',
        'direction' => 'expense', 'reasons' => ['large'], 'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349, 'currency' => 'EUR', 'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
    ]);

    return (int) $alert->id;
}

it('offers the suppression undo through the seam the toast host reads', function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $user = driftUndoUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $alertId = driftUndoAlert($db, $user);

    Livewire::actingAs($user)->test(DriftPage::class)
        ->call('markAnomalyExpected', $alertId)
        ->assertDispatched('toast', undoAction: 'undoAnomalySuppression', undoPayload: (string) $alertId);
});
