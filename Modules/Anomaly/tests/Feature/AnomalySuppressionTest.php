<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlertAsExpected;
use Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function supUser(): User
{
    return User::query()->create([
        'username' => 'sup-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ]);
}

/**
 * @return array{account: int, run: int, counterparty: int}
 */
function supScaffold(DatabaseManager $db, int $userId): array
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'sup-asn-'.$suffix, 'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/sup-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'sup-'.$suffix), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $counterpartyId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => 'acme-'.$suffix, 'display_name' => 'Acme',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    return ['account' => $accountId, 'run' => $runId, 'counterparty' => $counterpartyId];
}

function supTxn(DatabaseManager $db, int $userId, array $s, int $settledMinor, string $postedAt): int
{
    static $i = 0;
    $i++;

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $s['account'], 'import_run_id' => $s['run'],
        'fingerprint' => hash('sha256', 'sup-'.$i.bin2hex(random_bytes(8))),
        'posted_at' => $postedAt, 'booked_at' => $postedAt.' 00:00:00', 'value_date' => $postedAt,
        'amount_minor' => $settledMinor, 'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor, 'settled_currency' => 'EUR',
        'counterparty_id' => $s['counterparty'], 'counterparty_normalized' => 'acme', 'counterparty_name' => 'ACME',
        'normalization_version' => 1, 'description' => 'acme charge', 'type' => 'expense',
        'source_format' => 'asn-csv', 'source_row_index' => $i, 'fingerprint_version' => 3,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    $this->user = supUser();
    /** @var DatabaseManager $db */
    $this->db = app(DatabaseManager::class);
    $this->s = supScaffold($this->db, (int) $this->user->id);

    // Five months of ~€10 charges establish the typical band.
    foreach (['2026-01-10', '2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10'] as $i => $date) {
        supTxn($this->db, (int) $this->user->id, $this->s, -999, $date);
    }
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('creates a ±15% suppression rule per reason with provenance, then skips in-band and fires above-band', function (): void {
    $largeTxn = supTxn($this->db, (int) $this->user->id, $this->s, -2349, '2026-06-15');

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($largeTxn, $this->user);

    $alert = AnomalyAlert::query()->where('transaction_id', $largeTxn)->firstOrFail();
    expect($alert->reasons)->toContain('large');

    /** @var DismissAnomalyAlertAsExpected $dismiss */
    $dismiss = $this->app->make(DismissAnomalyAlertAsExpected::class);
    ($dismiss)($alert->id, $this->user);

    $rule = $this->db->connection()->table('anomaly_suppression_rules')
        ->where('user_id', $this->user->id)
        ->where('detector', 'large')
        ->first();

    expect($rule)->not->toBeNull()
        ->and((int) $rule->amount_band_low_minor)->toBe((int) round(1.15 * -2349))   // -2701 (more negative)
        ->and((int) $rule->amount_band_high_minor)->toBe((int) round(0.85 * -2349))  // -1997 (less negative)
        ->and((int) $rule->source_anomaly_alert_id)->toBe((int) $alert->id)
        ->and($rule->currency)->toBe('EUR');

    $inBandTxn = supTxn($this->db, (int) $this->user->id, $this->s, -2349, '2026-07-15');
    $evaluator->evaluate($inBandTxn, $this->user);
    expect(AnomalyAlert::query()->where('transaction_id', $inBandTxn)->exists())->toBeFalse();

    $aboveBandTxn = supTxn($this->db, (int) $this->user->id, $this->s, -4000, '2026-08-15');
    $evaluator->evaluate($aboveBandTxn, $this->user);
    $aboveAlert = AnomalyAlert::query()->where('transaction_id', $aboveBandTxn)->first();
    expect($aboveAlert)->not->toBeNull()
        ->and($aboveAlert->reasons)->toContain('large');
});

it('does not accumulate a duplicate rule when an identical natural-key rule already exists', function (): void {
    $largeTxn = supTxn($this->db, (int) $this->user->id, $this->s, -2349, '2026-06-15');
    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($largeTxn, $this->user);
    $alert = AnomalyAlert::query()->where('transaction_id', $largeTxn)->firstOrFail();

    $cpId = (int) $this->db->connection()->table('transactions')->where('id', $largeTxn)->value('counterparty_id');

    // Same natural key the dismissal would write: a partial undo where the
    // rule survived, the alert re-opened, and the user dismisses again.
    $this->db->connection()->table('anomaly_suppression_rules')->insert([
        'user_id' => $this->user->id, 'counterparty_id' => $cpId, 'detector' => 'large', 'direction' => 'expense',
        'amount_band_low_minor' => (int) round(1.15 * -2349), 'amount_band_high_minor' => (int) round(0.85 * -2349),
        'currency' => 'EUR', 'source_anomaly_alert_id' => null,
        'created_at' => '2026-06-19 00:00:00', 'updated_at' => '2026-06-19 00:00:00',
    ]);

    /** @var DismissAnomalyAlertAsExpected $dismiss */
    $dismiss = $this->app->make(DismissAnomalyAlertAsExpected::class);
    $wrote = ($dismiss)($alert->id, $this->user);

    expect($wrote)->toBeFalse()
        ->and(
            (int) $this->db->connection()->table('anomaly_suppression_rules')
                ->where('user_id', $this->user->id)
                ->where('detector', 'large')
                ->count()
        )->toBe(1);
});

it('marks the dismissed alert as expected and is cross-user guarded', function (): void {
    $largeTxn = supTxn($this->db, (int) $this->user->id, $this->s, -2349, '2026-06-15');
    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($largeTxn, $this->user);
    $alert = AnomalyAlert::query()->where('transaction_id', $largeTxn)->firstOrFail();

    /** @var DismissAnomalyAlertAsExpected $dismiss */
    $dismiss = $this->app->make(DismissAnomalyAlertAsExpected::class);

    $other = supUser();
    expect(fn () => ($dismiss)($alert->id, $other))
        ->toThrow(NotFoundHttpException::class);

    ($dismiss)($alert->id, $this->user);
    $fresh = AnomalyAlert::query()->findOrFail($alert->id);
    expect($fresh->state)->toBe('dismissed')
        ->and($fresh->dismissed_as)->toBe('expected');
});
