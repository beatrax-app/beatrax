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

// The safety-net sweep selects on created_at, so a statement imported today
// routinely carries charges posted years ago. "Today" here is 533 days after
// the charge under test, which is the gap that emptied the baseline.
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-30 09:00:00');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return list<array<string, mixed>>
 */
function bacHistoryAround(string $counterparty): array
{
    $rows = [];
    foreach (['2024-03-01', '2024-03-04', '2024-03-07', '2024-03-10', '2024-03-13'] as $postedAt) {
        $rows[] = [
            'counterparty' => $counterparty,
            'category' => 'groceries',
            'amount_minor' => -2000,
            'currency' => 'EUR',
            'posted_at' => $postedAt,
        ];
    }

    return $rows;
}

it('draws the large-vs-typical baseline from the months around the charge', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, [
        'history' => bacHistoryAround('old-cafe'),
        'transaction' => [
            'counterparty' => 'old-cafe',
            'category' => 'groceries',
            'amount_minor' => -50000,
            'currency' => 'EUR',
            'posted_at' => '2024-03-15',
        ],
    ]);

    /** @var LargeVsTypicalDetector $detector */
    $detector = $this->app->make(LargeVsTypicalDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    $trip = $detector->fires($txn, $user, AnomalySensitivity::default(), $user->anomaly_min_amount_minor);

    expect($trip)->not->toBeNull()
        ->and($trip['baseline_amount_minor'])->toBe(-2000)
        ->and($trip['latest_amount_minor'])->toBe(-50000);
});

it('draws the first-time overall-spend baseline from the months around the charge', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, [
        'history' => bacHistoryAround('old-cafe'),
        'transaction' => [
            'counterparty' => 'brand-new-shop',
            'category' => 'groceries',
            'amount_minor' => -50000,
            'currency' => 'EUR',
            'posted_at' => '2024-03-15',
        ],
    ]);

    /** @var FirstTimeMerchantDetector $detector */
    $detector = $this->app->make(FirstTimeMerchantDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    expect($detector->fires($txn, $user, $user->anomaly_min_amount_minor))->toBeTrue();
});

// The detector that already anchored on the row, kept honest while the other
// two were moved onto the same anchor: a historic double-charge is a
// double-charge whenever the file describing it happens to be imported.
it('still finds a historic double charge long after it was imported', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, [
        'history' => [[
            'counterparty' => 'old-cafe',
            'amount_minor' => -50000,
            'currency' => 'EUR',
            'posted_at' => '2024-03-14',
        ]],
        'transaction' => [
            'counterparty' => 'old-cafe',
            'amount_minor' => -50000,
            'currency' => 'EUR',
            'posted_at' => '2024-03-15',
        ],
    ]);

    /** @var DuplicateChargeDetector $detector */
    $detector = $this->app->make(DuplicateChargeDetector::class);
    $txn = AnomalyCorpusSeeder::transactionRow($this->db, $txnId);

    expect($detector->fires($txn, $user, $user->anomaly_min_amount_minor))->toBeTrue();
});
