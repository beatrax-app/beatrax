<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\CashBook\Internal\Actions\RecordManualTransaction;
use Modules\Sync\Internal\OpLog\OpLogWriter;

// Every writer of ledger rows — imports, the cash book, receipts, migration —
// records through the same action, so that is where capture belongs. Capturing
// only the import path meant a cash entry produced notification ops and nothing
// else, while goals written in the same session travelled fine.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    $keypair = sodium_crypto_sign_keypair();
    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'manual-entry-device',
        'userId' => (int) $this->fixtureUser->id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]));
});

function manualOps(string $table): Collection
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('op_log_entries')->where('table_name', $table)->get();
}

it('captures a hand-typed cash entry, not just an imported one', function (): void {
    app(RecordManualTransaction::class)(
        $this->fixtureUser,
        'expense',
        1234,
        CarbonImmutable::parse('2026-08-19'),
        'Bakkerij',
        null,
        'kasboek test',
    );

    $ops = manualOps('transactions');

    expect($ops)->not->toBeEmpty('a cash entry must reach the op log or it stays on the device that typed it')
        ->and($ops->pluck('op_type')->unique()->all())->toBe(['create_row']);
});

it('captures the cash account and manual run the entry hangs off', function (): void {
    app(RecordManualTransaction::class)(
        $this->fixtureUser,
        'expense',
        1234,
        CarbonImmutable::parse('2026-08-19'),
        'Bakkerij',
        null,
        'kasboek test',
    );

    // transactions.import_run_id and account_id are NOT NULL foreign keys, so
    // the peer needs both before the transaction lands.
    expect(manualOps('import_runs'))->not->toBeEmpty()
        ->and(manualOps('accounts'))->not->toBeEmpty();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $firstRun = (int) $db->connection()->table('op_log_entries')->where('table_name', 'import_runs')->min('id');
    $firstTx = (int) $db->connection()->table('op_log_entries')->where('table_name', 'transactions')->min('id');

    expect($firstRun)->toBeLessThan($firstTx);
});
