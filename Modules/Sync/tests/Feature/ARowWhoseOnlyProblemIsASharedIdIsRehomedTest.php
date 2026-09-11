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

// Measured on a paired Mac and Galaxy A51. Each imported a statement before
// pairing, so both took id 4: on the Mac 2026-08-01 for -1250,00 and on the
// phone 2026-02-02 for -3,99. Compared by fingerprint, 112 of 219 distinct
// transactions lived on one device only, both screens said "All devices are up
// to date", and 47 creates per side sat in quarantine as primary_key_collision.

// The peer's own autoincrement. It names a row here, and a different one.
const REHOME_PEER_DEVICE = 'the-phone-that-imported-first';

function rehomeUser(): User
{
    return User::query()->create([
        'username' => 'rehome-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/** @return array{account: int, run: int} */
function rehomeParents(DatabaseManager $db, int $userId): array
{
    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN betaalrekening',
        'slug' => 'rehome-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00REHM'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/rehome.csv',
        'sha256' => hash('sha256', 'rehome-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-02 00:00:00',
        'status' => 'confirmed',
        'created_at' => '2026-01-02 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ]);

    return ['account' => $accountId, 'run' => $runId];
}

// One transaction, every column of it, because that is what a capture puts on
// the wire. The date and the amount carry the identity: they are inside both
// unique indexes `transactions` declares.
/** @return array<string, mixed> */
function rehomeRow(int $userId, int $accountId, int $runId, string $day, int $amountMinor, string $writtenAt): array
{
    /** @var FingerprintComposer $composer */
    $composer = app(FingerprintComposer::class);

    return [
        'user_id' => $userId,
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
        'created_at' => $writtenAt,
        'updated_at' => $writtenAt,
    ];
}

function rehomeWriter(int $userId): OpLogWriter
{
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => REHOME_PEER_DEVICE,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey(test()->peerKeypair),
        'publicKey' => sodium_crypto_sign_publickey(test()->peerKeypair),
    ]);

    return $writer;
}

/**
 * @param  array<string, mixed>  $row
 */
function rehomePeerCreate(int $userId, int|string $peerPk, array $row): void
{
    unset($row['user_id']);

    rehomeWriter($userId)->writeCreateRow('transactions', $peerPk, $row);
}

/** @return list<OpLogEntry> */
function rehomeOps(DatabaseManager $db, int $userId): array
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

function rehomeReplay(DatabaseManager $db, int $userId): void
{
    $keys = [REHOME_PEER_DEVICE => bin2hex(sodium_crypto_sign_publickey(test()->peerKeypair))];

    (new OpLogReplayer($db, $keys, new MergeRulesRegistry))->replay(rehomeOps($db, $userId), $userId);
}

function rehomeAliasFor(DatabaseManager $db, int $userId, string $table, int|string $peerPk): ?string
{
    $local = $db->connection()->table('op_log_row_aliases')
        ->where('user_id', $userId)
        ->where('table_name', $table)
        ->where('remote_id', (string) $peerPk)
        ->value('local_id');

    return is_string($local) ? $local : null;
}

/** @return list<string> */
function rehomeQuarantineReasons(DatabaseManager $db, int $userId): array
{
    /** @var list<string> $reasons */
    $reasons = $db->connection()->table('op_log_quarantine')->where('user_id', $userId)->pluck('reason')->all();

    return $reasons;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-10 22:44:19');

    $this->user = rehomeUser();
    $this->peerKeypair = sodium_crypto_sign_keypair();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $parents = rehomeParents($db, (int) $this->user->id);
    $this->accountId = $parents['account'];
    $this->runId = $parents['run'];

    // What this device imported before pairing. It takes the id it takes, and
    // the peer's statement took the same one for a different purchase.
    $this->localId = (int) $db->connection()->table('transactions')->insertGetId(
        rehomeRow((int) $this->user->id, $this->accountId, $this->runId, '2026-08-01', -125000, '2026-08-01 18:12:03'),
    );
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('stores a create colliding on its id under an id of this device', function (): void {
    $userId = (int) $this->user->id;

    rehomePeerCreate($userId, $this->localId, rehomeRow($userId, $this->accountId, $this->runId, '2026-02-02', -399, '2026-02-02 08:30:00'));

    rehomeReplay($this->db, $userId);

    expect($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(
        2,
        'The peer sent a transaction this device does not hold and it was not stored.',
    );

    $squatter = $this->db->connection()->table('transactions')->where('id', $this->localId)->first();
    $rehomed = $this->db->connection()->table('transactions')->where('posted_at', '2026-02-02')->first();

    expect($squatter?->amount_minor)->toBe(-125000)
        ->and($squatter?->posted_at)->toBe('2026-08-01')
        ->and($rehomed?->amount_minor)->toBe(-399)
        ->and((int) ($rehomed?->id ?? 0))->not->toBe($this->localId)
        ->and(rehomeQuarantineReasons($this->db, $userId))->toBe([]);
});

it('maps the id the peer uses to the id this device gave the row', function (): void {
    $userId = (int) $this->user->id;

    rehomePeerCreate($userId, $this->localId, rehomeRow($userId, $this->accountId, $this->runId, '2026-02-02', -399, '2026-02-02 08:30:00'));

    rehomeReplay($this->db, $userId);

    $rehomedId = $this->db->connection()->table('transactions')->where('posted_at', '2026-02-02')->value('id');

    expect(rehomeAliasFor($this->db, $userId, 'transactions', $this->localId))->toBe((string) $rehomedId);
});

// The reason the alias is worth recording at all: every op the peer sends for
// that row afterwards still names the id it minted, and this device holds a
// different row at it. Without the alias the edit lands on that other row.
it('lands a later edit from that peer on the re-homed row', function (): void {
    $userId = (int) $this->user->id;

    rehomePeerCreate($userId, $this->localId, rehomeRow($userId, $this->accountId, $this->runId, '2026-02-02', -399, '2026-02-02 08:30:00'));
    rehomeReplay($this->db, $userId);

    rehomeWriter($userId)->writeSet('transactions', $this->localId, 'status', 'pending');
    rehomeReplay($this->db, $userId);

    $rehomedId = (int) $this->db->connection()->table('transactions')->where('posted_at', '2026-02-02')->value('id');

    expect($this->db->connection()->table('transactions')->where('id', $rehomedId)->value('status'))->toBe('pending')
        ->and($this->db->connection()->table('transactions')->where('id', $this->localId)->value('status'))->toBe(
            'cleared',
            'The edit landed on the local row the peer id collided with, which is the row the peer never saw.',
        );
});

// The assertion a wrong design fails: a re-homed row is recognised by its
// natural key on the next replay, so the create is idempotent. It is only safe
// to re-home a row a later replay can find again.
it('leaves exactly one row when the same create is applied twice', function (): void {
    $userId = (int) $this->user->id;

    rehomePeerCreate($userId, $this->localId, rehomeRow($userId, $this->accountId, $this->runId, '2026-02-02', -399, '2026-02-02 08:30:00'));

    rehomeReplay($this->db, $userId);
    rehomeReplay($this->db, $userId);
    rehomeReplay($this->db, $userId);

    expect($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(
        2,
        'A replay of the same create inserted a second copy of the re-homed row, so every sync adds another.',
    );

    expect($this->db->connection()->table('transactions')->where('posted_at', '2026-02-02')->count())->toBe(1);
});

it('inserts under the id the peer used when nothing holds it', function (): void {
    $userId = (int) $this->user->id;

    rehomePeerCreate($userId, 9001, rehomeRow($userId, $this->accountId, $this->runId, '2026-03-03', -1250, '2026-03-03 09:00:00'));

    rehomeReplay($this->db, $userId);

    expect($this->db->connection()->table('transactions')->where('id', 9001)->value('amount_minor'))->toBe(-1250)
        ->and(rehomeAliasFor($this->db, $userId, 'transactions', 9001))->toBeNull()
        ->and(rehomeQuarantineReasons($this->db, $userId))->toBe([]);
});

// The arm re-homing must not take over: the row IS here, under an id only the
// peer ever used, so it is aliased and nothing is written.
it('aliases a row already here under another id rather than storing it again', function (): void {
    $userId = (int) $this->user->id;

    rehomePeerCreate($userId, 9002, rehomeRow($userId, $this->accountId, $this->runId, '2026-08-01', -125000, '2026-08-01 19:45:00'));

    rehomeReplay($this->db, $userId);

    expect($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(1)
        ->and(rehomeAliasFor($this->db, $userId, 'transactions', 9002))->toBe((string) $this->localId)
        ->and(rehomeQuarantineReasons($this->db, $userId))->toBe([]);
});

it('stays silent when the create names the row already sitting at that id', function (): void {
    $userId = (int) $this->user->id;

    rehomePeerCreate($userId, $this->localId, rehomeRow($userId, $this->accountId, $this->runId, '2026-08-01', -125000, '2026-08-01 18:12:03'));

    rehomeReplay($this->db, $userId);

    expect($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(1)
        ->and(rehomeAliasFor($this->db, $userId, 'transactions', $this->localId))->toBeNull()
        ->and(rehomeQuarantineReasons($this->db, $userId))->toBe([]);
});

// The table that has to keep quarantining. `goals` mints its ids rather than
// declaring a natural key, so nothing can recognise a re-homed goal afterwards
// and each replay would store another one. The disclosure stays the answer,
// under the reason that says no later pass can change it.
it('still quarantines a collision on a table with no natural key', function (): void {
    $userId = (int) $this->user->id;

    $this->db->connection()->table('goals')->insert([
        'id' => 4242,
        'user_id' => $userId,
        'name' => 'Nieuwe fiets',
        'target_minor' => 125000,
        'target_currency' => 'EUR',
        'start_date' => '2026-09-02',
        'target_date' => '2027-06-30',
        'status' => 'active',
        'created_at' => '2026-09-02 21:07:48',
        'updated_at' => '2026-09-02 21:07:48',
    ]);

    rehomeWriter($userId)->writeCreateRow('goals', 4242, [
        'name' => 'Zonnepanelen',
        'target_minor' => 900000,
        'target_currency' => 'EUR',
        'start_date' => '2026-09-02',
        'target_date' => '2028-01-31',
        'status' => 'active',
        'created_at' => '2026-09-02 22:55:05',
        'updated_at' => '2026-09-02 22:55:05',
    ]);

    rehomeReplay($this->db, $userId);

    expect($this->db->connection()->table('goals')->where('user_id', $userId)->count())->toBe(1)
        ->and($this->db->connection()->table('goals')->where('id', 4242)->value('name'))->toBe('Nieuwe fiets')
        ->and(rehomeQuarantineReasons($this->db, $userId))->toContain('unplaceable_collision');
});
