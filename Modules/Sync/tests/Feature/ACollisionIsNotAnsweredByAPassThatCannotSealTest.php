<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\RetriedCollisionCreates;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\Support\DevicesScreenOpening;
use Modules\Sync\Public\Exceptions\SensitiveColumnKeyUnavailableException;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\EncryptionRecoveryMarkers;
use Modules\Sync\Public\Services\HistoryReprojector;
use Modules\Sync\Public\Services\SealedProjectionReadiness;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

// A sealed ledger whose key this process cannot reach still admitted the
// recovery pass: two mobile callers gated it on enrolment, which is the
// `current_epoch` pointer and not the key behind it. The pass then rebuilt
// every collision create it could not seal, recorded the refusal as
// `strategy_error`, and retired the `primary_key_collision` hold anyway — so
// the narrow retry that re-homes the peer's row was never asked again, and the
// replay that resolves EVERY device's ops at the pk took it over.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#a-pass-that-cannot-seal-must-not-run
 */
const KCOL_PEER = 'the-galaxy-whose-create-was-refused';

const KCOL_SELF = 'the-mac-that-holds-the-id';

function kcolUser(): User
{
    return User::query()->create([
        'username' => 'kcol-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function kcolRegister(DatabaseManager $db, int $userId, string $deviceId, string $publicKey, bool $self): void
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
function kcolParents(DatabaseManager $db, int $userId): array
{
    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN betaalrekening',
        'slug' => 'kcol-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00KCOL'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/kcol.csv',
        'sha256' => hash('sha256', 'kcol-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-02 00:00:00',
        'status' => 'confirmed',
        'created_at' => '2026-01-02 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ]);

    return ['account' => $accountId, 'run' => $runId];
}

// `note` is a registered sensitive column, which is what makes this row one a
// keyless process cannot project: every other value here would write fine.
/** @return array<string, mixed> */
function kcolRow(int $userId, int $accountId, int $runId, string $day, int $amountMinor, string $note): array
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
        'note' => $note,
        'fingerprint' => $composer->composeTuple(new FingerprintTuple(
            $userId, $accountId, $day, $day.' 00:00:00', $amountMinor, 'EUR', 'albert heijn', 0,
        )),
        'fingerprint_version' => FingerprintComposer::NORMALIZATION_VERSION,
        'status' => 'cleared',
        'created_at' => $day.' 09:00:00',
        'updated_at' => $day.' 09:00:00',
    ];
}

function kcolWriter(int $userId, string $deviceId, string $secretKey, string $publicKey): OpLogWriter
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
function kcolCreate(int $userId, string $deviceId, string $secretKey, string $publicKey, int|string $pk, array $row): void
{
    unset($row['user_id']);

    kcolWriter($userId, $deviceId, $secretKey, $publicKey)->writeCreateRow('transactions', $pk, $row);
}

// The audit row an older build left behind, written before the pass window so
// the fixture drives the same shape the paired Mac is in.
function kcolHold(DatabaseManager $db, int $userId, int|string $pk): void
{
    $db->connection()->table('op_log_quarantine')->insert([
        'user_id' => $userId,
        'op_entry_id' => null,
        'table_name' => 'transactions',
        'pk' => (string) $pk,
        'device_id' => KCOL_PEER,
        'reason' => 'primary_key_collision',
        'gdk_epoch' => null,
        'hlc_l' => 1,
        'hlc_c' => 0,
        'raw_value' => null,
        'created_at' => '2026-09-01 22:44:19',
    ]);
}

/** @return list<string> */
function kcolReasons(DatabaseManager $db, int $userId): array
{
    /** @var list<string> $reasons */
    $reasons = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)->orderBy('id')->pluck('reason')->all();

    return $reasons;
}

function kcolSession(): Session
{
    /** @var Session $session */
    $session = app(Session::class);

    return $session;
}

function kcolReproject(int $userId): int
{
    return app(HistoryReprojector::class)->replayQuarantined($userId, kcolSession(), null, null);
}

beforeEach(function (): void {
    $this->user = kcolUser();
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

    kcolRegister($db, $userId, KCOL_PEER, $this->peerPublic, self: false);
    kcolRegister($db, $userId, KCOL_SELF, $this->selfPublic, self: true);

    $parents = kcolParents($db, $userId);
    $this->accountId = $parents['account'];
    $this->runId = $parents['run'];

    // What this device imported before pairing, and the id it took.
    $this->localId = (int) $db->connection()->table('transactions')->insertGetId(
        kcolRow($userId, $this->accountId, $this->runId, '2026-08-01', -125000, 'the note this device wrote'),
    );

    // Both devices' creates under the one id, written while nothing was sealed
    // — which is why the entries carry no epoch and a keyless pass selects them.
    kcolCreate(
        $userId, KCOL_PEER, $this->peerSecret, $this->peerPublic, $this->localId,
        kcolRow($userId, $this->accountId, $this->runId, '2026-02-02', -399, 'the note the peer wrote'),
    );

    kcolCreate(
        $userId, KCOL_SELF, $this->selfSecret, $this->selfPublic, $this->localId,
        kcolRow($userId, $this->accountId, $this->runId, '2026-08-01', -125000, 'the note this device wrote'),
    );

    kcolHold($db, $userId, $this->localId);

    AppLockTestHarness::unlock(kcolSession(), str_repeat("\x2a", 32));
    app(GdkKeyringService::class)->generateAndPersist($userId, kcolSession());
});

// The fixture's own premise, and the distinction the two mobile gates missed:
// enrolment is a pointer read with no key behind it.
it('is enrolled while the key it would seal with is out of reach', function (): void {
    $userId = (int) $this->user->id;
    AppLockTestHarness::lock(kcolSession());

    expect(app(EncryptionRecoveryMarkers::class)->isEnrolled($userId))->toBeTrue()
        ->and(app(SensitiveColumnCodec::class)->canSeal($userId, kcolSession()))->toBeFalse()
        ->and(app(SealedProjectionReadiness::class)->canProject($userId, kcolSession()))->toBeFalse();
});

it('refuses the pass rather than recording a create it cannot seal', function (): void {
    $userId = (int) $this->user->id;
    AppLockTestHarness::lock(kcolSession());

    expect(fn (): int => kcolReproject($userId))->toThrow(SensitiveColumnKeyUnavailableException::class)
        ->and(kcolReasons($this->db, $userId))->toBe(['primary_key_collision']);
});

// The caller that had the weakest gate of the three. It swallows whatever the
// pass raises, so the assertion is on what the quarantine holds afterwards.
it('leaves the hold standing when the devices screen opens with no key', function (): void {
    $userId = (int) $this->user->id;
    AppLockTestHarness::lock(kcolSession());

    app(DevicesScreenOpening::class)->recoverDeferred($userId, kcolSession());

    expect(kcolReasons($this->db, $userId))->toBe(['primary_key_collision']);
});

// The damage. Before the gate, one keyless pass retired the collision hold and
// left two `strategy_error` holds in its place — so the narrow retry was never
// asked again and the peer's row could not be re-homed by any later pass,
// however many keys arrived.
it('keeps the peer row recoverable once the key comes back', function (): void {
    $userId = (int) $this->user->id;

    AppLockTestHarness::lock(kcolSession());
    app(DevicesScreenOpening::class)->recoverDeferred($userId, kcolSession());

    AppLockTestHarness::unlock(kcolSession(), str_repeat("\x2a", 32));
    kcolReproject($userId);

    $rehomed = $this->db->connection()->table('transactions')->where('posted_at', '2026-02-02')->first();

    expect($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(2)
        ->and($rehomed?->amount_minor)->toBe(-399)
        ->and(kcolReasons($this->db, $userId))->toBe([]);
});

// The other half of losing the hold: with the collision no longer named, the
// pk goes through the replay that resolves every device's ops at it, and the
// peer's later edit lands on this device's unrelated row.
it('does not move the peer values onto the row already at the id', function (): void {
    $userId = (int) $this->user->id;

    kcolWriter($userId, KCOL_PEER, $this->peerSecret, $this->peerPublic)
        ->writeSet('transactions', $this->localId, 'amount_minor', -777);

    AppLockTestHarness::lock(kcolSession());
    app(DevicesScreenOpening::class)->recoverDeferred($userId, kcolSession());

    AppLockTestHarness::unlock(kcolSession(), str_repeat("\x2a", 32));
    kcolReproject($userId);

    $squatter = $this->db->connection()->table('transactions')->where('id', $this->localId)->first();

    expect($squatter?->amount_minor)->toBe(-125000)
        ->and($squatter?->posted_at)->toBe('2026-08-01');
});

// Asked of the retry itself, past the gate above, because the gate is not the
// only thing standing between a create and a hold it did not answer: any
// refusal recorded before the id is looked at leaves the row unplaced, and the
// hold was retired on all of them.
it('reports no hold spent when the retried create never reached the id', function (): void {
    $userId = (int) $this->user->id;
    AppLockTestHarness::lock(kcolSession());

    $replayer = new OpLogReplayer(
        db: $this->db,
        deviceKeys: app(DeviceRegistryService::class)->signatureVerificationKeys($userId),
        deviceKeysUserId: $userId,
        rules: app(MergeRulesRegistry::class),
        searchWriter: null,
    );

    $held = $this->db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('reason', 'primary_key_collision');

    $answered = app(RetriedCollisionCreates::class)->replay($held, $replayer, $userId, 500);

    expect($answered['spent'])->toBe([]);
});
