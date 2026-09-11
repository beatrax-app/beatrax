<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\PersistedOpLogEntries;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\HistoryReprojector;

uses(RefreshDatabase::class);

// The ten `missing_reference` holds a paired Galaxy A51 was still carrying. The
// pass that would answer them reopens on key material moving or on this build
// reaching further, and the thing that undoes a missing reference is neither:
// it is the parent row arriving, which moves no watermark at all.

const ARRV_PEER = 'the-galaxy-that-sent-the-child-first';

const ARRV_SELF = 'the-mac-that-had-no-account-for-it';

// The id the peer's account arrives under, chosen so no local row wears it.
const ARRV_ACCOUNT = 900;

const ARRV_TRANSACTION = 500;

function arrvUser(): User
{
    return User::query()->create([
        'username' => 'arrv-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function arrvRegister(DatabaseManager $db, int $userId, string $deviceId, string $publicKey, bool $self): void
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

/**
 * @param  array<string, mixed>  $row
 */
function arrvCreate(int $userId, string $secretKey, string $publicKey, string $table, int|string $pk, array $row): void
{
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => ARRV_PEER,
        'userId' => $userId,
        'secretKey' => $secretKey,
        'publicKey' => $publicKey,
    ]);

    unset($row['user_id']);

    $writer->writeCreateRow($table, $pk, $row);
}

// Only the rows named, so a frame that carried the child and not its parent can
// be replayed as it arrived.
/**
 * @param  list<array{table: string, pk: string}>  $rows
 */
function arrvReplay(DatabaseManager $db, int $userId, array $rows): void
{
    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: $registry->signatureVerificationKeys($userId),
        deviceKeysUserId: $userId,
    );

    $replayer->replay(app(PersistedOpLogEntries::class)->forRows($userId, $rows), $userId);
}

function arrvPass(int $userId, ?string $since = null): int
{
    /** @var Session $session */
    $session = app(Session::class);

    /** @var HistoryReprojector $reprojector */
    $reprojector = app(HistoryReprojector::class);

    return $reprojector->replayQuarantined($userId, $session, $since, $reprojector->passIdentity($userId));
}

/** @return array<string, mixed> */
function arrvAccountRow(string $slug): array
{
    return [
        'name' => 'ASN betaalrekening',
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => 'NL00ARRV'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-08-30 09:00:00',
        'updated_at' => '2026-08-30 09:00:00',
    ];
}

/** @return array<string, mixed> */
function arrvTransactionRow(int $userId, int $runId): array
{
    /** @var FingerprintComposer $composer */
    $composer = app(FingerprintComposer::class);

    return [
        'account_id' => ARRV_ACCOUNT,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => '2026-08-31',
        'booked_at' => '2026-08-31 00:00:00',
        'value_date' => '2026-08-31',
        'amount_minor' => -4500,
        'currency' => 'EUR',
        'settled_amount_minor' => -4500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => FingerprintComposer::NORMALIZATION_VERSION,
        'source_format' => 'asn-csv',
        'source_row_index' => 3,
        'occurrence_ordinal' => 0,
        'fingerprint' => $composer->composeTuple(new FingerprintTuple(
            $userId, ARRV_ACCOUNT, '2026-08-31', '2026-08-31 00:00:00', -4500, 'EUR', 'albert heijn', 0,
        )),
        'fingerprint_version' => FingerprintComposer::NORMALIZATION_VERSION,
        'status' => 'cleared',
        'created_at' => '2026-08-31 19:22:41',
        'updated_at' => '2026-08-31 19:22:41',
    ];
}

/** @return list<string> */
function arrvReasons(DatabaseManager $db, int $userId): array
{
    /** @var list<string> $reasons */
    $reasons = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)->orderBy('id')->pluck('reason')->all();

    return $reasons;
}

/** @return list<int> */
function arrvHoldIds(DatabaseManager $db, int $userId): array
{
    return array_map(
        static fn (mixed $id): int => (int) $id,
        $db->connection()->table('op_log_quarantine')->where('user_id', $userId)->orderBy('id')->pluck('id')->all(),
    );
}

beforeEach(function (): void {
    $this->user = arrvUser();
    $userId = (int) $this->user->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $peer = sodium_crypto_sign_keypair();
    $this->peerSecret = sodium_crypto_sign_secretkey($peer);
    $this->peerPublic = sodium_crypto_sign_publickey($peer);

    $self = sodium_crypto_sign_keypair();

    arrvRegister($db, $userId, ARRV_PEER, $this->peerPublic, self: false);
    arrvRegister($db, $userId, ARRV_SELF, sodium_crypto_sign_publickey($self), self: true);

    $this->runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/arrv.csv',
        'sha256' => hash('sha256', 'arrv-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-08-30 09:05:00',
        'status' => 'confirmed',
        'created_at' => '2026-08-30 09:05:00',
        'updated_at' => '2026-08-30 09:05:00',
    ]);

    // The frame that carried the charge and not the account it names. Both
    // creates are written at the earlier clock, so the hold the replay records
    // is older than the stamp every later pass reads.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 21:00:00'));

    arrvCreate($userId, $this->peerSecret, $this->peerPublic, 'accounts', ARRV_ACCOUNT, arrvAccountRow('arrv-peer-account'));
    arrvCreate($userId, $this->peerSecret, $this->peerPublic, 'transactions', ARRV_TRANSACTION, arrvTransactionRow($userId, $this->runId));

    arrvReplay($db, $userId, [['table' => 'transactions', 'pk' => (string) ARRV_TRANSACTION]]);

    CarbonImmutable::setTestNow();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// The fixture's own premise: a charge refused for a parent that is not here,
// held under a reason no key and no build version undoes.
it('holds the charge for the account that had not landed', function (): void {
    $userId = (int) $this->user->id;

    expect(arrvReasons($this->db, $userId))->toBe([QuarantineReason::MissingReference->value])
        ->and($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(0);
});

// The event that undoes it, and the one the window could not see. Before the
// change the hold was older than the caller's stamp, so no pass named the row
// again however many times the parent arrived.
it('takes the hold again once the account it named has landed', function (): void {
    $userId = (int) $this->user->id;

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03 10:00:00'));
    arrvReplay($this->db, $userId, [['table' => 'accounts', 'pk' => (string) ARRV_ACCOUNT]]);
    CarbonImmutable::setTestNow();

    arrvPass($userId, since: '2026-09-02 00:00:00');

    expect($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(1)
        ->and($this->db->connection()->table('transactions')->where('id', ARRV_TRANSACTION)->value('amount_minor'))->toBe(-4500);
});

// And the hold goes with it. A reason that is retried and not retired is the
// same permanent audit row read from the other end.
it('retires the hold the arrival answered', function (): void {
    $userId = (int) $this->user->id;

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03 10:00:00'));
    arrvReplay($this->db, $userId, [['table' => 'accounts', 'pk' => (string) ARRV_ACCOUNT]]);
    CarbonImmutable::setTestNow();

    arrvPass($userId, since: '2026-09-02 00:00:00');

    expect(arrvReasons($this->db, $userId))->toBe([]);
});

// The bound that has to survive the fix. Nothing has been recorded since the
// stamp, so nothing can have undone the hold, and retrying it on every request
// is the recurring replay the window exists to stop.
it('leaves the hold alone while nothing has landed since the stamp', function (): void {
    $userId = (int) $this->user->id;

    $before = arrvHoldIds($this->db, $userId);

    arrvPass($userId, since: '2026-09-02 00:00:00');

    expect(arrvHoldIds($this->db, $userId))->toBe($before)
        ->and($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(0);
});

// The sibling reason the retirement half never named. `split_sum_unreadable` is
// replayed by every pass and was swept by none of the three retirements, which
// is the shape a hold outliving its answer takes wherever it appears.
it('retires a hold held for a sum it could not read', function (): void {
    $userId = (int) $this->user->id;
    $savings = ARRV_ACCOUNT + 1;

    arrvCreate($userId, $this->peerSecret, $this->peerPublic, 'accounts', $savings, arrvAccountRow('arrv-peer-savings'));
    arrvReplay($this->db, $userId, [
        ['table' => 'accounts', 'pk' => (string) ARRV_ACCOUNT],
        ['table' => 'accounts', 'pk' => (string) $savings],
    ]);

    $this->db->connection()->table('op_log_quarantine')->insert([
        'user_id' => $userId,
        'op_entry_id' => null,
        'table_name' => 'accounts',
        'pk' => (string) $savings,
        'device_id' => ARRV_PEER,
        'op_type' => 'set',
        'reason' => QuarantineReason::SplitSumUnreadable->value,
        'gdk_epoch' => null,
        'hlc_l' => 1,
        'hlc_c' => 0,
        'raw_value' => null,
        'created_at' => '2026-09-01 21:30:00',
    ]);

    arrvPass($userId);

    expect(arrvReasons($this->db, $userId))->toBe([]);
});

// Every reason a pass takes again is on exactly one side of the question the
// window turns on. A new recoverable case that is on neither is one the pass
// would bound by the wrong event, which is how these two were found.
it('classifies every recoverable reason by what undoes it', function (): void {
    $key = QuarantineReason::keyRecoverable();
    $state = QuarantineReason::stateRecoverable();

    expect(array_intersect($key, $state))->toBe([])
        ->and(array_values(array_diff(QuarantineReason::recoverable(), [...$key, ...$state])))->toBe([]);
});
