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

const HELD_LOCAL_DEVICE = 'the-mac-that-classified-first';

const HELD_PEER_DEVICE = 'the-phone-that-classified-first';

function heldUser(): User
{
    return User::query()->create([
        'username' => 'held-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/** @return array<string, mixed> */
function heldCounterparty(string $slug, string $writtenAt): array
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

function heldWriter(int $userId, string $deviceId): OpLogWriter
{
    $keypair = $deviceId === HELD_PEER_DEVICE ? test()->peerKeypair : test()->localKeypair;

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    return $writer;
}

/** @return array<string, int> */
function heldCensus(int $userId): array
{
    /** @var StrandedCreates $stranded */
    $stranded = app(StrandedCreates::class);

    return $stranded->census($userId)['stranded'];
}

function heldReplay(DatabaseManager $db, int $userId): void
{
    $keys = [
        HELD_PEER_DEVICE => bin2hex(sodium_crypto_sign_publickey(test()->peerKeypair)),
        HELD_LOCAL_DEVICE => bin2hex(sodium_crypto_sign_publickey(test()->localKeypair)),
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

    $this->user = heldUser();
    $this->peerKeypair = sodium_crypto_sign_keypair();
    $this->localKeypair = sodium_crypto_sign_keypair();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->localId = (int) $db->connection()->table('counterparties')->insertGetId([
        'user_id' => (int) $this->user->id,
        ...heldCounterparty('albert-heijn', '2026-08-01 18:12:03'),
    ]);

    heldWriter((int) $this->user->id, HELD_LOCAL_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, heldCounterparty('albert-heijn', '2026-08-01 18:12:03'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

// The shape a check that asks about primary keys cannot see. The id is here,
// and a check that stops at "is something at this id" reports nothing.
it('counts a create whose id is here holding a different row', function (): void {
    $userId = (int) $this->user->id;

    heldWriter($userId, HELD_PEER_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, heldCounterparty('kpn-mobiel', '2026-02-02 08:30:00'));

    expect($this->db->connection()->table('counterparties')->where('id', $this->localId)->exists())->toBeTrue(
        'The id is free, so this is not the shape a primary-key check misses.',
    );

    expect(heldCensus($userId))->toBe(['counterparties' => 1]);
});

it('counts a create whose id no row is at', function (): void {
    $userId = (int) $this->user->id;

    heldWriter($userId, HELD_PEER_DEVICE)
        ->writeCreateRow('counterparties', 9001, heldCounterparty('netflix', '2026-02-02 08:30:00'));

    expect(heldCensus($userId))->toBe(['counterparties' => 1]);
});

// The first legitimate absence: the row is here under an id this device minted,
// and the alias says so. Replaying is what records it.
it('does not count a create the applier re-homed', function (): void {
    $userId = (int) $this->user->id;

    heldWriter($userId, HELD_PEER_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, heldCounterparty('kpn-mobiel', '2026-02-02 08:30:00'));

    heldReplay($this->db, $userId);

    expect($this->db->connection()->table('counterparties')->where('user_id', $userId)->count())->toBe(2)
        ->and(heldCensus($userId))->toBe([]);
});

// The second: a row deliberately gone. The tombstone is the record of that, and
// it outranks the create whether or not anything is at the id any more.
it('does not count a create a tombstone answers for', function (): void {
    $userId = (int) $this->user->id;

    $writer = heldWriter($userId, HELD_PEER_DEVICE);
    $writer->writeCreateRow('counterparties', 9002, heldCounterparty('hema', '2026-02-02 08:30:00'));
    $writer->writeDelete('counterparties', 9002);

    expect(heldCensus($userId))->toBe([]);
});

// The third: two devices that computed one id for one row. Nothing special is
// done for a derived id — the natural key finds the row, which is the same
// answer the applier gives, so the merge is not read as a loss.
it('does not count two devices that minted one id for one row', function (): void {
    $userId = (int) $this->user->id;

    heldWriter($userId, HELD_PEER_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, heldCounterparty('albert-heijn', '2026-08-01 19:45:00'));

    expect(heldCensus($userId))->toBe([]);
});

// A natural key is not frozen at creation. Reading only the create's own value
// would call every renamed row a row that never landed.
it('does not count a row whose natural key was edited after it arrived', function (): void {
    $userId = (int) $this->user->id;

    heldWriter($userId, HELD_PEER_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, heldCounterparty('albert-heijn', '2026-08-01 19:45:00'));

    $this->db->connection()->table('counterparties')->where('id', $this->localId)->update(['slug' => 'ah-to-go']);
    heldWriter($userId, HELD_PEER_DEVICE)->writeSet('counterparties', $this->localId, 'slug', 'ah-to-go');

    expect(heldCensus($userId))->toBe([]);
});

it('counts nothing on a log every create of which has its row', function (): void {
    expect(heldCensus((int) $this->user->id))->toBe([]);
});

function heldHealthCheck(): StrandedCreateHealthCheck
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

    heldWriter($userId, HELD_PEER_DEVICE)
        ->writeCreateRow('counterparties', $this->localId, heldCounterparty('kpn-mobiel', '2026-02-02 08:30:00'));

    $message = heldHealthCheck()->message();

    expect(heldHealthCheck()->severity())->toBe('warning')
        ->and($message)->toContain('1 row the op log holds and no table has', 'counterparties 1')
        ->and($message)->not->toContain('kpn-mobiel')
        ->and($message)->not->toContain('Kpn-mobiel');
});

// A pass that reports silence is unreadable: one that stopped looking says the
// same as one that looked at everything, so the row carries what it examined.
it('says how many rows it checked when none of them is missing', function (): void {
    expect(heldHealthCheck()->severity())->toBe('ok')
        ->and(heldHealthCheck()->message())->toBe('1 row the op log claims, all of them here');
});
