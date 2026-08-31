<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\Enums\AnomalyDetector;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlertAsExpected;
use Modules\Anomaly\Public\Actions\RemoveAnomalySuppressionRule;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// One merchant, two charges, one band: the pair the dedup collapses onto a
// single rule.
function bandUser(): User
{
    return User::query()->create([
        'username' => 'band-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function bandMerchant(DatabaseManager $db, int $userId): array
{
    $suffix = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'band-asn-'.$suffix, 'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/band-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'band-'.$suffix), 'uploaded_at' => '2026-06-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);
    $counterpartyId = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId, 'type' => 'merchant', 'slug' => 'acme-'.$suffix, 'display_name' => 'Acme',
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    return [$accountId, $runId, $counterpartyId];
}

function bandAlert(DatabaseManager $db, User $user, array $merchant, string $postedAt): AnomalyAlert
{
    [$accountId, $runId, $counterpartyId] = $merchant;

    $txnId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'band-'.bin2hex(random_bytes(8))),
        'posted_at' => $postedAt, 'booked_at' => $postedAt.' 00:00:00', 'value_date' => $postedAt,
        'amount_minor' => -2349, 'currency' => 'EUR', 'settled_amount_minor' => -2349, 'settled_currency' => 'EUR',
        'counterparty_id' => $counterpartyId, 'counterparty_normalized' => 'acme', 'counterparty_name' => 'ACME',
        'normalization_version' => 1, 'description' => 'acme', 'type' => 'expense',
        'source_format' => 'asn-csv', 'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
    ]);

    return AnomalyAlert::factory()->create([
        'user_id' => $user->id, 'transaction_id' => $txnId, 'state' => 'open',
        'direction' => 'expense', 'reasons' => [AnomalyDetector::Large->value],
        'baseline_amount_minor' => -999, 'latest_amount_minor' => -2349,
        'currency' => 'EUR', 'sensitivity_percent_used' => 50,
        'detected_at' => CarbonImmutable::parse($postedAt.' 12:00:00'),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-20 09:00:00');
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->user = bandUser();
    $this->merchant = bandMerchant($this->db, (int) $this->user->id);

    $this->first = bandAlert($this->db, $this->user, $this->merchant, '2026-06-14');
    $this->second = bandAlert($this->db, $this->user, $this->merchant, '2026-06-15');

    /** @var DismissAnomalyAlertAsExpected $dismiss */
    $dismiss = app(DismissAnomalyAlertAsExpected::class);
    $this->dismiss = $dismiss;
    /** @var RemoveAnomalySuppressionRule $remove */
    $remove = app(RemoveAnomalySuppressionRule::class);
    $this->remove = $remove;
});

afterEach(fn () => CarbonImmutable::setTestNow());

function bandRuleCount(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('anomaly_suppression_rules')->where('user_id', $user->id)->count();
}

it('collapses two dismissals in one band onto a single rule, naming the first alert', function (): void {
    ($this->dismiss)((int) $this->first->id, $this->user);
    ($this->dismiss)((int) $this->second->id, $this->user);

    $rules = $this->db->connection()->table('anomaly_suppression_rules')
        ->where('user_id', $this->user->id)->get();

    expect($rules)->toHaveCount(1)
        ->and((int) $rules[0]->source_anomaly_alert_id)->toBe((int) $this->first->id);
});

it('un-mutes the merchant when the SECOND dismissal is undone, not just the first', function (): void {
    ($this->dismiss)((int) $this->first->id, $this->user);
    ($this->dismiss)((int) $this->second->id, $this->user);

    $this->remove->undoSuppression((int) $this->second->id, $this->user);

    expect(bandRuleCount($this->db, $this->user))->toBe(0)
        ->and(AnomalyAlert::query()->findOrFail($this->second->id)->state)->toBe('open');
});

it('un-mutes the merchant when the FIRST dismissal is undone as well', function (): void {
    ($this->dismiss)((int) $this->first->id, $this->user);
    ($this->dismiss)((int) $this->second->id, $this->user);

    $this->remove->undoSuppression((int) $this->first->id, $this->user);

    expect(bandRuleCount($this->db, $this->user))->toBe(0)
        ->and(AnomalyAlert::query()->findOrFail($this->first->id)->state)->toBe('open');
});

// A rule for a different merchant shares nothing with this alert's band, so
// undoing here must not reach across and un-mute it.
it('leaves a rule outside the undone alert band alone', function (): void {
    ($this->dismiss)((int) $this->first->id, $this->user);

    $other = bandMerchant($this->db, (int) $this->user->id);
    $otherAlert = bandAlert($this->db, $this->user, $other, '2026-06-16');
    ($this->dismiss)((int) $otherAlert->id, $this->user);

    expect(bandRuleCount($this->db, $this->user))->toBe(2);

    $this->remove->undoSuppression((int) $this->second->id, $this->user);

    $survivor = $this->db->connection()->table('anomaly_suppression_rules')
        ->where('user_id', $this->user->id)->get();

    expect($survivor)->toHaveCount(1)
        ->and((int) $survivor[0]->source_anomaly_alert_id)->toBe((int) $otherAlert->id);
});
