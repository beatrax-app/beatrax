<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\StrandedCreates;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Public\Services\StrandedCreateHealthCheck;

uses(RefreshDatabase::class);

// Measured on a paired Mac and Galaxy A51: fifteen counterparties the phone
// created are in no row here, their 135 create ops are still on disk, and
// op_log_row_aliases and op_log_quarantine hold nothing for the table. Every
// one of the fifteen ids IS in use — by a different counterparty.

const LOG_HOLDS_LOCAL_DEVICE = 'the-mac-that-classified-first';

const LOG_HOLDS_PEER_DEVICE = 'the-phone-that-classified-first';

function logHoldsUser(): User
{
    return User::query()->create([
        'username' => 'logholds-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/** @return array<string, mixed> */
function logHoldsCounterparty(string $slug, string $writtenAt): array
{
    return [
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => ucfirst($slug),
        'merchant_name' => ucfirst($slug),
        'iban' => null,
        'metadata' => null,
        'created_at' => $writtenAt,
        'updated_at' => $writtenAt,
    ];
}

function logHoldsWriter(int $userId, string $deviceId): OpLogWriter
{
    $keypair = $deviceId === LOG_HOLDS_PEER_DEVICE ? test()->peerKeypair : test()->localKeypair;

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    return $writer;
}

/** @return array{checked: int, unplaced: array<string, int>, removedHere: array<string, int>, held: array<string, int>} */
function logHoldsCauses(int $userId): array
{
    /** @var StrandedCreates $stranded */
    $stranded = app(StrandedCreates::class);

    return $stranded->census($userId);
}

/** @return array<string, int> */
function logHoldsCensus(int $userId): array
{
    return logHoldsCauses($userId)['unplaced'];
}

function logHoldsRegister(DatabaseManager $db, int $userId, string $deviceId, bool $self): void
{
    $keypair = $self ? test()->localKeypair : test()->peerKeypair;

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $self ? 'Mac' : 'Galaxy A51',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey($keypair)),
        'x25519_public_key_hex' => bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => $self ? 1 : 0,
        'paired_at' => '2026-08-01T10:00:00Z',
        'confirmed_at' => '2026-08-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-01T10:00:00Z',
    ]);
}

function logHoldsHold(DatabaseManager $db, int $userId, int|string $pk, string $reason): void
{
    $db->connection()->table('op_log_quarantine')->insert([
        'user_id' => $userId,
        'op_entry_id' => null,
        'table_name' => 'counterparties',
        'pk' => (string) $pk,
        'device_id' => LOG_HOLDS_PEER_DEVICE,
        'reason' => $reason,
        'hlc_l' => 1789071986333,
        'hlc_c' => 0,
        'raw_value' => null,
        'created_at' => '2026-09-11 16:40:34',
        'gdk_epoch' => null,
        'op_type' => OpType::CreateRow->value,
    ]);
}

function logHoldsReplay(DatabaseManager $db, int $userId): void
{
    $keys = [
        LOG_HOLDS_PEER_DEVICE => bin2hex(sodium_crypto_sign_publickey(test()->peerKeypair)),
        LOG_HOLDS_LOCAL_DEVICE => bin2hex(sodium_crypto_sign_publickey(test()->localKeypair)),
    ];

    $entries = $db->connection()->table('op_log_entries')
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

    (new OpLogReplayer($db, $keys, new MergeRulesRegistry))->replay($entries, $userId);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-10 22:48:52');

    $this->user = logHoldsUser();
    $this->peerKeypair = sodium_crypto_sign_keypair();
    $this->localKeypair = sodium_crypto_sign_keypair();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    logHoldsRegister($db, (int) $this->user->id, LOG_HOLDS_LOCAL_DEVICE, self: true);
    logHoldsRegister($db, (int) $this->user->id, LOG_HOLDS_PEER_DEVICE, self: false);

    $this->localId = (int) $db->connection()->table('counterparties')->insertGetId([
        'user_id' => (int) $this->user->id,
        ...logHoldsCounterparty('albert-heijn', '2026-08-01 18:12:03'),
    ]);

    logHoldsWriter((int) $this->user->id, LOG_HOLDS_LOCAL_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, logHoldsCounterparty('albert-heijn', '2026-08-01 18:12:03'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

// The shape a check that asks about primary keys cannot see. The id is here,
// and a check that stops at "is something at this id" reports nothing.
it('counts a create whose id is here holding a different row', function (): void {
    $userId = (int) $this->user->id;

    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, logHoldsCounterparty('kpn-mobiel', '2026-02-02 08:30:00'));

    expect($this->db->connection()->table('counterparties')->where('id', $this->localId)->exists())->toBeTrue(
        'The id is free, so this is not the shape a primary-key check misses.',
    );

    expect(logHoldsCensus($userId))->toBe(['counterparties' => 1]);
});

it('counts a create whose id no row is at', function (): void {
    $userId = (int) $this->user->id;

    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)
        ->writeCreateRow('counterparties', 9001, logHoldsCounterparty('netflix', '2026-02-02 08:30:00'));

    expect(logHoldsCensus($userId))->toBe(['counterparties' => 1]);
});

// The first legitimate absence: the row is here under an id this device minted,
// and the alias says so. Replaying is what records it.
it('does not count a create the applier re-homed', function (): void {
    $userId = (int) $this->user->id;

    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, logHoldsCounterparty('kpn-mobiel', '2026-02-02 08:30:00'));

    logHoldsReplay($this->db, $userId);

    expect($this->db->connection()->table('counterparties')->where('user_id', $userId)->count())->toBe(2)
        ->and(logHoldsCensus($userId))->toBe([]);
});

// The second: a row deliberately gone. The tombstone is the record of that, and
// it outranks the create whether or not anything is at the id any more.
it('does not count a create a tombstone answers for', function (): void {
    $userId = (int) $this->user->id;

    $writer = logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE);
    $writer->writeCreateRow('counterparties', 9002, logHoldsCounterparty('hema', '2026-02-02 08:30:00'));
    $writer->writeDelete('counterparties', 9002);

    expect(logHoldsCensus($userId))->toBe([]);
});

// The third: two devices that computed one id for one row. Nothing special is
// done for a derived id — the natural key finds the row, which is the same
// answer the applier gives, so the merge is not read as a loss.
it('does not count two devices that minted one id for one row', function (): void {
    $userId = (int) $this->user->id;

    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, logHoldsCounterparty('albert-heijn', '2026-08-01 19:45:00'));

    expect(logHoldsCensus($userId))->toBe([]);
});

// A natural key is not frozen at creation. Reading only the create's own value
// would call every renamed row a row that never landed.
it('does not count a row whose natural key was edited after it arrived', function (): void {
    $userId = (int) $this->user->id;

    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, logHoldsCounterparty('albert-heijn', '2026-08-01 19:45:00'));

    $this->db->connection()->table('counterparties')->where('id', $this->localId)->update(['slug' => 'ah-to-go']);
    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)->writeSet('counterparties', $this->localId, 'slug', 'ah-to-go');

    expect(logHoldsCensus($userId))->toBe([]);
});

it('counts nothing on a log every create of which has its row', function (): void {
    expect(logHoldsCensus((int) $this->user->id))->toBe([]);
});

function logHoldsHealthCheck(): StrandedCreateHealthCheck
{
    /** @var StrandedCreateHealthCheck $check */
    $check = app(StrandedCreateHealthCheck::class);

    return $check;
}

// The reader's own ledger is what is stranded -- a counterparty is the shop
// they bought from -- and a console line is pasted into bug reports, so the
// row names how many and which table and nothing else.
it('names the count and the table and prints no value from the row', function (): void {
    $userId = (int) $this->user->id;

    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, logHoldsCounterparty('kpn-mobiel', '2026-02-02 08:30:00'));

    $message = logHoldsHealthCheck()->message();

    expect(logHoldsHealthCheck()->severity())->toBe('warning')
        ->and($message)->toContain('1 row a peer sent that no table here has', 'counterparties 1')
        ->and($message)->not->toContain('kpn-mobiel')
        ->and($message)->not->toContain('Kpn-mobiel');
});

// A pass that reports silence is unreadable: one that stopped looking says the
// same as one that looked at everything, so the row carries what it examined.
it('says how many rows it checked when none of them is missing', function (): void {
    expect(logHoldsHealthCheck()->severity())->toBe('ok')
        ->and(logHoldsHealthCheck()->message())->toBe('1 row the op log claims, all of them here');
});

// The op log carries no record that a delete was deliberate: a migration that
// removes a row emits no tombstone on purpose, because one would travel and
// take the peer's good copy with it. Authorship carries it instead -- the
// capture runs on the write, so a create THIS device signed proves the row was
// here, and nothing a peer holds is waiting to be placed under it.
it('does not count a row this device wrote and no longer has as one a peer is owed', function (): void {
    $userId = (int) $this->user->id;

    $gone = (int) $this->db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        ...logHoldsCounterparty('hema', '2026-08-02 09:00:00'),
    ]);

    logHoldsWriter($userId, LOG_HOLDS_LOCAL_DEVICE)
        ->writeCreateRow('counterparties', $gone, logHoldsCounterparty('hema', '2026-08-02 09:00:00'));

    $this->db->connection()->table('counterparties')->where('id', $gone)->delete();

    $census = logHoldsCauses($userId);

    expect($census['unplaced'])->toBe([])
        ->and($census['removedHere'])->toBe(['counterparties' => 1])
        ->and($census['held'])->toBe([]);
});

// A hold under a verdict nothing arriving later undoes is the answer already
// given. Counting it as a row still owed is asking the reader to act on a
// refusal the merge has closed.
it('does not count a peer create the quarantine answered for good', function (): void {
    $userId = (int) $this->user->id;

    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)
        ->writeCreateRow('counterparties', 9101, logHoldsCounterparty('netflix', '2026-02-02 08:30:00'));

    logHoldsHold($this->db, $userId, 9101, 'unplaceable_collision');

    $census = logHoldsCauses($userId);

    expect($census['unplaced'])->toBe([])
        ->and($census['held'])->toBe(['counterparties' => 1])
        ->and($census['removedHere'])->toBe([]);
});

// The inverse, which is what proves the rule is the VERDICT and not the
// presence of a hold: a collision a re-home still answers is a row this device
// is owed, and it stays in the count a reader acts on.
it('still counts a peer create held under a verdict a later state undoes', function (): void {
    $userId = (int) $this->user->id;

    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)
        ->writeCreateRow('counterparties', 9102, logHoldsCounterparty('netflix', '2026-02-02 08:30:00'));

    logHoldsHold($this->db, $userId, 9102, 'primary_key_collision');

    $census = logHoldsCauses($userId);

    expect($census['unplaced'])->toBe(['counterparties' => 1])
        ->and($census['held'])->toBe([])
        ->and($census['removedHere'])->toBe([]);
});

// All three on ONE table. A rule keyed on a table name could not draw this
// line, and the next migration that legitimately deletes would be invisible
// to it -- which is the whole reason the cause is asked of the log instead.
it('separates three causes on one table, which no rule keyed on a table name could', function (): void {
    $userId = (int) $this->user->id;

    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, logHoldsCounterparty('kpn-mobiel', '2026-02-02 08:30:00'));

    $gone = (int) $this->db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        ...logHoldsCounterparty('hema', '2026-08-02 09:00:00'),
    ]);
    logHoldsWriter($userId, LOG_HOLDS_LOCAL_DEVICE)
        ->writeCreateRow('counterparties', $gone, logHoldsCounterparty('hema', '2026-08-02 09:00:00'));
    $this->db->connection()->table('counterparties')->where('id', $gone)->delete();

    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)
        ->writeCreateRow('counterparties', 9103, logHoldsCounterparty('netflix', '2026-02-02 08:30:00'));
    logHoldsHold($this->db, $userId, 9103, 'unplaceable_collision');

    $census = logHoldsCauses($userId);

    expect($census['unplaced'])->toBe(['counterparties' => 1])
        ->and($census['removedHere'])->toBe(['counterparties' => 1])
        ->and($census['held'])->toBe(['counterparties' => 1]);
});

// The defect this split exists for: every deliberate deletion used to raise the
// warning by one, so the row could never read ok again and grew with every
// migration. A reader learns to skip a check that never clears, which costs
// them the rows that do matter.
it('reaches ok while the log still holds rows no repair would return', function (): void {
    $userId = (int) $this->user->id;

    $gone = (int) $this->db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        ...logHoldsCounterparty('hema', '2026-08-02 09:00:00'),
    ]);
    logHoldsWriter($userId, LOG_HOLDS_LOCAL_DEVICE)
        ->writeCreateRow('counterparties', $gone, logHoldsCounterparty('hema', '2026-08-02 09:00:00'));
    $this->db->connection()->table('counterparties')->where('id', $gone)->delete();

    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)
        ->writeCreateRow('counterparties', 9104, logHoldsCounterparty('netflix', '2026-02-02 08:30:00'));
    logHoldsHold($this->db, $userId, 9104, 'unplaceable_collision');

    $message = logHoldsHealthCheck()->message();

    expect(logHoldsHealthCheck()->severity())->toBe('ok')
        ->and($message)->toContain(
            'all of them here or accounted for',
            '1 row this device wrote and no longer has, with no tombstone behind them (counterparties 1)',
            '1 row held under a verdict no later state undoes (counterparties 1)',
        )
        ->and($message)->not->toContain('hema')
        ->and($message)->not->toContain('netflix');
});

// Both populations are named even while the actionable one is non-empty: the
// row is pasted into bug reports, and a count with no cause beside it is what
// sent the last reader to the wrong twenty-three rows.
it('names what is accounted for beside the rows a peer is still owed', function (): void {
    $userId = (int) $this->user->id;

    logHoldsWriter($userId, LOG_HOLDS_PEER_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, logHoldsCounterparty('kpn-mobiel', '2026-02-02 08:30:00'));

    $gone = (int) $this->db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        ...logHoldsCounterparty('hema', '2026-08-02 09:00:00'),
    ]);
    logHoldsWriter($userId, LOG_HOLDS_LOCAL_DEVICE)
        ->writeCreateRow('counterparties', $gone, logHoldsCounterparty('hema', '2026-08-02 09:00:00'));
    $this->db->connection()->table('counterparties')->where('id', $gone)->delete();

    $message = logHoldsHealthCheck()->message();

    expect(logHoldsHealthCheck()->severity())->toBe('warning')
        ->and($message)->toContain(
            '1 row a peer sent that no table here has (counterparties 1)',
            'Accounted for beside them: 1 row this device wrote and no longer has, with no tombstone behind them (counterparties 1)',
        );
});
