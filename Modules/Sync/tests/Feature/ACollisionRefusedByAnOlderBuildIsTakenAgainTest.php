<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Public\Services\HistoryReprojector;

uses(RefreshDatabase::class);

// The 52 ops a paired Mac still held. Every one was refused as
// `primary_key_collision` by a build with no re-home, and nothing has looked at
// one since: the reason was terminal, so no pass named the row. The entries are
// all still in op_log_entries, which is what makes the verdict retryable now.

const PKRT_PEER = 'the-galaxy-that-imported-first';

const PKRT_SELF = 'the-mac-that-holds-the-id';

function pkrtUser(): User
{
    return User::query()->create([
        'username' => 'pkrt-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function pkrtRegister(DatabaseManager $db, int $userId, string $deviceId, string $publicKey, bool $self): void
{
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $self ? 'Mac' : 'Galaxy A51',
        'ed25519_public_key_hex' => bin2hex($publicKey),
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

/** @return array{account: int, run: int} */
function pkrtParents(DatabaseManager $db, int $userId): array
{
    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN betaalrekening',
        'slug' => 'pkrt-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00PKRT'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pkrt.csv',
        'sha256' => hash('sha256', 'pkrt-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-02 00:00:00',
        'status' => 'confirmed',
        'created_at' => '2026-01-02 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ]);

    return ['account' => $accountId, 'run' => $runId];
}

/** @return array<string, mixed> */
function pkrtRow(int $userId, int $accountId, int $runId, string $day, int $amountMinor, string $writtenAt): array
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

function pkrtWriter(int $userId, string $deviceId, string $secretKey, string $publicKey): OpLogWriter
{
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => $secretKey,
        'publicKey' => $publicKey,
    ]);

    return $writer;
}

/**
 * @param  array<string, mixed>  $row
 */
function pkrtCreate(int $userId, string $deviceId, string $secretKey, string $publicKey, string $table, int|string $pk, array $row): void
{
    unset($row['user_id']);

    pkrtWriter($userId, $deviceId, $secretKey, $publicKey)->writeCreateRow($table, $pk, $row);
}

// The audit row a build with no re-home left behind, written before the stamp
// the pass reads so the test drives the same shape the desktop is in.
function pkrtHold(DatabaseManager $db, int $userId, string $table, int|string $pk): void
{
    $db->connection()->table('op_log_quarantine')->insert([
        'user_id' => $userId,
        'op_entry_id' => null,
        'table_name' => $table,
        'pk' => (string) $pk,
        'device_id' => PKRT_PEER,
        'reason' => 'primary_key_collision',
        'gdk_epoch' => null,
        'hlc_l' => 1,
        'hlc_c' => 0,
        'raw_value' => null,
        'created_at' => '2026-09-01 22:44:19',
    ]);
}

/** @return list<int> */
function pkrtHoldIds(DatabaseManager $db, int $userId): array
{
    return array_map(
        static fn (mixed $id): int => (int) $id,
        $db->connection()->table('op_log_quarantine')->where('user_id', $userId)->orderBy('id')->pluck('id')->all(),
    );
}

/** @return list<string> */
function pkrtReasons(DatabaseManager $db, int $userId): array
{
    /** @var list<string> $reasons */
    $reasons = $db->connection()->table('op_log_quarantine')->where('user_id', $userId)->pluck('reason')->all();

    return $reasons;
}

function pkrtAliasFor(DatabaseManager $db, int $userId, string $table, int|string $peerPk): ?string
{
    $local = $db->connection()->table('op_log_row_aliases')
        ->where('user_id', $userId)
        ->where('table_name', $table)
        ->where('remote_id', (string) $peerPk)
        ->value('local_id');

    return is_string($local) ? $local : null;
}

function pkrtReproject(int $userId, ?string $since = null, ?string $lastFingerprint = null): int
{
    /** @var Session $session */
    $session = app(Session::class);

    return app(HistoryReprojector::class)->replayQuarantined($userId, $session, $since, $lastFingerprint);
}

beforeEach(function (): void {
    $this->user = pkrtUser();
    $userId = (int) $this->user->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $peer = sodium_crypto_sign_keypair();
    $this->peerSecret = sodium_crypto_sign_secretkey($peer);
    $this->peerPublic = sodium_crypto_sign_publickey($peer);

    $self = sodium_crypto_sign_keypair();
    $this->selfSecret = sodium_crypto_sign_secretkey($self);
    $this->selfPublic = sodium_crypto_sign_publickey($self);

    pkrtRegister($db, $userId, PKRT_PEER, $this->peerPublic, self: false);
    pkrtRegister($db, $userId, PKRT_SELF, $this->selfPublic, self: true);

    $parents = pkrtParents($db, $userId);
    $this->accountId = $parents['account'];
    $this->runId = $parents['run'];

    // What this device imported before pairing, and the id it took.
    $this->localId = (int) $db->connection()->table('transactions')->insertGetId(
        pkrtRow($userId, $this->accountId, $this->runId, '2026-08-01', -125000, '2026-08-01 18:12:03'),
    );

    // The peer's row under the same id, refused. Written FIRST so this device's
    // own capture of the row it already holds carries the later clock — which
    // is the state the desktop is in, and the reason the pass cannot simply
    // replay everything the log holds at that id.
    pkrtCreate(
        $userId, PKRT_PEER, $this->peerSecret, $this->peerPublic,
        'transactions', $this->localId,
        pkrtRow($userId, $this->accountId, $this->runId, '2026-02-02', -399, '2026-02-02 08:30:00'),
    );

    pkrtCreate(
        $userId, PKRT_SELF, $this->selfSecret, $this->selfPublic,
        'transactions', $this->localId,
        pkrtRow($userId, $this->accountId, $this->runId, '2026-08-01', -125000, '2026-08-01 18:12:03'),
    );

    pkrtHold($db, $userId, 'transactions', $this->localId);
});

// The fixture's own premise. Without both devices' creates under one id, a pass
// that replayed every op at that pk would look correct here and lose the row on
// the device this was measured on.
it('holds two devices\' creates under the one id', function (): void {
    $authors = $this->db->connection()->table('op_log_entries')
        ->where('table_name', 'transactions')->where('pk', (string) $this->localId)
        ->distinct()->pluck('device_id')->all();

    sort($authors);

    expect($authors)->toBe([PKRT_PEER, PKRT_SELF]);
});

// Before the change: `primary_key_collision` was outside recoverable(), so
// rowsWorthReplaying() never named the row, the pass replayed nothing, and the
// count stayed at one for ever.
it('stores the refused create under an id of this device', function (): void {
    $userId = (int) $this->user->id;

    pkrtReproject($userId);

    $rehomed = $this->db->connection()->table('transactions')->where('posted_at', '2026-02-02')->first();

    expect($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(2)
        ->and($rehomed?->amount_minor)->toBe(-399)
        ->and((int) ($rehomed?->id ?? 0))->not->toBe($this->localId);
});

// The assertion a whole-row retry fails: replaying every op under the id
// resolves the two creates together, and this device's own is the later one, so
// the payload comes back as the row already here and nothing is re-homed.
it('re-homes the peer\'s row rather than the one already at the id', function (): void {
    pkrtReproject((int) $this->user->id);

    $squatter = $this->db->connection()->table('transactions')->where('id', $this->localId)->first();

    expect($squatter?->amount_minor)->toBe(-125000)
        ->and($squatter?->posted_at)->toBe('2026-08-01');
});

// Before the change there was no alias, so every later op the peer sent for
// this row landed on the unrelated local row sitting at the id.
it('maps the id the peer uses to the id this device gave the row', function (): void {
    $userId = (int) $this->user->id;

    pkrtReproject($userId);

    $rehomedId = $this->db->connection()->table('transactions')->where('posted_at', '2026-02-02')->value('id');

    expect(pkrtAliasFor($this->db, $userId, 'transactions', $this->localId))->toBe((string) $rehomedId);
});

// The retirement half: a reason a pass takes again must also be one it lets go
// of. Before the change nothing ever deleted one of these, so the hold outlived
// the answer and every later pass redid the same work.
it('retires the hold it has answered', function (): void {
    $userId = (int) $this->user->id;

    expect(pkrtHoldIds($this->db, $userId))->toHaveCount(1);

    pkrtReproject($userId);

    expect(pkrtHoldIds($this->db, $userId))->toBe([]);
});

// Bounded and idempotent: the re-homed row is found by its natural key on the
// next pass, so a second one aliases instead of inserting. A design that could
// not recognise its own re-home adds a copy per pass.
it('leaves one re-homed row however often the pass runs', function (): void {
    $userId = (int) $this->user->id;

    pkrtReproject($userId);
    pkrtReproject($userId);
    pkrtReproject($userId);

    expect($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(2)
        ->and($this->db->connection()->table('op_log_row_aliases')->where('user_id', $userId)->count())->toBe(1)
        ->and(pkrtReasons($this->db, $userId))->toBe([]);
});

// The negative control. `goals` declares no unique index a re-homed row could
// be found by again, so the create cannot be placed, and one retry is all it
// ever gets: the pass re-records it as the terminal reason, which no later
// pass selects, and the reader is told instead of being made to wait.
it('retries a collision no natural key can place exactly once', function (): void {
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

    pkrtCreate($userId, PKRT_PEER, $this->peerSecret, $this->peerPublic, 'goals', 4242, [
        'name' => 'Zonnepanelen',
        'target_minor' => 900000,
        'target_currency' => 'EUR',
        'start_date' => '2026-09-02',
        'target_date' => '2028-01-31',
        'status' => 'active',
        'created_at' => '2026-09-02 22:55:05',
        'updated_at' => '2026-09-02 22:55:05',
    ]);

    pkrtHold($this->db, $userId, 'goals', 4242);

    $before = pkrtHoldIds($this->db, $userId);

    pkrtReproject($userId);
    $afterOne = pkrtHoldIds($this->db, $userId);

    pkrtReproject($userId);

    expect($this->db->connection()->table('goals')->where('user_id', $userId)->count())->toBe(1)
        ->and($this->db->connection()->table('goals')->where('id', 4242)->value('name'))->toBe('Nieuwe fiets')
        ->and(pkrtReasons($this->db, $userId))->toBe(['unplaceable_collision'])
        ->and($afterOne)->not->toBe($before)
        ->and(pkrtHoldIds($this->db, $userId))->toBe($afterOne);
});

// The other bound: a hold older than the caller's stamp is one an earlier pass
// already answered for this keyring. Retrying it on every request is the
// recurring full replay the pass window exists to stop.
it('does not take a collision again outside the pass window', function (): void {
    $userId = (int) $this->user->id;

    $before = pkrtHoldIds($this->db, $userId);

    pkrtReproject($userId, since: '2026-09-02 00:00:00');

    expect($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(1)
        ->and(pkrtHoldIds($this->db, $userId))->toBe($before)
        ->and(pkrtAliasFor($this->db, $userId, 'transactions', $this->localId))->toBeNull();
});
