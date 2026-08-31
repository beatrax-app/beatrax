<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\Detectors\DuplicateChargeDetector;
use Modules\Anomaly\Internal\Detectors\FirstTimeMerchantDetector;
use Modules\Anomaly\Internal\Detectors\LargeVsTypicalDetector;
use Modules\Anomaly\Internal\Support\AnomalySensitivity;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

// The sub-floor-ignored fixture is a €4.50 charge under the €10 floor that
// would otherwise be large against its €0.99 baseline.
beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('gates the large-vs-typical detector below the floor', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('sub-floor-ignored');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var LargeVsTypicalDetector $detector */
    $detector = $this->app->make(LargeVsTypicalDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    expect($detector->fires($txn, $user, AnomalySensitivity::fromStored($user->anomaly_sensitivity_percent), $user->anomaly_min_amount_minor))->toBeNull();
});

it('gates the first-time-merchant detector below the floor', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('sub-floor-ignored');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var FirstTimeMerchantDetector $detector */
    $detector = $this->app->make(FirstTimeMerchantDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    expect($detector->fires($txn, $user, $user->anomaly_min_amount_minor))->toBeFalse();
});

it('gates the duplicate-charge detector below the floor', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    // Both sides of the duplicate pair pushed below the €10 floor.
    $fixture = AnomalyCorpusSeeder::load('duplicate-in-window');
    $fixture['history'][0]['amount_minor'] = -450;
    $fixture['transaction']['amount_minor'] = -450;
    $fixture['transaction']['settled_amount_minor'] = -450;
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var DuplicateChargeDetector $detector */
    $detector = $this->app->make(DuplicateChargeDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    expect($detector->fires($txn, $user, $user->anomaly_min_amount_minor))->toBeFalse();
});
