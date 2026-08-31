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

it('fires for a large charge to a never-seen merchant', function (): void {
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

// The question is "first", which is a question about the charges BEFORE this
// one. A merchant the user goes on to use again is still a merchant they had
// never used at the moment of this charge.
it('still fires when the only other charge to the merchant is LATER', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('first-time-large');
    $fixture['history_after'] = [
        ['counterparty' => 'new-electronics-shop', 'amount_minor' => -2200, 'currency' => 'EUR', 'posted_at' => '2026-06-16'],
    ];
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var FirstTimeMerchantDetector $detector */
    $detector = $this->app->make(FirstTimeMerchantDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    expect($detector->fires($txn, $user, $user->anomaly_min_amount_minor))->toBeTrue();
});

it('does NOT fire on the later charge of that same pair', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('first-time-large');
    $fixture['history_after'] = [
        ['counterparty' => 'new-electronics-shop', 'amount_minor' => -22000, 'currency' => 'EUR', 'posted_at' => '2026-06-16'],
    ];
    AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    $laterId = (int) $this->db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->where('posted_at', '2026-06-16')
        ->value('id');

    /** @var FirstTimeMerchantDetector $detector */
    $detector = $this->app->make(FirstTimeMerchantDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $laterId);

    expect($detector->fires($txn, $user, $user->anomaly_min_amount_minor))->toBeFalse();
});

// Two charges on one day cannot be ordered by date, so the id decides — the
// same tie-break the duplicate window uses, so exactly one of the pair is
// ever the first.
it('gives a same-day pair exactly one first-time charge', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('first-time-large');
    $fixture['history_after'] = [
        ['counterparty' => 'new-electronics-shop', 'amount_minor' => -18500, 'currency' => 'EUR', 'posted_at' => '2026-06-15', 'booked_at' => '2026-06-15 18:30:00'],
    ];
    $earlierId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    $siblingId = (int) $this->db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->where('booked_at', '2026-06-15 18:30:00')
        ->value('id');

    /** @var FirstTimeMerchantDetector $detector */
    $detector = $this->app->make(FirstTimeMerchantDetector::class);

    expect($detector->fires(AnomalyCorpusSeeder::transactionRow($this->db, $earlierId), $user, $user->anomaly_min_amount_minor))->toBeTrue();
    expect($detector->fires(AnomalyCorpusSeeder::transactionRow($this->db, $siblingId), $user, $user->anomaly_min_amount_minor))->toBeFalse();
});
