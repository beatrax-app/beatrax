<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/*
 * Unit tests for Phase 13.1 D-06a: leg-aware TagTransaction/UntagTransaction.
 *
 * Tests cover:
 *  - Tagging leg A (not leg B) produces exactly one tag row keyed to leg A.
 *  - Tagging both legs produces two independent rows.
 *  - A whole-transaction tag (transactionSplitId=null) still works for
 *    unsplit transactions (backward compatibility).
 *  - Untagging leg A leaves leg B's tag and any whole-tx tag intact.
 *  - A cross-user leg id is rejected (T-13.1-09).
 */

/**
 * Inserts a minimal user row and returns its id.
 */
function tstlUser(DatabaseManager $db, string $username): int
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
 * Inserts an account + import run + transaction for the given user and
 * returns the transaction id.
 */
function tstlTransaction(DatabaseManager $db, int $userId, int $settledAmountMinor = -8000): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TSTL ASN '.$suffix,
        'slug' => 'tstl-asn-'.$suffix,
        'kind' => 'asn',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tstl-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'tstl-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tstl-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-01-15',
        'booked_at' => '2026-01-15 00:00:00',
        'value_date' => '2026-01-15',
        'amount_minor' => $settledAmountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledAmountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'test-vendor',
        'counterparty_name' => 'Test Vendor BV',
        'normalization_version' => 1,
        'description' => 'TagTransactionLeg test transaction',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Inserts a deduction category for the user and returns its id.
 */
function tstlDeductionCategory(DatabaseManager $db, int $userId, string $name = 'Test Category'): int
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
 * Inserts a spend category (Ledger's categories table — distinct from
 * tax_deduction_categories) and returns its id.
 */
function tstlSpendCategory(DatabaseManager $db, int $userId, string $name): int
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

/**
 * Inserts a transaction_splits leg row and returns its id.
 */
function tstlLeg(DatabaseManager $db, int $userId, int $transactionId, int $categoryId, int $settledAmountMinor, int $sortOrder = 0): int
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

// -----------------------------------------------------------------------

it('tags one leg without affecting a sibling leg — exactly one row keyed to that leg', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = tstlUser($db, 'leg-tag-a');
    $txId = tstlTransaction($db, $userId);
    $groceries = tstlSpendCategory($db, $userId, 'Groceries');
    $household = tstlSpendCategory($db, $userId, 'Household');
    $legA = tstlLeg($db, $userId, $txId, $groceries, -6000, 0);
    $legB = tstlLeg($db, $userId, $txId, $household, -2000, 1);
    $deductionCatId = tstlDeductionCategory($db, $userId);

    app(TagTransaction::class)->execute($userId, $txId, $deductionCatId, 'Leg A note', null, $legA);

    $rows = $db->connection()->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->get();

    expect($rows)->toHaveCount(1);
    expect((int) $rows->first()->transaction_split_id)->toBe($legA);
    expect($rows->first()->note)->toBe('Leg A note');

    // Leg B has no tag.
    $legBTagged = $db->connection()->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->where('transaction_split_id', $legB)
        ->exists();
    expect($legBTagged)->toBeFalse();
});

it('tags both legs independently — two rows, different states', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = tstlUser($db, 'leg-tag-both');
    $txId = tstlTransaction($db, $userId);
    $groceries = tstlSpendCategory($db, $userId, 'Groceries');
    $household = tstlSpendCategory($db, $userId, 'Household');
    $legA = tstlLeg($db, $userId, $txId, $groceries, -6000, 0);
    $legB = tstlLeg($db, $userId, $txId, $household, -2000, 1);
    $catA = tstlDeductionCategory($db, $userId, 'Deduction A');
    $catB = tstlDeductionCategory($db, $userId, 'Deduction B');

    app(TagTransaction::class)->execute($userId, $txId, $catA, 'A note', null, $legA);
    app(TagTransaction::class)->execute($userId, $txId, $catB, 'B note', null, $legB);

    $rows = $db->connection()->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->get()
        ->keyBy('transaction_split_id');

    expect($rows)->toHaveCount(2);
    expect((int) $rows[$legA]->deduction_category_id)->toBe($catA);
    expect($rows[$legA]->note)->toBe('A note');
    expect((int) $rows[$legB]->deduction_category_id)->toBe($catB);
    expect($rows[$legB]->note)->toBe('B note');
});

it('a whole-transaction tag (transactionSplitId=null) still works for unsplit transactions', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = tstlUser($db, 'leg-tag-unsplit');
    $txId = tstlTransaction($db, $userId);
    $catId = tstlDeductionCategory($db, $userId);

    app(TagTransaction::class)->execute($userId, $txId, $catId, 'Whole tx note', null);

    $row = $db->connection()->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->transaction_split_id)->toBeNull();
    expect($row->note)->toBe('Whole tx note');
});

it('re-tagging the same leg is idempotent — one row, updated values', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = tstlUser($db, 'leg-tag-idempotent');
    $txId = tstlTransaction($db, $userId);
    $groceries = tstlSpendCategory($db, $userId, 'Groceries');
    $legA = tstlLeg($db, $userId, $txId, $groceries, -6000, 0);
    $catId = tstlDeductionCategory($db, $userId);

    app(TagTransaction::class)->execute($userId, $txId, $catId, 'First', null, $legA);
    app(TagTransaction::class)->execute($userId, $txId, $catId, 'Updated', null, $legA);

    $rows = $db->connection()->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->where('transaction_split_id', $legA)
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->note)->toBe('Updated');
});

it('untagging leg A leaves leg B\'s tag and a whole-tx tag intact', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = tstlUser($db, 'leg-untag-scoped');
    $txId = tstlTransaction($db, $userId);
    $groceries = tstlSpendCategory($db, $userId, 'Groceries');
    $household = tstlSpendCategory($db, $userId, 'Household');
    $legA = tstlLeg($db, $userId, $txId, $groceries, -6000, 0);
    $legB = tstlLeg($db, $userId, $txId, $household, -2000, 1);
    $catId = tstlDeductionCategory($db, $userId);

    app(TagTransaction::class)->execute($userId, $txId, $catId, null, null, $legA);
    app(TagTransaction::class)->execute($userId, $txId, $catId, null, null, $legB);

    app(UntagTransaction::class)->execute($userId, $txId, $legA);

    $legATagged = $db->connection()->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->where('transaction_split_id', $legA)
        ->exists();
    $legBTagged = $db->connection()->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->where('transaction_split_id', $legB)
        ->exists();

    expect($legATagged)->toBeFalse();
    expect($legBTagged)->toBeTrue();
});

it('untagging a leg never deletes a whole-transaction tag on the same transaction', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = tstlUser($db, 'leg-untag-vs-whole');
    $txId = tstlTransaction($db, $userId);
    $groceries = tstlSpendCategory($db, $userId, 'Groceries');
    $legA = tstlLeg($db, $userId, $txId, $groceries, -6000, 0);
    $catId = tstlDeductionCategory($db, $userId);

    // A whole-tx tag (transaction_split_id IS NULL) predates the split.
    app(TagTransaction::class)->execute($userId, $txId, $catId, null, null);
    // Now the leg gets its own tag.
    app(TagTransaction::class)->execute($userId, $txId, $catId, null, null, $legA);

    app(UntagTransaction::class)->execute($userId, $txId, $legA);

    $wholeTagStillExists = $db->connection()->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->where('transaction_id', $txId)
        ->whereNull('transaction_split_id')
        ->exists();

    expect($wholeTagStillExists)->toBeTrue();
});

it('rejects a cross-user leg id — 404, no tag row created (T-13.1-09)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = tstlUser($db, 'leg-xuser-owner');
    $intruder = tstlUser($db, 'leg-xuser-intruder');
    $txId = tstlTransaction($db, $owner);
    $groceries = tstlSpendCategory($db, $owner, 'Groceries');
    $legA = tstlLeg($db, $owner, $txId, $groceries, -8000, 0);

    // Intruder owns no transaction with this id, so the transaction
    // ownership guard fires first — either way, no write occurs and a
    // NotFoundHttpException surfaces.
    expect(fn () => app(TagTransaction::class)->execute($intruder, $txId, null, null, null, $legA))
        ->toThrow(NotFoundHttpException::class);

    $anyTagRow = $db->connection()->table('tax_transaction_tags')
        ->where('transaction_id', $txId)
        ->exists();
    expect($anyTagRow)->toBeFalse();
});

it('rejects a leg id that belongs to a different transaction (T-13.1-09)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userId = tstlUser($db, 'leg-wrong-tx');
    $txId1 = tstlTransaction($db, $userId);
    $txId2 = tstlTransaction($db, $userId);
    $groceries = tstlSpendCategory($db, $userId, 'Groceries');
    // legOfTx2 belongs to txId2, not txId1.
    $legOfTx2 = tstlLeg($db, $userId, $txId2, $groceries, -8000, 0);

    expect(fn () => app(TagTransaction::class)->execute($userId, $txId1, null, null, null, $legOfTx2))
        ->toThrow(NotFoundHttpException::class);

    $anyTagRow = $db->connection()->table('tax_transaction_tags')
        ->where('transaction_id', $txId1)
        ->exists();
    expect($anyTagRow)->toBeFalse();
});
