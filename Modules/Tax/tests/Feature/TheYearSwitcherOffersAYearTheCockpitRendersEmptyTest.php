<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Tax\Internal\Services\TaxYearQuery;

function ysoUser(DatabaseManager $db, string $username): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ysoTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'YSO ASN '.$suffix,
        'slug' => 'yso-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/yso-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'yso-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'yso-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-02-10',
        'booked_at' => '2026-02-10 00:00:00',
        'value_date' => '2026-02-10',
        'amount_minor' => -12450,
        'currency' => 'EUR',
        'settled_amount_minor' => -12450,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'yso-vendor',
        'counterparty_name' => 'YSO Vendor BV',
        'normalization_version' => 1,
        'description' => 'YSO test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function ysoLeg(DatabaseManager $db, int $userId, int $txId, string $categoryName, int $settledAmountMinor, int $sortOrder = 0): int
{
    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => $categoryName,
        'slug' => strtolower($categoryName).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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

function ysoTag(DatabaseManager $db, int $userId, int $txId, ?int $splitId = null, ?int $yearOverride = null): void
{
    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'transaction_split_id' => $splitId,
        'deduction_category_id' => null,
        'note' => null,
        'tax_year_override' => $yearOverride,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('does not offer a year whose only tag row is a superseded whole-transaction one, which the cockpit then renders empty', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = ysoUser($db, 'yso-superseded-year');
    $txId = ysoTransaction($db, $userId);
    $legId = ysoLeg($db, $userId, $txId, 'Groceries', -2450, 0);
    ysoLeg($db, $userId, $txId, 'Household', -10000, 1);

    // The stale whole-tx tag was assigned to 2024 before the split replaced it.
    ysoTag($db, $userId, $txId, null, 2024);
    ysoTag($db, $userId, $txId, $legId);

    $query = app(TaxYearQuery::class);

    expect($query->availableYears($userId))->toBe([2026]);
});

it('every year the switcher offers renders at least one item in the cockpit', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = ysoUser($db, 'yso-offered-years-render');
    $txId = ysoTransaction($db, $userId);
    $legId = ysoLeg($db, $userId, $txId, 'Groceries', -2450, 0);
    ysoLeg($db, $userId, $txId, 'Household', -10000, 1);
    ysoTag($db, $userId, $txId, null, 2024);
    ysoTag($db, $userId, $txId, $legId);

    ysoTag($db, $userId, ysoTransaction($db, $userId), null, 2025);

    $query = app(TaxYearQuery::class);

    foreach ($query->availableYears($userId) as $year) {
        expect($query->forUser($userId, $year)->itemCount)->toBeGreaterThan(0);
    }
});

it('still offers a year a live tag was manually assigned to', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = ysoUser($db, 'yso-live-override');
    ysoTag($db, $userId, ysoTransaction($db, $userId), null, 2025);
    ysoTag($db, $userId, ysoTransaction($db, $userId));

    expect(app(TaxYearQuery::class)->availableYears($userId))->toBe([2026, 2025]);
});
