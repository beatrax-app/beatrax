<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\Detectors\LargeVsTypicalDetector;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

/*
 * Large-vs-typical detector coverage driven by the shared anomaly-corpus
 * fixtures. Each fixture seeds the per-counterparty / per-category history
 * plus the charge under test; the detector must fire (or not) exactly as
 * the fixture's `expected.reasons` lists for the `large` reason.
 *
 * The full multi-reason AnomalyEvaluator is exercised in Task 3's tests;
 * here we isolate the statistical large-vs-typical path so a regression in
 * the MAD / percentile / sensitivity math is pinpointed.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function anomalyCorpusUser(): User
{
    return User::query()->create([
        'username' => 'anom-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * Loads the under-test transaction as the raw array the detector consumes.
 *
 * @return array<string, mixed>
 */
function anomalyTxnRow(DatabaseManager $db, int $txnId): array
{
    /** @var stdClass $row */
    $row = $db->connection()->table('transactions')->where('id', $txnId)->first();

    return (array) $row;
}

it('fires the large reason exactly as the corpus expects', function (string $fixtureName, bool $expectLarge): void {
    $user = anomalyCorpusUser();
    $fixture = AnomalyCorpusSeeder::load($fixtureName);
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var LargeVsTypicalDetector $detector */
    $detector = $this->app->make(LargeVsTypicalDetector::class);

    $txn = anomalyTxnRow($this->db, $txnId);
    $result = $detector->fires($txn, $user, $user->anomaly_sensitivity_percent, $user->anomaly_min_amount_minor);

    if ($expectLarge) {
        expect($result)->not->toBeNull();
        expect($result['currency'])->toBe('EUR');
        expect($result['latest_amount_minor'])->toBeInt();
        expect($result['baseline_amount_minor'])->toBeInt();
    } else {
        expect($result)->toBeNull();
    }
})->with([
    'large-above fires' => ['large-above', true],
    'large-below does not fire' => ['large-below', false],
    'thin-history category fallback fires' => ['thin-history-category-fallback', true],
]);

it('maps the default 50% sensitivity to k=3.0 (the large-above baseline)', function (): void {
    $user = anomalyCorpusUser();
    $fixture = AnomalyCorpusSeeder::load('large-above');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var LargeVsTypicalDetector $detector */
    $detector = $this->app->make(LargeVsTypicalDetector::class);
    $txn = anomalyTxnRow($this->db, $txnId);

    // At the default sensitivity the €23.49 charge against a stable €9.99
    // baseline trips; at a far less sensitive setting (k clamps high), the
    // same charge against a zero-MAD floored baseline still trips because
    // the deviation dwarfs the floor — assert the default path fires.
    $result = $detector->fires($txn, $user, 50, 1000);
    expect($result)->not->toBeNull();
    expect($result['baseline_amount_minor'])->toBe(-999);
});
