<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\NavCountsService;

/*
 * Phase 13.3 Finding C regression: NavCountsService's 'tax_tagged' sidebar
 * badge counted raw tax_transaction_tags rows, so a transaction with a
 * stale (superseded) whole-transaction tag PLUS leg-scoped tags on its
 * splits was over-counted — the sidebar badge read one higher than the
 * /tax cockpit, which already excludes the superseded whole-tx row (see
 * TaxYearQuery's supersession policy). The fix mirrors that same
 * whereNotNull/orWhereNotExists visibility shape directly in
 * NavCountsService's raw-table count so the two surfaces always agree.
 */

function ncstUser(DatabaseManager $db, string $username): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ncstTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'NCST ASN '.$suffix,
        'slug' => 'ncst-asn-'.$suffix,
        'kind' => 'asn',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ncst-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'ncst-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'ncst-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-04-01',
        'booked_at' => '2026-04-01 00:00:00',
        'value_date' => '2026-04-01',
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'ncst-vendor',
        'counterparty_name' => 'NCST Vendor BV',
        'normalization_version' => 1,
        'description' => 'NavCountsTaxSupersession fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ncstSpendCategory(DatabaseManager $db, int $userId, string $name): int
{
    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ncstLeg(DatabaseManager $db, int $userId, int $txId, int $categoryId, int $settledAmountMinor, int $sortOrder): int
{
    return $db->connection()->table('transaction_splits')->insertGetId([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'category_id' => $categoryId,
        'settled_amount_minor' => $settledAmountMinor,
        'settled_currency' => 'EUR',
        'note' => null,
        'sort_order' => $sortOrder,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ncstDeductionCategory(DatabaseManager $db, int $userId, string $name = 'NCST Category'): int
{
    return $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'short_name' => substr($name, 0, 3),
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ncstTag(DatabaseManager $db, int $userId, int $txId, ?int $catId, ?int $transactionSplitId = null): int
{
    return $db->connection()->table('tax_transaction_tags')->insertGetId([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'transaction_split_id' => $transactionSplitId,
        'deduction_category_id' => $catId,
        'tax_year_override' => null,
        'note' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('tax_tagged excludes a superseded whole-tx row when leg tags exist — 1 whole-tx + 2 leg tags counts as 2, not 3', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = ncstUser($db, 'ncst-supersede');
    $txId = ncstTransaction($db, $userId);
    $groceries = ncstSpendCategory($db, $userId, 'NCST Groceries');
    $household = ncstSpendCategory($db, $userId, 'NCST Household');
    $legA = ncstLeg($db, $userId, $txId, $groceries, -6000, 0);
    $legB = ncstLeg($db, $userId, $txId, $household, -2000, 1);
    $catId = ncstDeductionCategory($db, $userId);

    // Stale whole-tx tag predates the split.
    ncstTag($db, $userId, $txId, $catId, null);
    // Both legs now carry their own leg-scoped tags.
    ncstTag($db, $userId, $txId, $catId, $legA);
    ncstTag($db, $userId, $txId, $catId, $legB);

    // Sanity: 3 raw rows exist in the table.
    expect($db->connection()->table('tax_transaction_tags')->where('transaction_id', $txId)->count())->toBe(3);

    /** @var NavCountsService $service */
    $service = app(NavCountsService::class);
    $counts = $service->forUser($userId);

    expect($counts['tax_tagged'])->toBe(2);
});

it('tax_tagged counts a lone whole-tx tag normally when no legs exist (regression)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = ncstUser($db, 'ncst-unsplit');
    $txId = ncstTransaction($db, $userId);
    $catId = ncstDeductionCategory($db, $userId, 'NCST Unsplit Cat');

    ncstTag($db, $userId, $txId, $catId, null);

    /** @var NavCountsService $service */
    $service = app(NavCountsService::class);
    $counts = $service->forUser($userId);

    expect($counts['tax_tagged'])->toBe(1);
});
