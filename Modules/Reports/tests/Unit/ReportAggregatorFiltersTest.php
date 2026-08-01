<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Reports\Internal\Aggregation\ReportAggregator;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Enums\ReportGranularity;

uses(RefreshDatabase::class);

/*
 * Plan 06 Task 1 companion coverage (T-999.6-06/T-999.6-14) — not a Wave 0
 * stub. Task 1's own acceptance criteria states "Filter ids are
 * re-validated at the query layer (ownership), not trusted from
 * ReportDefinition", but no Wave 0 stub exercises it directly; this file
 * pins that behavior (Rule 2 auto-add, see SUMMARY.md Deviations).
 *
 * Every Plan 04 dimension query's `whereIn('account_id'/'category_id'/
 * 'counterparty_id', ...)` filter predicate sits ALONGSIDE the query's own
 * pre-existing `where('user_id', $user->id)` guard, so a foreign id can
 * only ever narrow (or zero) the calling user's own already-scoped result
 * — never widen it to another user's rows.
 */

function raftUser(string $prefix = 'raft'): User
{
    /** @var User */
    return User::query()->create([
        'username' => $prefix.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function raftAccount(User $user, string $name): Account
{
    /** @var Account */
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00RAFT'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);
}

function raftImportRun(DatabaseManager $db, User $user): int
{
    return $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/raft-run-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'raft-run-'.bin2hex(random_bytes(4))),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function raftTransaction(DatabaseManager $db, User $user, Account $account, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(8));
    $settledMinor = $overrides['settled_amount_minor'] ?? -1000;

    $defaults = [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => raftImportRun($db, $user),
        'type' => 'expense',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 10:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'RAFT Vendor',
        'counterparty_normalized' => 'raft-vendor',
        'normalization_version' => 1,
        'category_id' => null,
        'counterparty_id' => null,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'raft-tx-'.$suffix),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

/**
 * @param  list<int>  $accounts
 * @param  list<int>  $categories
 * @param  list<int>  $counterparties
 */
function raftDefinition(string $dimension, array $accounts = [], array $categories = [], array $counterparties = []): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: $dimension,
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-05-01',
        customTo: '2026-05-31',
        accounts: $accounts,
        categories: $categories,
        counterparties: $counterparties,
    );
}

it('accounts filter narrows a category-dimension report to only the selected account', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = raftUser();
    $accountA = raftAccount($user, 'ASN');
    $accountB = raftAccount($user, 'ICS');
    /** @var Category $groceries */
    $groceries = Category::query()->create([
        'user_id' => null, 'name' => 'Groceries', 'slug' => 'groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1,
    ]);

    raftTransaction($db, $user, $accountA, ['settled_amount_minor' => -4_000, 'category_id' => $groceries->id]);
    raftTransaction($db, $user, $accountB, ['settled_amount_minor' => -9_000, 'category_id' => $groceries->id]);

    $unfiltered = app(ReportAggregator::class)->run($user, raftDefinition('category'));
    expect($unfiltered->totalMinor)->toBe(13_000);

    $filtered = app(ReportAggregator::class)->run($user, raftDefinition('category', accounts: [$accountA->id]));
    expect($filtered->totalMinor)->toBe(4_000);
});

it('a foreign account id never leaks another user\'s spend — it only narrows the caller\'s own result to nothing', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = raftUser('raft-a');
    $otherUser = raftUser('raft-b');
    $account = raftAccount($user, 'ASN');
    $otherAccount = raftAccount($otherUser, 'ASN-Other');

    raftTransaction($db, $user, $account, ['settled_amount_minor' => -5_000]);
    raftTransaction($db, $otherUser, $otherAccount, ['settled_amount_minor' => -99_000]);

    // A persisted definition carrying a foreign account id (belonging to
    // $otherUser, not $user) must never surface $otherUser's spend — the
    // combined `user_id = $user->id AND account_id IN (foreign_id)`
    // predicate matches zero of $user's own rows.
    $result = app(ReportAggregator::class)->run($user, raftDefinition('account', accounts: [$otherAccount->id]));

    expect($result->totalMinor)->toBe(0);
    expect($result->rows)->toBe([]);
});

it('counterparties filter narrows a time_bucket-dimension report to the selected counterparty', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = raftUser();
    $account = raftAccount($user, 'ASN');

    $vendorOne = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id, 'type' => 'merchant', 'slug' => 'raft-vendor-one-'.bin2hex(random_bytes(3)),
        'display_name' => 'Vendor One', 'merchant_name' => 'VENDOR ONE',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $vendorTwo = $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $user->id, 'type' => 'merchant', 'slug' => 'raft-vendor-two-'.bin2hex(random_bytes(3)),
        'display_name' => 'Vendor Two', 'merchant_name' => 'VENDOR TWO',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    raftTransaction($db, $user, $account, ['settled_amount_minor' => -2_500, 'counterparty_id' => $vendorOne]);
    raftTransaction($db, $user, $account, ['settled_amount_minor' => -7_500, 'counterparty_id' => $vendorTwo]);

    $result = app(ReportAggregator::class)->run($user, raftDefinition('time_bucket', counterparties: [$vendorOne]));

    expect($result->totalMinor)->toBe(2_500);
});

it('AMOUNT FILTER: amountMin + amountDirection=in ("In > EUR 500") changes the total/rows — previously silently ignored', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = raftUser();
    $account = raftAccount($user, 'ASN');
    /** @var Category $groceries */
    $groceries = Category::query()->create([
        'user_id' => null, 'name' => 'Groceries', 'slug' => 'groceries-af-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1,
    ]);

    // Below the EUR 500 threshold — excluded by amountMin regardless of direction.
    raftTransaction($db, $user, $account, ['type' => 'income', 'settled_amount_minor' => 20_000, 'category_id' => $groceries->id]);
    // Above the threshold AND incoming — the only row that should survive both predicates.
    raftTransaction($db, $user, $account, ['type' => 'income', 'settled_amount_minor' => 80_000, 'category_id' => $groceries->id]);
    // Above the threshold in absolute value, but OUTGOING — excluded by amountDirection=in.
    raftTransaction($db, $user, $account, ['type' => 'expense', 'settled_amount_minor' => -90_000, 'category_id' => $groceries->id]);

    $unfiltered = new ReportDefinition(
        metric: 'net',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-05-01',
        customTo: '2026-05-31',
    );
    $unfilteredResult = app(ReportAggregator::class)->run($user, $unfiltered);
    expect($unfilteredResult->totalMinor)->toBe(10_000); // 20_000 + 80_000 - 90_000

    $filtered = new ReportDefinition(
        metric: 'net',
        dimension: 'category',
        periodPreset: 'custom',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        customFrom: '2026-05-01',
        customTo: '2026-05-31',
        amountMin: '500.00',
        amountDirection: 'in',
    );
    $filteredResult = app(ReportAggregator::class)->run($user, $filtered);

    // Amount filter is now wired into every dimension query — the total
    // reflects ONLY the >= EUR 500, incoming row (80_000), never the whole
    // unfiltered net total.
    expect($filteredResult->totalMinor)->toBe(80_000);
});
