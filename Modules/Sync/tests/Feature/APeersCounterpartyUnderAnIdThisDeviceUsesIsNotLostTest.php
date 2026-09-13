<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// Measured on a paired Mac and Galaxy A51. Both classified their own import
// before pairing, so both minted counterparties 2..20; nineteen ids carry a
// create from each device under a DIFFERENT slug, and fifteen of the phone's
// slugs are in no row here. 135 create_row ops, no alias, no quarantine row.

// The shape the pk-absent detector cannot see: the id IS here, holding a row
// the peer never sent.
const STRANDED_CP_PEER_DEVICE = 'the-phone-that-classified-first';

function strandedCpUser(): User
{
    return User::query()->create([
        'username' => 'strandedcp-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/** @return array<string, mixed> */
function strandedCpRow(string $slug, string $displayName, string $writtenAt): array
{
    return [
        'type' => 'merchant',
        'slug' => $slug,
        'display_name' => $displayName,
        'merchant_name' => $displayName,
        'iban' => null,
        'metadata' => null,
        'created_at' => $writtenAt,
        'updated_at' => $writtenAt,
    ];
}

function strandedCpWriter(int $userId): OpLogWriter
{
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => STRANDED_CP_PEER_DEVICE,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey(test()->peerKeypair),
        'publicKey' => sodium_crypto_sign_publickey(test()->peerKeypair),
    ]);

    return $writer;
}

/** @return list<OpLogEntry> */
function strandedCpOps(DatabaseManager $db, int $userId): array
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

function strandedCpReplay(DatabaseManager $db, int $userId): void
{
    $keys = [STRANDED_CP_PEER_DEVICE => bin2hex(sodium_crypto_sign_publickey(test()->peerKeypair))];

    (new OpLogReplayer($db, $keys, new MergeRulesRegistry))->replay(strandedCpOps($db, $userId), $userId);
}

/** @return list<string> */
function strandedCpQuarantineReasons(DatabaseManager $db, int $userId): array
{
    /** @var list<string> $reasons */
    $reasons = $db->connection()->table('op_log_quarantine')->where('user_id', $userId)->pluck('reason')->all();

    return $reasons;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-10 22:48:52');

    $this->user = strandedCpUser();
    $this->peerKeypair = sodium_crypto_sign_keypair();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->localId = (int) $db->connection()->table('counterparties')->insertGetId([
        'user_id' => (int) $this->user->id,
        ...strandedCpRow('albert-heijn', 'Albert Heijn', '2026-08-01 18:12:03'),
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('stores the counterparty the peer minted that id for', function (): void {
    $userId = (int) $this->user->id;

    strandedCpWriter($userId)->writeCreateRow('counterparties', $this->localId, strandedCpRow('kpn-mobiel', 'KPN Mobiel', '2026-02-02 08:30:00'));

    strandedCpReplay($this->db, $userId);

    expect($this->db->connection()->table('counterparties')->where('user_id', $userId)->count())->toBe(
        2,
        'The peer sent a counterparty this device does not hold and it was not stored.',
    );

    expect($this->db->connection()->table('counterparties')->where('slug', 'albert-heijn')->count())->toBe(1)
        ->and($this->db->connection()->table('counterparties')->where('slug', 'kpn-mobiel')->count())->toBe(1);
});

it('maps the id the peer uses to the id this device gave the counterparty', function (): void {
    $userId = (int) $this->user->id;

    strandedCpWriter($userId)->writeCreateRow('counterparties', $this->localId, strandedCpRow('kpn-mobiel', 'KPN Mobiel', '2026-02-02 08:30:00'));

    strandedCpReplay($this->db, $userId);

    $rehomedId = $this->db->connection()->table('counterparties')->where('slug', 'kpn-mobiel')->value('id');

    $alias = $this->db->connection()->table('op_log_row_aliases')
        ->where('user_id', $userId)
        ->where('table_name', 'counterparties')
        ->where('remote_id', (string) $this->localId)
        ->value('local_id');

    expect($alias)->toBe((string) $rehomedId)
        ->and(strandedCpQuarantineReasons($this->db, $userId))->toBe([]);
});

it('leaves one counterparty when the same create is replayed three times', function (): void {
    $userId = (int) $this->user->id;

    strandedCpWriter($userId)->writeCreateRow('counterparties', $this->localId, strandedCpRow('kpn-mobiel', 'KPN Mobiel', '2026-02-02 08:30:00'));

    strandedCpReplay($this->db, $userId);
    strandedCpReplay($this->db, $userId);
    strandedCpReplay($this->db, $userId);

    expect($this->db->connection()->table('counterparties')->where('slug', 'kpn-mobiel')->count())->toBe(1)
        ->and($this->db->connection()->table('counterparties')->where('user_id', $userId)->count())->toBe(2);
});

// The reason the alias matters here rather than only for transactions: a
// counterparty id crosses on every transaction the peer sends, and that column
// carries no foreign key, so a wrong translation lands silently.
it('lands a later edit from that peer on the counterparty it re-homed', function (): void {
    $userId = (int) $this->user->id;

    strandedCpWriter($userId)->writeCreateRow('counterparties', $this->localId, strandedCpRow('kpn-mobiel', 'KPN Mobiel', '2026-02-02 08:30:00'));
    strandedCpReplay($this->db, $userId);

    strandedCpWriter($userId)->writeSet('counterparties', $this->localId, 'type', 'personal');
    strandedCpReplay($this->db, $userId);

    expect($this->db->connection()->table('counterparties')->where('slug', 'kpn-mobiel')->value('type'))->toBe('personal')
        ->and($this->db->connection()->table('counterparties')->where('slug', 'albert-heijn')->value('type'))->toBe(
            'merchant',
            'The edit landed on the local counterparty the peer id collided with, which is a row the peer never saw.',
        );
});
