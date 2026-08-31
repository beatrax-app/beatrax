<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tax\Public\Actions\TagTransaction;
use Modules\Tax\Public\Actions\UntagTransaction;

uses(RefreshDatabase::class);

function trrUser(DatabaseManager $db, string $username): int
{
    return $db->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => bcrypt('test'),
        'period_start_day' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function trrTransaction(DatabaseManager $db, int $userId, string $status): int
{
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TRR ASN '.$suffix,
        'slug' => 'trr-asn-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/trr-run-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'trr-run-'.$suffix),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'trr-tx-'.bin2hex(random_bytes(8))),
        'posted_at' => '2026-01-15',
        'booked_at' => '2026-01-15 00:00:00',
        'value_date' => '2026-01-15',
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'test-vendor',
        'counterparty_name' => 'Test Vendor BV',
        'normalization_version' => 1,
        'description' => 'Reconciled tax-write-path fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function trrDeductionCategory(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Vakliteratuur',
        'short_name' => 'Lit',
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// The four Livewire callers pre-check the lock, so the paths that reach these
// actions on a reconciled row are the ones with no page in front of them: the
// rule engine, a bulk tag, a replay.
it('refuses to tag a reconciled transaction from the action itself', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = trrUser($db, 'trr-tag-locked');
    $txId = trrTransaction($db, $userId, 'reconciled');
    $categoryId = trrDeductionCategory($db, $userId);

    app(TagTransaction::class)->execute($userId, $txId, $categoryId, null, null);

    expect($db->connection()->table('tax_transaction_tags')->where('transaction_id', $txId)->exists())->toBeFalse();
});

it('still tags a cleared transaction from the action itself', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = trrUser($db, 'trr-tag-cleared');
    $txId = trrTransaction($db, $userId, 'cleared');
    $categoryId = trrDeductionCategory($db, $userId);

    app(TagTransaction::class)->execute($userId, $txId, $categoryId, null, null);

    expect($db->connection()->table('tax_transaction_tags')->where('transaction_id', $txId)->exists())->toBeTrue();
});

it('refuses to untag a reconciled transaction from the action itself', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = trrUser($db, 'trr-untag-locked');
    $txId = trrTransaction($db, $userId, 'cleared');
    $categoryId = trrDeductionCategory($db, $userId);

    app(TagTransaction::class)->execute($userId, $txId, $categoryId, null, null);
    $db->connection()->table('transactions')->where('id', $txId)->update(['status' => 'reconciled']);

    app(UntagTransaction::class)->execute($userId, $txId);

    expect($db->connection()->table('tax_transaction_tags')->where('transaction_id', $txId)->exists())->toBeTrue();
});
