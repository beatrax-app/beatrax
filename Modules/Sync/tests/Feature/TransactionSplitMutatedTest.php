<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// Capturing a split through TransactionMutated instead of its own event would
// silently write table_name = 'transactions' against a transaction_splits pk,
// and nothing quarantines that, so the literal column value is asserted here
// rather than inferred from the replay coming out right.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 10:00:00');

    $this->user = User::create([
        'username' => 'split-sync-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN split-sync',
        'slug' => 'split-sync-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/split-sync.xml',
        'sha256' => hash('sha256', 'split-sync-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'split-sync-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'split-sync-household-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);

    // Bound into the container because SyncCaptureListener resolves its writer
    // lazily and would otherwise never see a real one.
    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->pk = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'device-a',
        'userId' => $this->user->id,
        'secretKey' => $this->sk,
        'publicKey' => $this->pk,
    ]);
    app()->instance(OpLogWriter::class, $writer);

    $this->deviceKeys = ['device-a' => bin2hex($this->pk)];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function splitSyncTx(int $userId, int $accountId, int $runId, int $settledMinor): Transaction
{
    static $i = 900000;
    $i++;
    $today = CarbonImmutable::now()->toDateString();

    /** @var Transaction $tx */
    $tx = Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "SplitSyncM{$i}",
        'counterparty_normalized' => "splitsyncm{$i}",
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i,
        'fingerprint' => str_pad('splitsync'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    return $tx;
}

/**
 * @return list<OpLogEntry>
 */
function splitOpsAfter(DatabaseManager $db, int $userId, int $afterId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'transaction_splits')
        ->where('id', '>', $afterId)
        ->orderBy('id')
        ->get()
        ->map(static function (object $row): OpLogEntry {
            $pk = is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk;

            return new OpLogEntry(
                table: (string) $row->table_name,
                pk: $pk,
                field: (string) $row->field,
                value: $row->value !== null ? (string) $row->value : null,
                hlcL: (int) $row->hlc_l,
                hlcC: (int) $row->hlc_c,
                deviceId: (string) $row->device_id,
                opType: OpType::from((string) $row->op_type),
                signature: (string) $row->signature,
                userId: (int) $row->user_id,
            );
        })
        ->all();
}

function maxOpLogId(DatabaseManager $db): int
{
    $max = $db->connection()->table('op_log_entries')->max('id');

    return is_numeric($max) ? (int) $max : 0;
}

it('creating a split captures op-log entries with table_name transaction_splits, and a second device replays an identical split', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $tx = splitSyncTx((int) $this->user->id, (int) $this->account->id, (int) $this->run->id, -8000);
    $watermark = maxOpLogId($db);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => 'weekly shop'],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $createOps = $db->connection()->table('op_log_entries')
        ->where('user_id', $this->user->id)
        ->where('op_type', 'create_row')
        ->get();
    expect($createOps)->not->toBeEmpty();
    expect($createOps->pluck('table_name')->unique()->all())->toBe(['transaction_splits']);

    $capturedEntries = splitOpsAfter($db, (int) $this->user->id, $watermark);
    expect($capturedEntries)->not->toBeEmpty();

    // Stand in for a second, fresh device that has never seen these legs: drop
    // the rows and rebuild them from the captured ops alone.
    $db->connection()->table('transaction_splits')->where('transaction_id', $tx->id)->delete();
    expect($db->connection()->table('transaction_splits')->where('transaction_id', $tx->id)->count())->toBe(0);

    $replayer = new OpLogReplayer($db, $this->deviceKeys, new MergeRulesRegistry);
    $replayer->replay($capturedEntries, (int) $this->user->id);

    $reconstructed = $db->connection()->table('transaction_splits')->where('transaction_id', $tx->id)->get();
    expect($reconstructed)->toHaveCount(2);
    expect((int) $reconstructed->sum('settled_amount_minor'))->toBe(-8000);

    $groceriesLeg = $reconstructed->first(fn (object $row): bool => (int) $row->category_id === $this->groceries->id);
    $householdLeg = $reconstructed->first(fn (object $row): bool => (int) $row->category_id === $this->household->id);
    expect($groceriesLeg)->not->toBeNull();
    expect((int) $groceriesLeg->settled_amount_minor)->toBe(-6000);
    expect($groceriesLeg->settled_currency)->toBe('EUR');
    expect($householdLeg)->not->toBeNull();
    expect((int) $householdLeg->settled_amount_minor)->toBe(-2000);

    // Anything in quarantine would mean the _create_required columns did not match.
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});

it('editing a leg amount captures a Set op that a second device replays via LWW', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $tx = splitSyncTx((int) $this->user->id, (int) $this->account->id, (int) $this->run->id, -8000);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $legs = $db->connection()->table('transaction_splits')->where('transaction_id', $tx->id)->get();
    $groceriesLegId = (int) $legs->first(fn (object $row): bool => (int) $row->category_id === $this->groceries->id)->id;
    $householdLegId = (int) $legs->first(fn (object $row): bool => (int) $row->category_id === $this->household->id)->id;

    $watermark = maxOpLogId($db);

    // A rebalance: both legs change and the pair still sums to -8000.
    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => $groceriesLegId, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
        ['id' => $householdLegId, 'category_id' => $this->household->id, 'settled_amount_minor' => -3000, 'note' => null],
    ]);

    $editOps = splitOpsAfter($db, (int) $this->user->id, $watermark);
    $amountOps = collect($editOps)->filter(
        fn (OpLogEntry $e): bool => $e->field === 'settled_amount_minor' && $e->pk === $groceriesLegId,
    );
    expect($amountOps)->not->toBeEmpty('the groceries leg amount edit must be captured as a Set op');
    expect(collect($editOps)->pluck('table')->unique()->all())->toBe(['transaction_splits']);

    // A's write already committed the new amount locally, so the row goes back
    // to its pre-edit value to stand in for a device B that is behind.
    $db->connection()->table('transaction_splits')->where('id', $groceriesLegId)->update(['settled_amount_minor' => -6000]);
    expect((int) $db->connection()->table('transaction_splits')->where('id', $groceriesLegId)->value('settled_amount_minor'))->toBe(-6000);

    $replayer = new OpLogReplayer($db, $this->deviceKeys, new MergeRulesRegistry);
    $replayer->replay($editOps, (int) $this->user->id);

    expect((int) $db->connection()->table('transaction_splits')->where('id', $groceriesLegId)->value('settled_amount_minor'))->toBe(-5000);
});

it('unsplitting captures Delete ops that tombstone the legs on a second device', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $tx = splitSyncTx((int) $this->user->id, (int) $this->account->id, (int) $this->run->id, -8000);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $legRows = $db->connection()->table('transaction_splits')->where('transaction_id', $tx->id)->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all();
    $legIds = array_map(static fn (array $row): int => (int) $row['id'], $legRows);

    $watermark = maxOpLogId($db);

    app(SaveTransactionSplit::class)->unsplit($this->user, $tx->id, $this->groceries->id);

    expect($db->connection()->table('transaction_splits')->where('transaction_id', $tx->id)->count())->toBe(0);

    $deleteOps = splitOpsAfter($db, (int) $this->user->id, $watermark);
    $deleteOpsPks = collect($deleteOps)->pluck('pk')->all();
    foreach ($legIds as $legId) {
        expect($deleteOpsPks)->toContain($legId);
    }
    expect(collect($deleteOps)->map(fn (OpLogEntry $e): string => $e->opType->value)->unique()->all())->toBe(['delete_tombstone']);

    // A's unsplit already deleted the rows locally, so they go back under the
    // same primary keys for the tombstones to land on.
    foreach ($legRows as $row) {
        $db->connection()->table('transaction_splits')->insert($row);
    }
    expect($db->connection()->table('transaction_splits')->where('transaction_id', $tx->id)->count())->toBe(2);

    $replayer = new OpLogReplayer($db, $this->deviceKeys, new MergeRulesRegistry);
    $replayer->replay($deleteOps, (int) $this->user->id);

    expect($db->connection()->table('transaction_splits')->where('transaction_id', $tx->id)->count())->toBe(0);
});
