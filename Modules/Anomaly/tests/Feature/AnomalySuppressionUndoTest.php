<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlertAsExpected;
use Modules\Anomaly\Public\Actions\RemoveAnomalySuppressionRule;
use Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/*
 * The undo path (toast) deletes the suppression rule(s) AND re-opens the
 * anomaly via the diverging dismissed->open edge, while the settings
 * Remove deletes the rule only and leaves the anomaly dismissed (D-18).
 */

function undoUser(): User
{
    return User::query()->create([
        'username' => 'undo-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function undoTxn(DatabaseManager $db, int $userId): array
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'undo-asn-'.$suffix, 'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/undo-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'undo-'.$suffix), 'uploaded_at' => '2026-06-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $counterpartyId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => 'acme-'.$suffix, 'display_name' => 'Acme',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $txnId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'undo-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-15', 'booked_at' => '2026-06-15 00:00:00', 'value_date' => '2026-06-15',
        'amount_minor' => -2349, 'currency' => 'EUR', 'settled_amount_minor' => -2349, 'settled_currency' => 'EUR',
        'counterparty_id' => $counterpartyId, 'counterparty_normalized' => 'acme', 'counterparty_name' => 'ACME',
        'normalization_version' => 1, 'description' => 'acme', 'type' => 'expense',
        'source_format' => 'asn-csv', 'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    return [$txnId, $counterpartyId];
}

/**
 * Creates an OPEN alert then dismisses it as expected so a suppression
 * rule with provenance exists. Returns the alert id.
 */
function undoDismissedAlert(User $user): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    [$txnId] = undoTxn($db, (int) $user->id);

    $alert = AnomalyAlert::factory()->create([
        'user_id' => $user->id, 'transaction_id' => $txnId, 'state' => 'open',
        'direction' => 'expense', 'reasons' => ['large'], 'baseline_amount_minor' => -999,
        'latest_amount_minor' => -2349, 'currency' => 'EUR', 'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse('2026-06-15 12:00:00'),
    ]);

    /** @var DismissAnomalyAlertAsExpected $dismiss */
    $dismiss = app(DismissAnomalyAlertAsExpected::class);
    ($dismiss)((int) $alert->id, $user);

    return (int) $alert->id;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = undoUser();
    /** @var DatabaseManager $db */
    $this->db = app(DatabaseManager::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('undo deletes the suppression rule(s) AND re-opens the anomaly (dismissed -> open)', function (): void {
    $alertId = undoDismissedAlert($this->user);

    expect($this->db->connection()->table('anomaly_suppression_rules')
        ->where('source_anomaly_alert_id', $alertId)->count())->toBe(1);

    /** @var RemoveAnomalySuppressionRule $remove */
    $remove = $this->app->make(RemoveAnomalySuppressionRule::class);
    $remove->undoSuppression($alertId, $this->user);

    expect($this->db->connection()->table('anomaly_suppression_rules')
        ->where('source_anomaly_alert_id', $alertId)->count())->toBe(0);

    expect(AnomalyAlert::query()->findOrFail($alertId)->state)->toBe('open');
});

it('settings Remove deletes the rule only and leaves the anomaly dismissed', function (): void {
    $alertId = undoDismissedAlert($this->user);

    $rule = $this->db->connection()->table('anomaly_suppression_rules')
        ->where('source_anomaly_alert_id', $alertId)->first();
    expect($rule)->not->toBeNull();

    /** @var RemoveAnomalySuppressionRule $remove */
    $remove = $this->app->make(RemoveAnomalySuppressionRule::class);
    $remove->removeRule((int) $rule->id, $this->user);

    expect($this->db->connection()->table('anomaly_suppression_rules')
        ->where('id', $rule->id)->exists())->toBeFalse();

    // The anomaly stays dismissed — settings Remove is not an undo.
    expect(AnomalyAlert::query()->findOrFail($alertId)->state)->toBe('dismissed');
});

it('undoSuppression 404s on a cross-user alert', function (): void {
    $alertId = undoDismissedAlert($this->user);
    $other = undoUser();

    /** @var RemoveAnomalySuppressionRule $remove */
    $remove = $this->app->make(RemoveAnomalySuppressionRule::class);

    expect(fn () => $remove->undoSuppression($alertId, $other))
        ->toThrow(NotFoundHttpException::class);

    // Rule + dismissed state untouched.
    expect($this->db->connection()->table('anomaly_suppression_rules')
        ->where('source_anomaly_alert_id', $alertId)->count())->toBe(1);
    expect(AnomalyAlert::query()->findOrFail($alertId)->state)->toBe('dismissed');
});

it('removeRule 404s on a cross-user rule', function (): void {
    $alertId = undoDismissedAlert($this->user);
    $rule = $this->db->connection()->table('anomaly_suppression_rules')
        ->where('source_anomaly_alert_id', $alertId)->first();
    $other = undoUser();

    /** @var RemoveAnomalySuppressionRule $remove */
    $remove = $this->app->make(RemoveAnomalySuppressionRule::class);

    expect(fn () => $remove->removeRule((int) $rule->id, $other))
        ->toThrow(NotFoundHttpException::class);

    expect($this->db->connection()->table('anomaly_suppression_rules')
        ->where('id', $rule->id)->exists())->toBeTrue();
});
