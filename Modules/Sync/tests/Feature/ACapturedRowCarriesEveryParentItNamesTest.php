<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Services\ImportSyncCapture;

uses(RefreshDatabase::class);

// The capture emitted the parents of a transaction from a list of three table
// names written by hand — import_runs, accounts, transactions. transactions
// also has a foreign key onto categories, and that one was not in the list, so
// a peer received a transaction naming a category it had never been sent. Its
// foreign key refused the insert and the row was quarantined as
// missing_reference, which nothing ever retries: two charges went missing from
// a paired phone with nothing on either screen saying so.

function parentCaptureUser(): User
{
    return User::query()->create([
        'username' => 'parentcap-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function parentCaptureWriter(User $user): void
{
    $keypair = sodium_crypto_sign_keypair();

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'parentcap-device',
        'userId' => (int) $user->id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));
}

/** @return array{0: User, 1: int} the user and the transaction it recorded */
function parentCaptureTransaction(): array
{
    $user = parentCaptureUser();
    parentCaptureWriter($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    $accountId = $connection->table('accounts')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Parent capture',
        'slug' => 'parentcap-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00PCAP'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $runId = $connection->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/parentcap.csv',
        'sha256' => hash('sha256', 'parentcap-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    // The row the old list forgot. It exists before the transaction, exactly as
    // the category did on the machine this was measured on — an older row the
    // import merely points at.
    $categoryId = $connection->table('categories')->insertGetId([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'slug' => 'groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $transactionId = (int) $connection->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'category_id' => $categoryId,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => '2026-01-15',
        'booked_at' => '2026-01-15 00:00:01',
        'value_date' => '2026-01-15',
        'amount_minor' => -4500,
        'currency' => 'EUR',
        'settled_amount_minor' => -4500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'parentcap-fp-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'created_at' => '2026-01-15 00:00:00',
        'updated_at' => '2026-01-15 00:00:00',
    ]);

    app(ImportSyncCapture::class)->captureTransactions([$transactionId], $user);

    return [$user, $transactionId];
}

/** @return list<string> tables this user has op-log entries for */
function parentCaptureTables(User $user): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $tables = $db->connection()->table('op_log_entries')
        ->where('user_id', $user->id)
        ->distinct()
        ->pluck('table_name')
        ->all();

    return array_values(array_map(static fn (mixed $t): string => (string) $t, $tables));
}

it('emits the category a captured transaction names', function (): void {
    [$user] = parentCaptureTransaction();

    expect(parentCaptureTables($user))->toContain('categories');
});

it('still emits the parents it always did', function (): void {
    [$user] = parentCaptureTransaction();

    expect(parentCaptureTables($user))
        ->toContain('accounts')
        ->toContain('import_runs')
        ->toContain('transactions');
});

// The guard against the next forgotten one. Derived from the live foreign keys
// rather than restated here, so a column added to transactions tomorrow is
// covered without anyone remembering this file exists.
it('emits every covered parent the schema says a transaction has', function (): void {
    [$user] = parentCaptureTransaction();

    $captured = parentCaptureTables($user);
    $missing = [];

    foreach (app(CoveredTableOrder::class)->parentColumns('transactions') as $parent) {
        // user_id is seeded by the applier from the local user, never replayed
        // from the wire, so the peer's own users row is deliberately not sent.
        if ($parent === 'users' || in_array($parent, $captured, true)) {
            continue;
        }

        $missing[] = $parent;
    }

    expect($missing)->toBe([], 'parents named by a transaction but never captured: '.implode(', ', $missing));
});
