<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\Detectors\FirstTimeMerchantDetector;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('fires for a large charge to a never-seen merchant (D-09)', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('first-time-large');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var FirstTimeMerchantDetector $detector */
    $detector = $this->app->make(FirstTimeMerchantDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    expect($detector->fires($txn, $user, $user->anomaly_min_amount_minor))->toBeTrue();
});

it('does NOT fire for a small/typical charge to a new merchant', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    // Shrunk to a typical amount: first-time, but not large vs overall.
    $fixture = AnomalyCorpusSeeder::load('first-time-large');
    $fixture['transaction']['amount_minor'] = -1100;
    $fixture['transaction']['settled_amount_minor'] = -1100;
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var FirstTimeMerchantDetector $detector */
    $detector = $this->app->make(FirstTimeMerchantDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    expect($detector->fires($txn, $user, $user->anomaly_min_amount_minor))->toBeFalse();
});

it('does NOT fire for a merchant the user has charged before', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    // The large-above merchant has prior history: large, but not first-time.
    $fixture = AnomalyCorpusSeeder::load('large-above');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var FirstTimeMerchantDetector $detector */
    $detector = $this->app->make(FirstTimeMerchantDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    expect($detector->fires($txn, $user, $user->anomaly_min_amount_minor))->toBeFalse();
});
