<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// Every gate on the create path used to read the ids the PEER minted, because
// translate() ran eleven lines after the gates that judged them. One arrival
// path -- the second half of a create the transport split -- wrote before any
// gate ran at all, and was never translated.

const GATEPATH_PEER = 'the-phone-that-minted-its-own-ids';

function gatepathUser(string $tag): User
{
    return User::query()->create([
        'username' => $tag.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function gatepathCounterparty(DatabaseManager $db, int $userId, string $slug): int
{
    return (int) $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => ucwords(str_replace('-', ' ', $slug)),
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

// The pair remember() would have stored: the peer's id for a row, and the id
// this device holds the same logical row under.
function gatepathAlias(DatabaseManager $db, int $userId, string $table, int|string $remote, int|string $local): void
{
    $db->connection()->table('op_log_row_aliases')->insert([
        'user_id' => $userId,
        'table_name' => $table,
        'device_id' => GATEPATH_PEER,
        'remote_id' => (string) $remote,
        'local_id' => (string) $local,
        'created_at' => '2026-02-01 00:00:00',
    ]);
}

function gatepathLeg(DatabaseManager $db, int $userId, int $transactionId, int $categoryId, int $amountMinor): int
{
    return (int) $db->connection()->table('transaction_splits')->insertGetId([
        'user_id' => $userId,
        'transaction_id' => $transactionId,
        'category_id' => $categoryId,
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'sort_order' => 0,
        'created_at' => '2026-03-01 00:00:00',
        'updated_at' => '2026-03-01 00:00:00',
    ]);
}

/** @return array{account: int, run: int, category: int} */
function gatepathParents(DatabaseManager $db, int $userId): array
{
    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN betaalrekening',
        'slug' => 'gatepath-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00GATE'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/gatepath.csv',
        'sha256' => hash('sha256', 'gatepath-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-02 00:00:00',
        'status' => 'confirmed',
        'created_at' => '2026-01-02 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ]);

    $categoryId = (int) $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Boodschappen',
        'slug' => 'gatepath-boodschappen',
        'kind' => 'expense',
        'created_at' => '2026-01-03 00:00:00',
        'updated_at' => '2026-01-03 00:00:00',
    ]);

    return ['account' => $accountId, 'run' => $runId, 'category' => $categoryId];
}

/** @return array<string, mixed> */
function gatepathRow(int $userId, int $accountId, int $runId, string $day, int $amountMinor): array
{
    /** @var FingerprintComposer $composer */
    $composer = app(FingerprintComposer::class);

    return [
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => $day,
        'booked_at' => $day.' 00:00:00',
        'value_date' => $day,
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => FingerprintComposer::NORMALIZATION_VERSION,
        'source_format' => 'asn-csv',
        'source_row_index' => 0,
        'occurrence_ordinal' => 0,
        'fingerprint' => $composer->composeTuple(new FingerprintTuple(
            $userId, $accountId, $day, $day.' 00:00:00', $amountMinor, 'EUR', 'albert heijn', 0,
        )),
        'fingerprint_version' => FingerprintComposer::NORMALIZATION_VERSION,
        'status' => 'cleared',
        'created_at' => $day.' 09:00:00',
        'updated_at' => $day.' 09:00:00',
    ];
}

function gatepathWriter(int $userId): OpLogWriter
{
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => GATEPATH_PEER,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey(test()->peerKeypair),
        'publicKey' => sodium_crypto_sign_publickey(test()->peerKeypair),
    ]);

    return $writer;
}

/**
 * @param  array<string, mixed>  $row
 */
function gatepathCreate(int $userId, string $table, int|string $peerPk, array $row): void
{
    gatepathWriter($userId)->writeCreateRow($table, $peerPk, $row);
}

/** @return list<OpLogEntry> */
function gatepathOps(DatabaseManager $db, int $userId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->orderBy('id')
        ->get()
        ->map(static fn (object $row): OpLogEntry => new OpLogEntry(
            table: (string) $row->table_name,
            pk: is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk,
            field: (string) $row->field,
            value: $row->value !== null ? (string) $row->value : null,
            hlcL: (int) $row->hlc_l,
            hlcC: (int) $row->hlc_c,
            deviceId: (string) $row->device_id,
            opType: OpType::from((string) $row->op_type),
            signature: (string) $row->signature,
            userId: (int) $row->user_id,
        ))
        ->all();
}

function gatepathReplay(DatabaseManager $db, int $userId): void
{
    $keys = [GATEPATH_PEER => bin2hex(sodium_crypto_sign_publickey(test()->peerKeypair))];

    (new OpLogReplayer($db, $keys, new MergeRulesRegistry))->replay(gatepathOps($db, $userId), $userId);
}

/** @return list<string> */
function gatepathQuarantineReasons(DatabaseManager $db, int $userId): array
{
    /** @var list<string> $reasons */
    $reasons = $db->connection()->table('op_log_quarantine')->where('user_id', $userId)->pluck('reason')->all();

    return $reasons;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-11 10:15:00');

    $this->peerKeypair = sodium_crypto_sign_keypair();

    // The other reader in the household. They exist to hold an id, because a
    // reference is only refused when the row it names belongs to somebody.
    $this->other = gatepathUser('gatepath-other');
    $this->user = gatepathUser('gatepath');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $parents = gatepathParents($db, (int) $this->user->id);
    $this->accountId = $parents['account'];
    $this->runId = $parents['run'];
    $this->categoryId = $parents['category'];

    $this->localId = (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => (int) $this->user->id,
        ...gatepathRow((int) $this->user->id, $this->accountId, $this->runId, '2026-08-01', -125000),
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('translates the ids a create names before asking whose rows they are', function (): void {
    $userId = (int) $this->user->id;

    // The peer minted this id for ITS albert heijn. Here it is the other
    // reader's butcher, and this reader's albert heijn is under another id.
    $theirs = gatepathCounterparty($this->db, (int) $this->other->id, 'their-butcher');
    $mine = gatepathCounterparty($this->db, $userId, 'albert-heijn');

    gatepathAlias($this->db, $userId, 'counterparties', $theirs, $mine);

    gatepathCreate($userId, 'transactions', 9001, [
        ...gatepathRow($userId, $this->accountId, $this->runId, '2026-03-03', -1999),
        'counterparty_id' => $theirs,
    ]);

    gatepathReplay($this->db, $userId);

    expect(gatepathQuarantineReasons($this->db, $userId))->not->toContain('cross_user')
        ->and((int) $this->db->connection()->table('transactions')->where('id', 9001)->value('counterparty_id'))
        ->toBe($mine);
});

it('takes the split sum on the transaction the leg will land on', function (): void {
    $userId = (int) $this->user->id;

    gatepathLeg($this->db, $userId, $this->localId, $this->categoryId, -125000);
    gatepathAlias($this->db, $userId, 'transactions', 7777, $this->localId);

    gatepathCreate($userId, 'transaction_splits', 8888, [
        'transaction_id' => 7777,
        'category_id' => $this->categoryId,
        'settled_amount_minor' => -4500,
        'settled_currency' => 'EUR',
        'sort_order' => 1,
        'created_at' => '2026-04-01 00:00:00',
        'updated_at' => '2026-04-01 00:00:00',
    ]);

    gatepathReplay($this->db, $userId);

    expect(gatepathQuarantineReasons($this->db, $userId))->toContain('split_would_overfill_transaction')
        ->and((int) $this->db->connection()->table('transaction_splits')
            ->where('transaction_id', $this->localId)->sum('settled_amount_minor'))
        ->toBe(-125000);
});

it('translates the second half of a split create before it writes it', function (): void {
    $userId = (int) $this->user->id;

    $theirs = gatepathCounterparty($this->db, (int) $this->other->id, 'their-butcher');
    $mine = gatepathCounterparty($this->db, $userId, 'albert-heijn');

    gatepathAlias($this->db, $userId, 'counterparties', $theirs, $mine);

    // Incomplete: fingerprint_version is required to create, so this is the
    // tail of a create whose first half already landed the row.
    $tail = gatepathRow($userId, $this->accountId, $this->runId, '2026-08-01', -125000);
    unset($tail['fingerprint_version']);

    gatepathCreate($userId, 'transactions', $this->localId, [...$tail, 'counterparty_id' => $theirs]);

    gatepathReplay($this->db, $userId);

    expect((int) $this->db->connection()->table('transactions')->where('id', $this->localId)->value('counterparty_id'))
        ->toBe($mine);
});

it('fills the second half of a create on the id the row is here under', function (): void {
    $userId = (int) $this->user->id;

    // Re-homed on an earlier frame: the peer calls this row 6006, and nothing
    // at all is here under that id.
    gatepathAlias($this->db, $userId, 'transactions', 6006, $this->localId);

    $mine = gatepathCounterparty($this->db, $userId, 'albert-heijn');

    $tail = gatepathRow($userId, $this->accountId, $this->runId, '2026-08-01', -125000);
    unset($tail['fingerprint_version']);

    gatepathCreate($userId, 'transactions', 6006, [...$tail, 'counterparty_id' => $mine]);

    gatepathReplay($this->db, $userId);

    expect((int) $this->db->connection()->table('transactions')->where('id', $this->localId)->value('counterparty_id'))
        ->toBe($mine)
        ->and(gatepathQuarantineReasons($this->db, $userId))->not->toContain('incomplete_create_row');
});

it('judges an arriving leg against the amounts arriving beside it', function (): void {
    $userId = (int) $this->user->id;

    $stored = gatepathLeg($this->db, $userId, $this->localId, $this->categoryId, -125000);

    // A rebalance announces its whole set: the stored leg drops by exactly
    // what the new one takes, and both ops are in this frame.
    gatepathWriter($userId)->writeSet('transaction_splits', $stored, 'settled_amount_minor', -100000);

    gatepathCreate($userId, 'transaction_splits', 9100, [
        'transaction_id' => $this->localId,
        'category_id' => $this->categoryId,
        'settled_amount_minor' => -25000,
        'settled_currency' => 'EUR',
        'sort_order' => 1,
        'created_at' => '2026-04-02 00:00:00',
        'updated_at' => '2026-04-02 00:00:00',
    ]);

    gatepathReplay($this->db, $userId);

    expect(gatepathQuarantineReasons($this->db, $userId))->not->toContain('split_would_overfill_transaction')
        ->and($this->db->connection()->table('transaction_splits')->where('transaction_id', $this->localId)->count())
        ->toBe(2);
});

it('does not refuse a create whose own amount the same frame overwrites', function (): void {
    $userId = (int) $this->user->id;

    $groceries = gatepathLeg($this->db, $userId, $this->localId, $this->categoryId, -100000);
    $household = gatepathLeg($this->db, $userId, $this->localId, $this->categoryId, -25000);

    // The historical create replayed beside the rebalance that supersedes it.
    // Its -100000 is what the leg held, not what it will hold.
    gatepathCreate($userId, 'transaction_splits', $groceries, [
        'transaction_id' => $this->localId,
        'category_id' => $this->categoryId,
        'settled_amount_minor' => -100000,
        'settled_currency' => 'EUR',
        'sort_order' => 0,
        'created_at' => '2026-03-01 00:00:00',
        'updated_at' => '2026-03-01 00:00:00',
    ]);

    gatepathWriter($userId)->writeSet('transaction_splits', $groceries, 'settled_amount_minor', -95000);
    gatepathWriter($userId)->writeSet('transaction_splits', $household, 'settled_amount_minor', -30000);

    gatepathReplay($this->db, $userId);

    expect(gatepathQuarantineReasons($this->db, $userId))->not->toContain('split_would_overfill_transaction')
        ->and((int) $this->db->connection()->table('transaction_splits')
            ->where('transaction_id', $this->localId)->sum('settled_amount_minor'))
        ->toBe(-125000);
});
