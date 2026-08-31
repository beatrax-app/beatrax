<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\Detectors\LargeVsTypicalDetector;
use Modules\Anomaly\Internal\Support\AnomalySensitivity;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

// The detector is driven directly, not through AnomalyEvaluator, so a
// regression in the MAD / percentile / sensitivity maths is pinpointed.
beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('fires the large reason exactly as the corpus expects', function (string $fixtureName, bool $expectLarge): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load($fixtureName);
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var LargeVsTypicalDetector $detector */
    $detector = $this->app->make(LargeVsTypicalDetector::class);

    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);
    $result = $detector->fires($txn, $user, AnomalySensitivity::fromStored($user->anomaly_sensitivity_percent), $user->anomaly_min_amount_minor);

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
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('large-above');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var LargeVsTypicalDetector $detector */
    $detector = $this->app->make(LargeVsTypicalDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    $result = $detector->fires($txn, $user, AnomalySensitivity::default(), AnomalySensitivity::DEFAULT_MIN_AMOUNT_MINOR);
    expect($result)->not->toBeNull();
    expect($result['baseline_amount_minor'])->toBe(-999);
});
