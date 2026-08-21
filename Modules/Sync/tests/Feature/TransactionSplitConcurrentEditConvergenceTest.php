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

// transaction_splits is the first editable child table, so this is the first
// place two devices can field-merge the same logical child row. Both hold
// identical leg PKs, edit different fields offline, and the merged replay has to
// land both edits on the same leg — per-field LWW, not whole-row.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 11:00:00');

    $this->user = User::create([
        'username' => 'split-concurrent-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN split-concurrent',
        'slug' => 'split-concurrent-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/split-concurrent.xml',
        'sha256' => hash('sha256', 'split-concurrent-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'split-conc-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'split-conc-household-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function concurrentSplitTx(int $userId, int $accountId, int $runId, int $settledMinor): Transaction
{
    static $i = 950000;
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
        'counterparty_name' => "ConcurrentM{$i}",
        'counterparty_normalized' => "concurrentm{$i}",
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i,
        'fingerprint' => str_pad('concurrent'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    return $tx;
}

// Bound into the container because SyncCaptureListener resolves its writer lazily.
function bindDeviceWriter(int $userId, string $deviceId): string
{
    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pk = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => $sk,
        'publicKey' => $pk,
    ]);
    app()->instance(OpLogWriter::class, $writer);

    return bin2hex($pk);
}

/**
 * @return list<OpLogEntry>
 */
function concurrentSplitOpsAfter(DatabaseManager $db, int $userId, int $afterId): array
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

function concurrentMaxOpLogId(DatabaseManager $db): int
{
    $max = $db->connection()->table('op_log_entries')->max('id');

    return is_numeric($max) ? (int) $max : 0;
}

it('two devices concurrently editing the SAME split converge under per-field LWW with stable leg PKs, both edits merged, and an exact sum-to-parent', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // The origin seeds the split: the state both devices are assumed to already
    // hold identically, down to the leg PKs.
    bindDeviceWriter((int) $this->user->id, 'device-origin');
    $tx = concurrentSplitTx((int) $this->user->id, (int) $this->account->id, (int) $this->run->id, -8000);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
    ]);

    $originalLegs = $db->connection()->table('transaction_splits')->where('transaction_id', $tx->id)->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all();
    expect($originalLegs)->toHaveCount(2);

    $groceriesLegId = (int) collect($originalLegs)->first(fn (array $r): bool => (int) $r['category_id'] === $this->groceries->id)['id'];
    $householdLegId = (int) collect($originalLegs)->first(fn (array $r): bool => (int) $r['category_id'] === $this->household->id)['id'];
    $originalLegIds = [$groceriesLegId, $householdLegId];
    sort($originalLegIds);

    $pkA = bindDeviceWriter((int) $this->user->id, 'device-a');
    $watermarkA = concurrentMaxOpLogId($db);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => $groceriesLegId, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
        ['id' => $householdLegId, 'category_id' => $this->household->id, 'settled_amount_minor' => -3000, 'note' => null],
    ]);

    $aOps = concurrentSplitOpsAfter($db, (int) $this->user->id, $watermarkA);

    // A never touched note, category_id or sort_order, so only the two
    // settled_amount_minor ops may be captured.
    expect(collect($aOps)->pluck('field')->unique()->sort()->values()->all())->toBe(['settled_amount_minor']);
    expect(collect($aOps))->toHaveCount(2);

    // Back to the pre-A values: device B is offline and has not seen the
    // rebalance, so this is only how B's own diff comes out right below.
    foreach ($originalLegs as $row) {
        $db->connection()->table('transaction_splits')->where('id', $row['id'])->update([
            'settled_amount_minor' => $row['settled_amount_minor'],
            'note' => $row['note'],
        ]);
    }

    $pkB = bindDeviceWriter((int) $this->user->id, 'device-b');
    $watermarkB = concurrentMaxOpLogId($db);

    app(SaveTransactionSplit::class)->save($this->user, $tx->id, [
        ['id' => $groceriesLegId, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
        ['id' => $householdLegId, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => 'flagged by partner'],
    ]);

    $bOps = concurrentSplitOpsAfter($db, (int) $this->user->id, $watermarkB);

    // B never touched the amounts, only the household leg's note.
    expect(collect($bOps)->pluck('field')->unique()->all())->toBe(['note']);
    expect(collect($bOps))->toHaveCount(1);
    expect((int) $bOps[0]->pk)->toBe($householdLegId);

    $deviceKeys = ['device-a' => $pkA, 'device-b' => $pkB];
    $replayer = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayer->replay([...$aOps, ...$bOps], (int) $this->user->id);

    $converged = $db->connection()->table('transaction_splits')->where('transaction_id', $tx->id)->get();
    expect($converged)->toHaveCount(2);

    $convergedIds = $converged->pluck('id')->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();
    expect($convergedIds)->toBe($originalLegIds);

    $groceriesAfter = $converged->first(fn (object $row): bool => (int) $row->id === $groceriesLegId);
    $householdAfter = $converged->first(fn (object $row): bool => (int) $row->id === $householdLegId);

    expect((int) $groceriesAfter->settled_amount_minor)->toBe(-5000);
    expect((int) $householdAfter->settled_amount_minor)->toBe(-3000);

    // B's note survives on the same leg A rewrote, which is what makes the merge
    // per-field rather than per-row.
    expect($householdAfter->note)->toBe('flagged by partner');
    expect($groceriesAfter->note)->toBeNull();

    $parentMinor = (int) $db->connection()->table('transactions')->where('id', $tx->id)->value('settled_amount_minor');
    expect((int) $converged->sum('settled_amount_minor'))->toBe($parentMinor);
    expect($parentMinor)->toBe(-8000);

    // Anything in quarantine would mean an unknown device key, a signature that
    // did not verify, or a strategy that did not resolve.
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});
