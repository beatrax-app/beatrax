<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\Detectors\LargeVsTypicalDetector;
use Modules\Anomaly\Internal\Support\AnomalySensitivity;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

// The mixed-currency fixture sits inside the typical settled-EUR band, so a
// "large" flag here would mean the detector compared the native amount.
beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('does not flag a routine USD charge whose settled-EUR amount is typical', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('mixed-currency');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var LargeVsTypicalDetector $detector */
    $detector = $this->app->make(LargeVsTypicalDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    $result = $detector->fires($txn, $user, AnomalySensitivity::fromStored($user->anomaly_sensitivity_percent), $user->anomaly_min_amount_minor);

    expect($result)->toBeNull();
});

it('builds its baseline from settled minor units (the comparison currency is EUR)', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('mixed-currency');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var LargeVsTypicalDetector $detector */
    $detector = $this->app->make(LargeVsTypicalDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    expect($txn['currency'])->toBe('USD');
    expect($txn['settled_currency'])->toBe('EUR');

    $result = $detector->fires($txn, $user, AnomalySensitivity::default(), AnomalySensitivity::DEFAULT_MIN_AMOUNT_MINOR);
    expect($result)->toBeNull();
});
