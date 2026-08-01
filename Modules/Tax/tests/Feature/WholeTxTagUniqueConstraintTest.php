<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Tax\Public\Actions\TagTransaction;

/*
 * Phase 13.3 Finding B regression: the 2026_07_04_000002 migration widened
 * the unique constraint to (user_id, transaction_id, transaction_split_id).
 * SQLite treats every NULL as a DISTINCT value for uniqueness purposes, so
 * that compound constraint no longer rejects two whole-transaction rows
 * (transaction_split_id IS NULL) for the same (user_id, transaction_id) —
 * silently breaking TagTransaction's IN-06 select-then-insert race guard,
 * which relies on catching UniqueConstraintViolationException. A
 * double-clicked "Tag" button can create two whole-tx rows, and
 * TaxYearQuery double-counts the deduction.
 *
 * The fix is a partial unique index (WHERE transaction_split_id IS NULL)
 * that restores DB-level uniqueness for whole-tx rows while leaving the
 * per-leg compound constraint (non-NULL transaction_split_id) intact.
 */

function wtcUser(DatabaseManager $db, string $username): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function wtcTransaction(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'WTC ASN '.$suffix,
        'slug' => 'wtc-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/wtc-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'wtc-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'wtc-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-04-15',
        'booked_at' => '2026-04-15 00:00:00',
        'value_date' => '2026-04-15',
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'wtc-vendor',
        'counterparty_name' => 'WTC Vendor BV',
        'normalization_version' => 1,
        'description' => 'WholeTxTagUniqueConstraint fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function wtcDeductionCategory(DatabaseManager $db, int $userId, string $name = 'WTC Category'): int
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

it('rejects a second whole-transaction tag row for the same (user_id, transaction_id) at the DB level', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = wtcUser($db, 'wtc-db-unique');
    $txId = wtcTransaction($db, $userId);

    $row = [
        'user_id' => $userId,
        'transaction_id' => $txId,
        'transaction_split_id' => null,
        'deduction_category_id' => null,
        'note' => null,
        'tax_year_override' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('tax_transaction_tags')->insert($row);

    expect(fn () => DB::table('tax_transaction_tags')->insert($row))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(DB::table('tax_transaction_tags')->where('transaction_id', $txId)->count())->toBe(1);
});

it('still allows two leg-scoped tag rows for the same transaction (non-NULL transaction_split_id constraint untouched)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = wtcUser($db, 'wtc-db-legs-ok');
    $txId = wtcTransaction($db, $userId);

    $groceries = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'WTC Groceries',
        'slug' => 'wtc-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $household = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'WTC Household',
        'slug' => 'wtc-household-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $legA = $db->connection()->table('transaction_splits')->insertGetId([
        'user_id' => $userId, 'transaction_id' => $txId, 'category_id' => $groceries,
        'settled_amount_minor' => -3000, 'settled_currency' => 'EUR', 'note' => null,
        'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $legB = $db->connection()->table('transaction_splits')->insertGetId([
        'user_id' => $userId, 'transaction_id' => $txId, 'category_id' => $household,
        'settled_amount_minor' => -2000, 'settled_currency' => 'EUR', 'note' => null,
        'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('tax_transaction_tags')->insert([
        'user_id' => $userId, 'transaction_id' => $txId, 'transaction_split_id' => $legA,
        'deduction_category_id' => null, 'note' => null, 'tax_year_override' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('tax_transaction_tags')->insert([
        'user_id' => $userId, 'transaction_id' => $txId, 'transaction_split_id' => $legB,
        'deduction_category_id' => null, 'note' => null, 'tax_year_override' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(DB::table('tax_transaction_tags')->where('transaction_id', $txId)->count())->toBe(2);
});

it('TagTransaction survives a lost select-then-insert race for a whole-tx tag — exactly one row remains (IN-06)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = wtcUser($db, 'wtc-race');
    $txId = wtcTransaction($db, $userId);
    $catId = wtcDeductionCategory($db, $userId);

    // Simulate a concurrent request winning the TOCTOU race: right after
    // TagTransaction's own `exists()` check on tax_transaction_tags reports
    // "no row yet", inject a competing whole-tx row directly — mirroring a
    // second in-flight request that already committed its insert.
    $raceInjected = false;
    DB::listen(function ($query) use (&$raceInjected, $db, $userId, $txId): void {
        if ($raceInjected) {
            return;
        }
        if (! str_contains($query->sql, 'tax_transaction_tags')) {
            return;
        }
        if (! str_contains(strtolower($query->sql), 'select')) {
            return;
        }
        $raceInjected = true;
        $db->connection()->table('tax_transaction_tags')->insert([
            'user_id' => $userId,
            'transaction_id' => $txId,
            'transaction_split_id' => null,
            'deduction_category_id' => null,
            'note' => 'Concurrent winner',
            'tax_year_override' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    app(TagTransaction::class)->execute($userId, $txId, $catId, 'My note', null);

    $rows = DB::table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->whereNull('transaction_split_id')
        ->get();

    expect($rows)->toHaveCount(1);
});
