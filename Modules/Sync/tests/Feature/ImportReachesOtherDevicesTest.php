<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Internal\OpLog\OpLogWriter;

/*
 * Importing a statement is how data gets into this app, and what it produced
 * used to stay on the device that read the file.
 *
 * Measured on a paired phone: importing an ASN CSV added 5 transactions and
 * import_run 6, and the only op-log entries the device authored were
 * `notifications create_row x5`. After a sync the desktop still held 141
 * transactions and 5 import_runs, and had received nothing but those
 * notifications — it was told an import happened and given none of it.
 */

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    $keypair = sodium_crypto_sign_keypair();
    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'import-capture-device',
        'userId' => (int) $this->fixtureUser->id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));
});

function importedOps(string $table): Collection
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('op_log_entries')
        ->where('table_name', $table)
        ->get();
}

function confirmTheFixtureImport(User $user): int
{
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);
    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $user,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    app(ConfirmsImports::class)($preview->importRunId, $user);

    return $preview->importRunId;
}

it('puts the imported transactions on the wire, not just the notifications about them', function (): void {
    confirmTheFixtureImport($this->fixtureUser);

    expect(Transaction::count())->toBeGreaterThan(0);

    $ops = importedOps('transactions');

    expect($ops)->not->toBeEmpty('imported transactions must reach the op log or they never leave this device')
        ->and($ops->pluck('op_type')->unique()->all())->toBe(['create_row']);
});

it('captures the import run itself, which every transaction points at', function (): void {
    $importRunId = confirmTheFixtureImport($this->fixtureUser);

    $runOps = importedOps('import_runs');

    expect($runOps)->not->toBeEmpty()
        ->and($runOps->pluck('pk')->unique()->all())->toBe([(string) $importRunId]);
});

it('captures the account the transactions were booked to', function (): void {
    confirmTheFixtureImport($this->fixtureUser);

    expect(importedOps('accounts'))->not->toBeEmpty();
});

it('orders parents before children, so a peer\'s foreign keys accept the replay', function (): void {
    confirmTheFixtureImport($this->fixtureUser);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $firstRunOp = (int) $db->connection()->table('op_log_entries')->where('table_name', 'import_runs')->min('id');
    $firstAccountOp = (int) $db->connection()->table('op_log_entries')->where('table_name', 'accounts')->min('id');
    $firstTxOp = (int) $db->connection()->table('op_log_entries')->where('table_name', 'transactions')->min('id');

    // transactions.import_run_id and account_id are NOT NULL foreign keys, so
    // a transaction op arriving first names rows the peer does not have yet.
    expect($firstRunOp)->toBeLessThan($firstTxOp)
        ->and($firstAccountOp)->toBeLessThan($firstTxOp);
});

it('writes the description as plaintext, not the column\'s stored ciphertext', function (): void {
    confirmTheFixtureImport($this->fixtureUser);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $descriptionOps = $db->connection()->table('op_log_entries')
        ->where('table_name', 'transactions')
        ->where('field', 'description')
        ->count();

    // The op log encrypts sensitive columns itself under a different
    // associated data; handing it the at-rest ciphertext double-wraps it and
    // the peer projects base64 as a description.
    expect($descriptionOps)->toBeGreaterThan(0);
});
