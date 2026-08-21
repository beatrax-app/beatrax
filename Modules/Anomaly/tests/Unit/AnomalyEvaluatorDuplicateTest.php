<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\Detectors\DuplicateChargeDetector;
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

it('fires for an exact duplicate within the 7-day window', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('duplicate-in-window');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var DuplicateChargeDetector $detector */
    $detector = $this->app->make(DuplicateChargeDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    expect($detector->fires($txn, $user, $user->anomaly_min_amount_minor))->toBeTrue();
});

// Two charges on one approved series are a legitimate cadence that landed
// twice, not a double charge.
it('does NOT fire when both charges are on the same approved recurring series', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('duplicate-recurring-excluded');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var DuplicateChargeDetector $detector */
    $detector = $this->app->make(DuplicateChargeDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    expect($detector->fires($txn, $user, $user->anomaly_min_amount_minor))->toBeFalse();
});

it('fires on the later charge but not its earlier sibling — one alert per real duplicate (WR-02)', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('duplicate-in-window');
    $laterId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    $earlierId = (int) $this->db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->where('settled_amount_minor', -4999)
        ->where('posted_at', '2026-06-12')
        ->value('id');

    /** @var DuplicateChargeDetector $detector */
    $detector = $this->app->make(DuplicateChargeDetector::class);
    $laterRow = AnomalyCorpusSeeder::transactionRow($this->db, $laterId);
    $earlierRow = AnomalyCorpusSeeder::transactionRow($this->db, $earlierId);

    expect($detector->fires($laterRow, $user, $user->anomaly_min_amount_minor))->toBeTrue()
        ->and($detector->fires($earlierRow, $user, $user->anomaly_min_amount_minor))->toBeFalse();
});

it('exposes DUPLICATE_WINDOW_DAYS = 7 as a named constant', function (): void {
    expect(DuplicateChargeDetector::DUPLICATE_WINDOW_DAYS)->toBe(7);
});
