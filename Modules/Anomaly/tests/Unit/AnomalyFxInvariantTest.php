<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\Detectors\LargeVsTypicalDetector;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

/*
 * FX invariant: a multi-currency merchant (Google Play billed in USD,
 * settled in EUR) must be judged on its SETTLED minor units, never the
 * raw native amount. The mixed-currency fixture lands inside the typical
 * settled-EUR band, so the large-vs-typical detector must NOT fire — a
 * spurious "large" flag here would mean the detector compared the wrong
 * currency (Pitfall 5 / T-09-07).
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

it('does not flag a routine USD charge whose settled-EUR amount is typical', function (): void {
    $user = anomalyCorpusUser();
    $fixture = AnomalyCorpusSeeder::load('mixed-currency');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var LargeVsTypicalDetector $detector */
    $detector = $this->app->make(LargeVsTypicalDetector::class);
    $txn = anomalyTxnRow($this->db, $txnId);

    $result = $detector->fires($txn, $user, $user->anomaly_sensitivity_percent, $user->anomaly_min_amount_minor);

    expect($result)->toBeNull();
});

it('builds its baseline from settled minor units (the comparison currency is EUR)', function (): void {
    $user = anomalyCorpusUser();
    // Reuse the large-above fixture but flip the under-test charge to a USD
    // native amount whose settled-EUR stays at the typical €9.99 — the
    // detector must read settled, so it must still not fire.
    $fixture = AnomalyCorpusSeeder::load('mixed-currency');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var LargeVsTypicalDetector $detector */
    $detector = $this->app->make(LargeVsTypicalDetector::class);
    $txn = anomalyTxnRow($this->db, $txnId);

    // Native currency on the row is USD; the detector must not surface USD.
    expect($txn['currency'])->toBe('USD');
    expect($txn['settled_currency'])->toBe('EUR');

    $result = $detector->fires($txn, $user, 50, 1000);
    expect($result)->toBeNull();
});
