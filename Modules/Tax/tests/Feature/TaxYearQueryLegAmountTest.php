<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Tax\Public\Services\TaxYearQuery;

/*
 * Feature tests for Phase 13.1 D-06a: leg-aware TaxYearQuery amount
 * resolution + whole-tx-tag supersession.
 *
 * Tests cover:
 *  - A €80 split (€60 Groceries tagged deductible / €20 Household untagged)
 *    reports €60 deductible for the year — not €80, not €0.
 *  - A whole-transaction tag on an UNSPLIT transaction still reports the
 *    full transaction amount (regression).
 *  - Supersession: a stale whole-tx tag on a NOW-split transaction is
 *    excluded once a leg tag exists for that same transaction.
 */

function tylaUser(DatabaseManager $db, string $username): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function tylaTransaction(DatabaseManager $db, int $userId, int $settledAmountMinor): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TYLA ASN '.$suffix,
        'slug' => 'tyla-asn-'.$suffix,
        'kind' => 'asn',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tyla-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'tyla-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tyla-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-02-10',
        'booked_at' => '2026-02-10 00:00:00',
        'value_date' => '2026-02-10',
        'amount_minor' => $settledAmountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledAmountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'tyla-vendor',
        'counterparty_name' => 'TYLA Vendor BV',
        'normalization_version' => 1,
        'description' => 'TaxYearQueryLegAmount test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function tylaSpendCategory(DatabaseManager $db, int $userId, string $name): int
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

function tylaLeg(DatabaseManager $db, int $userId, int $transactionId, int $categoryId, int $settledAmountMinor, int $sortOrder = 0): int
{
    return $db->connection()->table('transaction_splits')->insertGetId([
        'user_id' => $userId,
        'transaction_id' => $transactionId,
        'category_id' => $categoryId,
        'settled_amount_minor' => $settledAmountMinor,
        'settled_currency' => 'EUR',
        'note' => null,
        'sort_order' => $sortOrder,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function tylaDeductionCategory(DatabaseManager $db, int $userId, string $name = 'Test Deduction'): int
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

/**
 * Inserts a tax_transaction_tags row (optionally leg-scoped) and returns
 * its id.
 */
function tylaTag(DatabaseManager $db, int $userId, int $txId, ?int $catId, ?int $transactionSplitId = null): int
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

// -----------------------------------------------------------------------

it('reports the LEG amount for a leg-scoped tag on a split — not the whole parent (€60, not €80 or €0)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = tylaUser($db, 'tyla-leg-amount');
    $txId = tylaTransaction($db, $userId, -8000);
    $groceries = tylaSpendCategory($db, $userId, 'Groceries');
    $household = tylaSpendCategory($db, $userId, 'Household');
    $groceriesLeg = tylaLeg($db, $userId, $txId, $groceries, -6000, 0);
    tylaLeg($db, $userId, $txId, $household, -2000, 1); // untagged leg

    $deductionCat = tylaDeductionCategory($db, $userId, 'Groceries Deduction');
    tylaTag($db, $userId, $txId, $deductionCat, $groceriesLeg);

    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);
    $result = $query->forUser($userId, 2026);

    expect($result->itemCount)->toBe(1)
        ->and($result->deductionsTotalMinor)->toBe(6000); // not 8000, not 0
});

it('a whole-transaction tag on an UNSPLIT transaction still reports the full amount (regression)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = tylaUser($db, 'tyla-unsplit-whole');
    $txId = tylaTransaction($db, $userId, -9900);
    $deductionCat = tylaDeductionCategory($db, $userId, 'Whole Tx Deduction');

    // Whole-transaction tag — transaction_split_id is null, no legs exist.
    tylaTag($db, $userId, $txId, $deductionCat, null);

    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);
    $result = $query->forUser($userId, 2026);

    expect($result->itemCount)->toBe(1)
        ->and($result->deductionsTotalMinor)->toBe(9900);
});

it('excludes a stale whole-tx tag once the same transaction has a leg tag (supersession)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = tylaUser($db, 'tyla-supersede');
    $txId = tylaTransaction($db, $userId, -8000);
    $groceries = tylaSpendCategory($db, $userId, 'Groceries');
    $household = tylaSpendCategory($db, $userId, 'Household');
    $groceriesLeg = tylaLeg($db, $userId, $txId, $groceries, -6000, 0);
    tylaLeg($db, $userId, $txId, $household, -2000, 1);

    $deductionCat = tylaDeductionCategory($db, $userId, 'Supersede Deduction');

    // Stale whole-tx tag predates the split.
    tylaTag($db, $userId, $txId, $deductionCat, null);
    // The transaction is now split and one leg carries its own tag.
    tylaTag($db, $userId, $txId, $deductionCat, $groceriesLeg);

    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);
    $result = $query->forUser($userId, 2026);

    // Only the leg row surfaces — the whole-tx row is superseded, so the
    // total is €60 (the leg), not €140 (€80 whole + €60 leg double-count)
    // and not €80.
    expect($result->itemCount)->toBe(1)
        ->and($result->deductionsTotalMinor)->toBe(6000);
});

it('cross-user isolation still holds for leg-scoped tags', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = tylaUser($db, 'tyla-iso-owner');
    $intruder = tylaUser($db, 'tyla-iso-intruder');

    $txId = tylaTransaction($db, $owner, -8000);
    $groceries = tylaSpendCategory($db, $owner, 'Groceries');
    $leg = tylaLeg($db, $owner, $txId, $groceries, -6000, 0);
    $deductionCat = tylaDeductionCategory($db, $owner, 'Owner Deduction');
    tylaTag($db, $owner, $txId, $deductionCat, $leg);

    /** @var TaxYearQuery $query */
    $query = app(TaxYearQuery::class);
    $result = $query->forUser($intruder, 2026);

    expect($result->itemCount)->toBe(0)
        ->and($result->deductionsTotalMinor)->toBe(0);
});
