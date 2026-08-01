<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Modules\Tax\Public\Services\TaxTagQuery;

/*
 * Feature tests for Plan 02 Task 2:
 *   - TaxTagQuery: keyed badge map, user-scope isolation, summary, batch suggestion
 *   - NavCountsService: tax_tagged key present and correct
 *
 * Covers: T-07-06 (cross-user badge isolation), D-03 (batch suggestion).
 */

// ---------------------------------------------------------------------------
// Shared fixture helpers (reuse the taxTest* helpers from TaxTagActionTest
// if defined in a shared helper, or inline them here)
// ---------------------------------------------------------------------------

/**
 * Inserts a minimal user row and returns its id.
 */
function nctUser(DatabaseManager $db, string $username): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Inserts an account + import run + transaction for the user.
 * Returns the transaction id.
 *
 * @param  array<string, mixed>  $overrides
 */
function nctTransaction(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'NCT ASN '.$suffix,
        'slug' => 'nct-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/nct-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'nct-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $defaults = [
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'nct-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2025-03-01',
        'booked_at' => '2025-03-01 00:00:00',
        'value_date' => '2025-03-01',
        'amount_minor' => -4990,
        'currency' => 'EUR',
        'settled_amount_minor' => -4990,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'nct-vendor',
        'counterparty_name' => 'NCT Vendor BV',
        'normalization_version' => 1,
        'description' => 'NCT test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(
        array_merge($defaults, $overrides),
    );
}

/**
 * Inserts a deduction category for the user and returns its id.
 */
function nctCategory(DatabaseManager $db, int $userId, string $name = 'NCT Category'): int
{
    return $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'short_name' => strtoupper(substr($name, 0, 3)),
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Inserts a tag row for the given user + transaction and returns the tag id.
 *
 * @param  array<string, mixed>  $overrides
 */
function nctTag(DatabaseManager $db, int $userId, int $txId, ?int $catId = null, array $overrides = []): int
{
    $defaults = [
        'user_id' => $userId,
        'transaction_id' => $txId,
        'deduction_category_id' => $catId,
        'tax_year_override' => null,
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('tax_transaction_tags')->insertGetId(
        array_merge($defaults, $overrides),
    );
}

/**
 * Inserts a counterparty and returns its id.
 */
function nctCounterparty(DatabaseManager $db, int $userId, string $name = 'NCT CP'): int
{
    $suffix = bin2hex(random_bytes(4));

    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'display_name' => $name,
        'slug' => 'nct-cp-'.$suffix,
        'type' => 'merchant',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ---------------------------------------------------------------------------
// TaxTagQuery tests
// ---------------------------------------------------------------------------

it('forTransactionIds returns only tagged ids keyed by transaction id', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = nctUser($db, 'ttq-keyed');
    $catId = nctCategory($db, $userId, 'Health');
    $txTagged = nctTransaction($db, $userId);
    $txUntagged = nctTransaction($db, $userId);

    nctTag($db, $userId, $txTagged, $catId);

    /** @var TaxTagQuery $query */
    $query = app(TaxTagQuery::class);
    $result = $query->forTransactionIds($userId, [$txTagged, $txUntagged]);

    expect($result)->toHaveKey($txTagged)
        ->and($result)->not->toHaveKey($txUntagged)
        ->and($result[$txTagged]->transactionId)->toBe($txTagged)
        ->and($result[$txTagged]->deductionCategoryShortName)->toBe('HEA');
});

it('forTransactionIds is user-scoped — another user tag does not leak into the map', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = nctUser($db, 'ttq-iso-owner');
    $requester = nctUser($db, 'ttq-iso-requester');

    $catId = nctCategory($db, $owner, 'Isolation Cat');
    $txId = nctTransaction($db, $owner);
    nctTag($db, $owner, $txId, $catId);

    /** @var TaxTagQuery $query */
    $query = app(TaxTagQuery::class);

    // The requester asks about the same transaction id — must get empty map (T-07-06)
    $result = $query->forTransactionIds($requester, [$txId]);

    expect($result)->toBe([]);
});

it('forTransactionIds issues a single query for a batch of ids', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = nctUser($db, 'ttq-batch');
    $catId = nctCategory($db, $userId, 'Batch Cat');

    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $txId = nctTransaction($db, $userId);
        nctTag($db, $userId, $txId, $catId);
        $ids[] = $txId;
    }

    /** @var TaxTagQuery $query */
    $query = app(TaxTagQuery::class);

    $db->connection()->enableQueryLog();
    $result = $query->forTransactionIds($userId, $ids);
    $log = $db->connection()->getQueryLog();
    $db->connection()->disableQueryLog();

    expect(count($log))->toBe(1, 'forTransactionIds must issue exactly 1 query for a batch (N+1 guard)')
        ->and(count($result))->toBe(5);
});

it('summaryForUser returns totalMinor and count for the year', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = nctUser($db, 'ttq-summary');
    $catId = nctCategory($db, $userId, 'Summary Cat');

    $tx1 = nctTransaction($db, $userId, ['settled_amount_minor' => -1000, 'booked_at' => '2025-02-01 00:00:00']);
    $tx2 = nctTransaction($db, $userId, ['settled_amount_minor' => -2000, 'booked_at' => '2025-05-01 00:00:00']);
    // Tx3 in different year — must NOT be included
    $tx3 = nctTransaction($db, $userId, ['settled_amount_minor' => -500, 'booked_at' => '2024-12-01 00:00:00']);

    nctTag($db, $userId, $tx1, $catId);
    nctTag($db, $userId, $tx2, $catId);
    nctTag($db, $userId, $tx3, $catId);

    /** @var TaxTagQuery $query */
    $query = app(TaxTagQuery::class);
    $summary = $query->summaryForUser($userId, 2025);

    expect($summary->year)->toBe(2025)
        ->and($summary->count)->toBe(2)
        ->and($summary->totalMinor)->toBe(3000);
});

it('summaryForUser totalMinor counts deductions only — tagged income is excluded from the total but not the count (IN-08)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = nctUser($db, 'ttq-summary-income');
    $catId = nctCategory($db, $userId, 'Mixed Cat');

    $expense = nctTransaction($db, $userId, ['settled_amount_minor' => -50000, 'booked_at' => '2025-02-01 00:00:00']);
    $income = nctTransaction($db, $userId, ['settled_amount_minor' => 50000, 'type' => 'income', 'booked_at' => '2025-03-01 00:00:00']);

    nctTag($db, $userId, $expense, $catId);
    nctTag($db, $userId, $income, $catId);

    /** @var TaxTagQuery $query */
    $query = app(TaxTagQuery::class);
    $summary = $query->summaryForUser($userId, 2025);

    // Matches the /tax cockpit headline (€500 deductions), NOT €1.000.
    expect($summary->totalMinor)->toBe(50000)
        ->and($summary->count)->toBe(2);
});

it('untaggedCountForCounterparty excludes the just-tagged transaction', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = nctUser($db, 'ttq-batch-cp');
    $catId = nctCategory($db, $userId, 'Batch CP Cat');
    $cpId = nctCounterparty($db, $userId, 'Gym Subscription');

    // 3 transactions for the same counterparty in 2025
    $txJustTagged = nctTransaction($db, $userId, [
        'booked_at' => '2025-01-01 00:00:00',
        'counterparty_id' => $cpId,
    ]);
    $txUntagged1 = nctTransaction($db, $userId, [
        'booked_at' => '2025-02-01 00:00:00',
        'counterparty_id' => $cpId,
    ]);
    $txUntagged2 = nctTransaction($db, $userId, [
        'booked_at' => '2025-03-01 00:00:00',
        'counterparty_id' => $cpId,
    ]);

    // Only tag the "just-tagged" one
    nctTag($db, $userId, $txJustTagged, $catId);

    /** @var TaxTagQuery $query */
    $query = app(TaxTagQuery::class);
    $suggestion = $query->untaggedCountForCounterparty($userId, $txJustTagged, 2025);

    expect($suggestion->counterpartyId)->toBe($cpId)
        ->and($suggestion->counterpartyName)->toBe('Gym Subscription')
        ->and($suggestion->untaggedCount)->toBe(2);
});

it('untaggedCountForCounterparty returns zero when transaction has no counterparty', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = nctUser($db, 'ttq-nocp');
    $catId = nctCategory($db, $userId, 'NoCp Cat');

    // Transaction without a counterparty
    $txNoCp = nctTransaction($db, $userId, [
        'booked_at' => '2025-01-01 00:00:00',
        'counterparty_id' => null,
    ]);
    nctTag($db, $userId, $txNoCp, $catId);

    /** @var TaxTagQuery $query */
    $query = app(TaxTagQuery::class);
    $suggestion = $query->untaggedCountForCounterparty($userId, $txNoCp, 2025);

    expect($suggestion->untaggedCount)->toBe(0);
});

// ---------------------------------------------------------------------------
// NavCountsService tests
// ---------------------------------------------------------------------------

it('NavCountsService.compute includes a tax_tagged integer key', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = nctUser($db, 'ncs-tax-key');
    $catId = nctCategory($db, $userId, 'Nav Cat');

    $tx1 = nctTransaction($db, $userId);
    $tx2 = nctTransaction($db, $userId);

    nctTag($db, $userId, $tx1, $catId);
    nctTag($db, $userId, $tx2, $catId);

    /** @var NavCountsService $service */
    $service = app(NavCountsService::class);
    $counts = $service->forUser($userId);

    expect($counts)->toHaveKey('tax_tagged')
        ->and($counts['tax_tagged'])->toBe(2);
});

it('tagging a transaction invalidates the cached nav counts (WR-06)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = nctUser($db, 'ncs-invalidate-tag');
    $txId = nctTransaction($db, $userId);

    /** @var NavCountsService $service */
    $service = app(NavCountsService::class);

    // Warm the cache BEFORE tagging.
    expect($service->forUser($userId)['tax_tagged'])->toBe(0);

    app(TagTransaction::class)->execute($userId, $txId, null, null, null);

    // Without invalidation this would still read the stale cached 0 for up
    // to 300 seconds.
    expect($service->forUser($userId)['tax_tagged'])->toBe(1);
});

it('untagging a transaction invalidates the cached nav counts (WR-06)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = nctUser($db, 'ncs-invalidate-untag');
    $txId = nctTransaction($db, $userId);
    nctTag($db, $userId, $txId);

    /** @var NavCountsService $service */
    $service = app(NavCountsService::class);

    // Warm the cache BEFORE untagging.
    expect($service->forUser($userId)['tax_tagged'])->toBe(1);

    app(UntagTransaction::class)->execute($userId, $txId);

    expect($service->forUser($userId)['tax_tagged'])->toBe(0);
});

it('NavCountsService tax_tagged count reflects only the requesting user tags', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $user1 = nctUser($db, 'ncs-iso-u1');
    $user2 = nctUser($db, 'ncs-iso-u2');

    $cat1 = nctCategory($db, $user1, 'Iso Cat 1');

    $tx1 = nctTransaction($db, $user1);
    $tx2 = nctTransaction($db, $user1);
    $tx3 = nctTransaction($db, $user2);

    nctTag($db, $user1, $tx1, $cat1);
    nctTag($db, $user1, $tx2, $cat1);
    nctTag($db, $user2, $tx3, null);

    /** @var NavCountsService $service */
    $service = app(NavCountsService::class);

    expect($service->forUser($user1)['tax_tagged'])->toBe(2)
        ->and($service->forUser($user2)['tax_tagged'])->toBe(1);
});
