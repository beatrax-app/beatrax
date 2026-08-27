<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Tax\Public\Services\TaxTagQuery;
use Modules\Tax\Public\Services\TaxYearQuery;

function dcmUser(DatabaseManager $db, string $username): int
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
 * @param  array<string, mixed>  $overrides
 */
function dcmTransaction(DatabaseManager $db, int $userId, array $overrides = []): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'DCM ASN '.$suffix,
        'slug' => 'dcm-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/dcm-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'dcm-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $defaults = [
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'dcm-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-02-10',
        'booked_at' => '2026-02-10 00:00:00',
        'value_date' => '2026-02-10',
        'amount_minor' => -12450,
        'currency' => 'EUR',
        'settled_amount_minor' => -12450,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'dcm-vendor',
        'counterparty_name' => 'DCM Vendor BV',
        'normalization_version' => 1,
        'description' => 'DCM test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    return $db->connection()->table('transactions')->insertGetId(array_merge($defaults, $overrides));
}

function dcmSpendCategory(DatabaseManager $db, int $userId, string $name): int
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

function dcmLeg(DatabaseManager $db, int $userId, int $txId, int $categoryId, int $settledAmountMinor, int $sortOrder = 0): int
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

function dcmTag(DatabaseManager $db, int $userId, int $txId, ?int $splitId = null, ?int $yearOverride = null): void
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

it('reports the leg amount on the dashboard card, not the whole parent the leg is a slice of', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = dcmUser($db, 'dcm-leg-amount');
    $txId = dcmTransaction($db, $userId);
    $legId = dcmLeg($db, $userId, $txId, dcmSpendCategory($db, $userId, 'Groceries'), -2450, 0);
    dcmLeg($db, $userId, $txId, dcmSpendCategory($db, $userId, 'Household'), -10000, 1);

    dcmTag($db, $userId, $txId, $legId);

    $summary = app(TaxTagQuery::class)->summaryForUser($userId, 2026);

    expect($summary->totalMinor)->toBe(2450)
        ->and($summary->count)->toBe(1);
});

it('drops the superseded whole-transaction tag from the dashboard card, so the same money is not counted twice', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = dcmUser($db, 'dcm-supersede');
    $txId = dcmTransaction($db, $userId);
    $legId = dcmLeg($db, $userId, $txId, dcmSpendCategory($db, $userId, 'Groceries'), -2450, 0);
    dcmLeg($db, $userId, $txId, dcmSpendCategory($db, $userId, 'Household'), -10000, 1);

    // The whole-tx tag predates the split; it is not deleted, only superseded.
    dcmTag($db, $userId, $txId, null);
    dcmTag($db, $userId, $txId, $legId);

    $summary = app(TaxTagQuery::class)->summaryForUser($userId, 2026);

    expect($summary->totalMinor)->toBe(2450)
        ->and($summary->count)->toBe(1);
});

it('agrees with the cockpit it links to, on both the money and the count', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = dcmUser($db, 'dcm-agrees');

    $splitTxId = dcmTransaction($db, $userId);
    $legId = dcmLeg($db, $userId, $splitTxId, dcmSpendCategory($db, $userId, 'Groceries'), -2450, 0);
    dcmLeg($db, $userId, $splitTxId, dcmSpendCategory($db, $userId, 'Household'), -10000, 1);
    dcmTag($db, $userId, $splitTxId, null);
    dcmTag($db, $userId, $splitTxId, $legId);

    $wholeTxId = dcmTransaction($db, $userId, ['settled_amount_minor' => -4990, 'amount_minor' => -4990]);
    dcmTag($db, $userId, $wholeTxId);

    $summary = app(TaxTagQuery::class)->summaryForUser($userId, 2026);
    $cockpit = app(TaxYearQuery::class)->forUser($userId, 2026);

    expect($summary->totalMinor)->toBe($cockpit->deductionsTotalMinor)
        ->and($summary->count)->toBe($cockpit->itemCount)
        ->and($summary->totalMinor)->toBe(7440);
});

it('still reports the full amount for a whole-transaction tag on an unsplit transaction', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = dcmUser($db, 'dcm-unsplit');
    dcmTag($db, $userId, dcmTransaction($db, $userId, ['settled_amount_minor' => -9900, 'amount_minor' => -9900]));

    $summary = app(TaxTagQuery::class)->summaryForUser($userId, 2026);

    expect($summary->totalMinor)->toBe(9900)
        ->and($summary->count)->toBe(1);
});

it('still counts an income leg without adding it to the deductions total', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = dcmUser($db, 'dcm-income-leg');
    $txId = dcmTransaction($db, $userId, [
        'type' => 'income',
        'amount_minor' => 250000,
        'settled_amount_minor' => 250000,
    ]);
    $legId = dcmLeg($db, $userId, $txId, dcmSpendCategory($db, $userId, 'Fees'), 100000, 0);
    dcmLeg($db, $userId, $txId, dcmSpendCategory($db, $userId, 'Net'), 150000, 1);
    dcmTag($db, $userId, $txId, $legId);

    $summary = app(TaxTagQuery::class)->summaryForUser($userId, 2026);

    expect($summary->totalMinor)->toBe(0)
        ->and($summary->count)->toBe(1);
});
