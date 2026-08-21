<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlertAsExpected;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

// A duplicate-only alert carries no latest_amount_minor, so the band has to
// fall back to the transaction's own settled amount.
beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('writes a fallback band for a duplicate-only alert and returns true (CR-01)', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('duplicate-in-window');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    $alert = AnomalyAlert::query()->where('transaction_id', $txnId)->firstOrFail();
    expect($alert->reasons)->toBe(['duplicate'])
        ->and($alert->latest_amount_minor)->toBeNull();

    /** @var DismissAnomalyAlertAsExpected $dismiss */
    $dismiss = $this->app->make(DismissAnomalyAlertAsExpected::class);
    $ruleWritten = ($dismiss)($alert->id, $user);

    expect($ruleWritten)->toBeTrue();

    $rule = $this->db->connection()->table('anomaly_suppression_rules')
        ->where('user_id', $user->id)
        ->where('detector', 'duplicate')
        ->first();

    expect($rule)->not->toBeNull()
        ->and((int) $rule->amount_band_low_minor)->toBe((int) round(1.15 * -4999))
        ->and((int) $rule->amount_band_high_minor)->toBe((int) round(0.85 * -4999))
        ->and($rule->currency)->toBe('EUR')
        ->and((int) $rule->source_anomaly_alert_id)->toBe((int) $alert->id);
});

it('suppresses the next identical duplicate after a duplicate-only dismissal (CR-01 closes the D-17 gap)', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('duplicate-in-window');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);
    $alert = AnomalyAlert::query()->where('transaction_id', $txnId)->firstOrFail();

    /** @var DismissAnomalyAlertAsExpected $dismiss */
    $dismiss = $this->app->make(DismissAnomalyAlertAsExpected::class);
    ($dismiss)($alert->id, $user);

    // A third identical charge inside the window: without the fallback band
    // there would be no rule and this would re-fire.
    $cpId = (int) $this->db->connection()->table('transactions')->where('id', $txnId)->value('counterparty_id');
    $accountId = (int) $this->db->connection()->table('transactions')->where('id', $txnId)->value('account_id');
    $runId = (int) $this->db->connection()->table('transactions')->where('id', $txnId)->value('import_run_id');

    $thirdId = $this->db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'cr01-third-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-06-18', 'booked_at' => '2026-06-18 00:00:00', 'value_date' => '2026-06-18',
        'amount_minor' => -4999, 'currency' => 'EUR', 'settled_amount_minor' => -4999, 'settled_currency' => 'EUR',
        'counterparty_id' => $cpId, 'counterparty_normalized' => 'coolblue', 'counterparty_name' => 'COOLBLUE',
        'normalization_version' => 1, 'description' => 'coolblue re-charge', 'type' => 'expense',
        'source_format' => 'asn-csv', 'source_row_index' => 99, 'fingerprint_version' => 3,
        'created_at' => '2026-06-18 00:00:00', 'updated_at' => '2026-06-18 00:00:00',
    ]);

    $evaluator->evaluate($thirdId, $user);

    expect(AnomalyAlert::query()->where('transaction_id', $thirdId)->exists())->toBeFalse();
});

it('falls back to the base currency when the charge row carries no settled currency', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('duplicate-in-window');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);
    $alert = AnomalyAlert::query()->where('transaction_id', $txnId)->firstOrFail();

    // Blank the settled currency AFTER detection so the dismiss action's own
    // charge lookup takes the base-currency fallback rather than the row value.
    $this->db->connection()->table('transactions')->where('id', $txnId)->update(['settled_currency' => '']);

    /** @var DismissAnomalyAlertAsExpected $dismiss */
    $dismiss = $this->app->make(DismissAnomalyAlertAsExpected::class);
    expect(($dismiss)($alert->id, $user))->toBeTrue();

    $rule = $this->db->connection()->table('anomaly_suppression_rules')
        ->where('user_id', $user->id)
        ->where('detector', 'duplicate')
        ->first();

    expect($rule)->not->toBeNull()
        ->and($rule->currency)->toBe('EUR');
});
