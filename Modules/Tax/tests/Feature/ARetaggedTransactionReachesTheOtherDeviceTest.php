<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Tax\Public\Actions\TagTransaction;

uses(RefreshDatabase::class);

// Two defects in one action, both found on a paired desktop and phone. The
// capture named five columns and `note` was not among them, so a tax note never
// left the device that typed it. And every re-tag was announced as a create,
// which a peer holding the row already ignores — so the two devices disagreed
// about the category for good, with nothing quarantined and no error raised.

function retagUserId(DatabaseManager $db): int
{
    $id = (int) $db->connection()->table('users')->insertGetId([
        'username' => 'retag-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $keypair = sodium_crypto_sign_keypair();

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'retag-device',
        'userId' => $id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));

    return $id;
}

function retagTransactionId(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));
    $connection = $db->connection();

    $accountId = $connection->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Retag '.$suffix, 'slug' => 'retag-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL00RTAG'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $runId = $connection->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/retag-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'retag-'.$suffix), 'uploaded_at' => now(), 'status' => 'committed',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return (int) $connection->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'retag-tx-'.$suffix), 'fingerprint_version' => 3,
        'posted_at' => '2026-01-15', 'booked_at' => '2026-01-15 00:00:00', 'value_date' => '2026-01-15',
        'amount_minor' => -8000, 'currency' => 'EUR', 'settled_amount_minor' => -8000, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'retag-vendor', 'counterparty_name' => 'Retag Vendor BV',
        'normalization_version' => 1, 'description' => 'retag fixture', 'type' => 'expense',
        'source_format' => 'asn-csv', 'source_row_index' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function retagCategoryId(DatabaseManager $db, int $userId, string $name): int
{
    return (int) $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId, 'name' => $name, 'short_name' => substr($name, 0, 3),
        'status' => 'active', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return list<array{field: string, op: string}> this user's tax-tag ops, oldest first */
function retagOps(DatabaseManager $db, int $userId): array
{
    $rows = [];

    foreach ($db->connection()->table('op_log_entries')
        ->where('user_id', $userId)->where('table_name', 'tax_transaction_tags')->orderBy('id')->get() as $row) {
        $rows[] = ['field' => (string) $row->field, 'op' => (string) $row->op_type];
    }

    return $rows;
}

it('sends the note the first tag wrote', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = retagUserId($db);
    $txId = retagTransactionId($db, $userId);
    $catId = retagCategoryId($db, $userId, 'Zorgkosten');

    app(TagTransaction::class)->execute($userId, $txId, $catId, 'Tandarts rekening', null, null);

    $fields = array_column(retagOps($db, $userId), 'field');

    expect($fields)->toContain('note');
});

it('announces a re-tag as an edit, which is the only shape a peer applies', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = retagUserId($db);
    $txId = retagTransactionId($db, $userId);
    $first = retagCategoryId($db, $userId, 'Zorgkosten');
    $second = retagCategoryId($db, $userId, 'Giften');

    app(TagTransaction::class)->execute($userId, $txId, $first, 'Eerste notitie', null, null);
    $afterCreate = count(retagOps($db, $userId));

    app(TagTransaction::class)->execute($userId, $txId, $second, 'Tweede notitie', null, null);

    $edit = array_slice(retagOps($db, $userId), $afterCreate);

    expect($edit)->not->toBeEmpty('a re-tag that emits nothing leaves the peer on the old category');
    expect(array_unique(array_column($edit, 'op')))->toBe(['set']);
    expect(array_column($edit, 'field'))->toContain('deduction_category_id')->toContain('note');
});

// updateExisting() leaves the three payload columns alone when every one of them
// is null, so announcing them anyway would send three null sets and wipe values
// the peer still holds.
it('does not wipe the peer when a re-tag carries nothing', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = retagUserId($db);
    $txId = retagTransactionId($db, $userId);
    $catId = retagCategoryId($db, $userId, 'Zorgkosten');

    app(TagTransaction::class)->execute($userId, $txId, $catId, 'Eerste notitie', null, null);
    $afterCreate = count(retagOps($db, $userId));

    app(TagTransaction::class)->execute($userId, $txId, null, null, null, null);

    expect(array_column(array_slice(retagOps($db, $userId), $afterCreate), 'field'))->toBe(['updated_at']);
});
