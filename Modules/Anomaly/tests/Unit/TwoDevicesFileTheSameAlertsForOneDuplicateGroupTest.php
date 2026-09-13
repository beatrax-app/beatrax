<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\Detectors\DuplicateChargeDetector;
use Modules\Anomaly\Internal\Detectors\FirstTimeMerchantDetector;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;
use Modules\Core\Models\User;

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

// One charge a terminal captured three times. The bank books all three on one
// date, at one instant, for one amount, to one merchant — so inside the dedup
// tuple `occurrence_ordinal` is the only column that tells them apart, and the
// only other thing that could is the order a device happened to store them in.
const ONE_DUPLICATE_GROUP = [0, 1, 2];

// Two devices holding that group, numbered as each one's own autoincrement
// reached it: ascending here, and there the third capture written first.
const ONE_DUPLICATE_GROUP_ON_THIS_DEVICE = [0, 1, 2];

const ONE_DUPLICATE_GROUP_ON_THE_PEER = [2, 0, 1];

/**
 * @param  list<int>  $writeOrder  the ordinals in the order this device stored them
 * @return array<int, int> ordinal => the id this device gave it
 */
function oneDuplicateGroupStoredInWriteOrder(DatabaseManager $db, User $user, array $writeOrder): array
{
    $capture = static fn (int $ordinal): array => [
        'counterparty' => 'coolblue',
        'amount_minor' => -4999,
        'currency' => 'EUR',
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 10:02:00',
        'occurrence_ordinal' => $ordinal,
        'direction' => 'expense',
        'on_recurring_series' => false,
    ];

    // Two charges to another merchant, identical on both devices. They are the
    // overall distribution `first_time` needs three of before it will judge
    // largeness at all, and they keep coolblue the merchant never seen before.
    $elsewhere = static fn (string $postedAt): array => [
        'counterparty' => 'albert-heijn',
        'amount_minor' => -500,
        'currency' => 'EUR',
        'posted_at' => $postedAt,
        'direction' => 'expense',
        'on_recurring_series' => false,
    ];

    // seed() writes `history` in order and `transaction` last, so the write
    // order IS the order the ids come out in.
    AnomalyCorpusSeeder::seed($db, $user, [
        'settings' => ['anomaly_sensitivity_percent' => 50, 'anomaly_min_amount_minor' => 1000],
        'history' => [
            $elsewhere('2026-06-01'),
            $elsewhere('2026-06-02'),
            $capture($writeOrder[0]),
            $capture($writeOrder[1]),
        ],
        'transaction' => $capture($writeOrder[2]),
    ]);

    $ids = [];

    $captures = $db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->where('counterparty_normalized', 'coolblue')
        ->get();

    foreach ($captures as $row) {
        $ids[(int) $row->occurrence_ordinal] = (int) $row->id;
    }

    ksort($ids);

    return $ids;
}

/**
 * @param  array<int, int>  $ids  ordinal => the id this device gave it
 * @return list<int> the ordinals this device opens a duplicate alert against
 */
function oneDuplicateGroupAlertedOrdinals(mixed $app, DatabaseManager $db, User $user, array $ids): array
{
    /** @var DuplicateChargeDetector $detector */
    $detector = $app->make(DuplicateChargeDetector::class);
    $alerted = [];

    foreach ($ids as $ordinal => $id) {
        if ($detector->fires(AnomalyCorpusSeeder::transactionRow($db, $id), $user, $user->anomaly_min_amount_minor)) {
            $alerted[] = $ordinal;
        }
    }

    return $alerted;
}

/**
 * @param  array<int, int>  $ids  ordinal => the id this device gave it
 * @return list<int> the ordinals in the order this device's ids rank them
 */
function oneDuplicateGroupByAscendingId(array $ids): array
{
    asort($ids);

    return array_keys($ids);
}

it('files the same alerts whichever order the device stored the group in', function (): void {
    $here = AnomalyCorpusSeeder::makeUser();
    $there = AnomalyCorpusSeeder::makeUser();

    $mine = oneDuplicateGroupStoredInWriteOrder($this->db, $here, ONE_DUPLICATE_GROUP_ON_THIS_DEVICE);
    $peer = oneDuplicateGroupStoredInWriteOrder($this->db, $there, ONE_DUPLICATE_GROUP_ON_THE_PEER);

    // The premise, asserted rather than assumed: two devices that numbered the
    // group identically agree for a reason that has nothing to do with the fix.
    expect(array_keys($mine))->toBe(ONE_DUPLICATE_GROUP)
        ->and(array_keys($peer))->toBe(ONE_DUPLICATE_GROUP)
        ->and(oneDuplicateGroupByAscendingId($mine))->toBe(ONE_DUPLICATE_GROUP_ON_THIS_DEVICE)
        ->and(oneDuplicateGroupByAscendingId($peer))->toBe(ONE_DUPLICATE_GROUP_ON_THE_PEER);

    $alertedHere = oneDuplicateGroupAlertedOrdinals($this->app, $this->db, $here, $mine);
    $alertedThere = oneDuplicateGroupAlertedOrdinals($this->app, $this->db, $there, $peer);

    expect($alertedThere)->toBe(
        $alertedHere,
        'Two devices holding one duplicate group alert on different captures of it, so the pair files '
        .'an alert row this device never opened and the reader sees a different screen on each.',
    )->and($alertedHere)->toBe(
        [1, 2],
        'A group of three is two duplicate pairs, and the alert belongs on the later capture of each: '
        .'the first occurrence has nothing before it and stays silent.',
    );
});

// anomaly_alerts carries UNIQUE(transaction_id) and the id is minted, so the
// pair converges on one row per CHARGE. That is one row per pair only while
// both devices name the same charges; when they do not, the union is what the
// synced pair ends up holding.
it('leaves the synced pair one alert per duplicate pair, not one per charge', function (): void {
    $here = AnomalyCorpusSeeder::makeUser();
    $there = AnomalyCorpusSeeder::makeUser();

    $mine = oneDuplicateGroupStoredInWriteOrder($this->db, $here, ONE_DUPLICATE_GROUP_ON_THIS_DEVICE);
    $peer = oneDuplicateGroupStoredInWriteOrder($this->db, $there, ONE_DUPLICATE_GROUP_ON_THE_PEER);

    $union = array_values(array_unique(array_merge(
        oneDuplicateGroupAlertedOrdinals($this->app, $this->db, $here, $mine),
        oneDuplicateGroupAlertedOrdinals($this->app, $this->db, $there, $peer),
    )));
    sort($union);

    expect($union)->toBe(
        [1, 2],
        'Three identical charges are two duplicate pairs, so the pair of devices must hold two alerts. '
        .'A third means one device alerted on a capture the other called the original.',
    );
});

/**
 * @param  array<int, int>  $ids  ordinal => the id this device gave it
 * @return list<int> the ordinals this device calls the first charge to this merchant
 */
function oneDuplicateGroupFirstTimeOrdinals(mixed $app, DatabaseManager $db, User $user, array $ids): array
{
    /** @var FirstTimeMerchantDetector $detector */
    $detector = $app->make(FirstTimeMerchantDetector::class);
    $first = [];

    foreach ($ids as $ordinal => $id) {
        if ($detector->fires(AnomalyCorpusSeeder::transactionRow($db, $id), $user, $user->anomaly_min_amount_minor)) {
            $first[] = $ordinal;
        }
    }

    return $first;
}

// The duplicate detector is not the only reader asking which of a merchant's
// same-day charges came first. `first_time` asks it as a count, so no ordering
// guard can see it, and it answered out of the same per-device numbering.
it('calls the same capture the first charge to this merchant on both devices', function (): void {
    $here = AnomalyCorpusSeeder::makeUser();
    $there = AnomalyCorpusSeeder::makeUser();

    $mine = oneDuplicateGroupStoredInWriteOrder($this->db, $here, ONE_DUPLICATE_GROUP_ON_THIS_DEVICE);
    $peer = oneDuplicateGroupStoredInWriteOrder($this->db, $there, ONE_DUPLICATE_GROUP_ON_THE_PEER);

    $firstHere = oneDuplicateGroupFirstTimeOrdinals($this->app, $this->db, $here, $mine);
    $firstThere = oneDuplicateGroupFirstTimeOrdinals($this->app, $this->db, $there, $peer);

    expect($firstThere)->toBe(
        $firstHere,
        'Two devices holding one merchant\'s first three charges disagree about which of them was first, '
        .'so each files first_time against a charge the other calls a repeat.',
    )->and($firstHere)->toBe(
        [0],
        'Exactly one capture is the first one, and it is the first occurrence of the tuple.',
    );
});
