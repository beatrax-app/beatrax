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
use Modules\Sync\Internal\OpLog\QuarantineReason;

uses(RefreshDatabase::class);

// Measured on a paired Mac and Galaxy A51. Forty-seven of the phone's
// transaction creates sat in quarantine, so no alias existed and there was
// nothing for translate() to rewrite. The phone's alerts then arrived naming
// the phone's transaction ids, found a charge of this device's wearing each
// number, satisfied the foreign key, and inserted against the wrong charge.

const ORPHANFK_PEER = 'the-phone-whose-parents-never-landed';

function orphanFkUser(): User
{
    return User::query()->create([
        'username' => 'orphanfk-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function orphanFkAccount(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN betaalrekening',
        'slug' => 'orphanfk-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ORPH'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function orphanFkImportRun(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/orphanfk.csv',
        'sha256' => hash('sha256', 'orphanfk-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-02 00:00:00',
        'status' => 'confirmed',
        'created_at' => '2026-01-02 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ]);
}

/** @return array<string, mixed> */
function orphanFkTransaction(int $userId, int $accountId, int $runId, string $day, int $amountMinor): array
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
        'created_at' => $day.' 00:00:00',
        'updated_at' => $day.' 00:00:00',
    ];
}

function orphanFkWriter(int $userId): OpLogWriter
{
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => ORPHANFK_PEER,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey(test()->peerKeypair),
        'publicKey' => sodium_crypto_sign_publickey(test()->peerKeypair),
    ]);

    return $writer;
}

/**
 * @param  array<string, mixed>  $row
 */
function orphanFkPeerCreate(string $table, int|string $peerPk, array $row): void
{
    unset($row['user_id']);

    orphanFkWriter((int) test()->user->id)->writeCreateRow($table, $peerPk, $row);
}

// Only the ops written since the last frame, because a frame boundary is where
// this defect lives: the parent is refused in one and the child arrives in the
// next, with nothing between them that places the parent.
/** @return list<OpLogEntry> */
function orphanFkFrame(DatabaseManager $db, int $userId, int $afterId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('id', '>', $afterId)
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

function orphanFkLatestOpId(DatabaseManager $db, int $userId): int
{
    /** @var mixed $id */
    $id = $db->connection()->table('op_log_entries')->where('user_id', $userId)->max('id');

    return is_numeric($id) ? (int) $id : 0;
}

function orphanFkReplay(DatabaseManager $db, int $userId, int $afterId): void
{
    $keys = [ORPHANFK_PEER => bin2hex(sodium_crypto_sign_publickey(test()->peerKeypair))];

    (new OpLogReplayer($db, $keys, new MergeRulesRegistry))->replay(orphanFkFrame($db, $userId, $afterId), $userId);
}

/** @return list<string> */
function orphanFkHeld(DatabaseManager $db, int $userId, string $table): array
{
    /** @var list<string> $reasons */
    $reasons = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('table_name', $table)
        ->pluck('reason')
        ->all();

    return $reasons;
}

// One alert, shaped the way the anomaly detector puts it on the wire. The
// amount is the charge's own, which is what makes a wrong parent readable: the
// alert states a figure the transaction it names does not have.
/** @return array<string, mixed> */
function orphanFkAlert(int $userId, int $transactionId, int $amountMinor): array
{
    return [
        'user_id' => $userId,
        'transaction_id' => $transactionId,
        'state' => 'open',
        'direction' => 'expense',
        'reasons' => '["duplicate"]',
        'baseline_amount_minor' => $amountMinor,
        'latest_amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'sensitivity_percent_used' => 50,
        'detected_at' => '2026-09-10 22:44:19',
        'created_at' => '2026-09-10 22:44:19',
        'updated_at' => '2026-09-10 22:44:19',
    ];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-10 22:44:19');

    $this->user = orphanFkUser();
    $this->peerKeypair = sodium_crypto_sign_keypair();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $userId = (int) $this->user->id;
    $this->accountId = orphanFkAccount($db, $userId);
    $this->runId = orphanFkImportRun($db, $userId);

    // What this device imported before pairing. The peer's statement took the
    // same autoincrement for an entirely different purchase.
    $this->squatterId = (int) $db->connection()->table('transactions')->insertGetId(
        orphanFkTransaction($userId, $this->accountId, $this->runId, '2026-08-01', -125000),
    );
});

afterEach(fn () => CarbonImmutable::setTestNow());

// The peer's create for the charge, refused and left in quarantine: its own
// account has not arrived, so there is nowhere to put the row and no natural
// key match to alias. The number stays this device's other charge.
function orphanFkRefuseTheParent(DatabaseManager $db, int $userId, int $peerPk): int
{
    $before = orphanFkLatestOpId($db, $userId);

    // Every parent it names is the peer's own, and none of them is here: the
    // account it was imported under has not arrived, so the insert is refused
    // and the re-home that would have placed it is refused for the same reason.
    orphanFkPeerCreate('transactions', $peerPk, orphanFkTransaction($userId, 4_242, 4_242, '2026-02-02', -2050));
    orphanFkReplay($db, $userId, $before);

    return orphanFkLatestOpId($db, $userId);
}

it('leaves a hold saying the peer\'s charge is not here under that number', function (): void {
    $userId = (int) $this->user->id;

    orphanFkRefuseTheParent($this->db, $userId, $this->squatterId);

    expect(orphanFkHeld($this->db, $userId, 'transactions'))->toBe([QuarantineReason::PrimaryKeyCollision->value])
        ->and($this->db->connection()->table('op_log_row_aliases')->where('user_id', $userId)->count())->toBe(0)
        ->and($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(1);
});

it('refuses an alert whose charge the peer never got to send', function (): void {
    $userId = (int) $this->user->id;

    $afterParent = orphanFkRefuseTheParent($this->db, $userId, $this->squatterId);

    orphanFkPeerCreate('anomaly_alerts', 5561945735144355233, orphanFkAlert($userId, $this->squatterId, -2050));
    orphanFkReplay($this->db, $userId, $afterParent);

    $landed = $this->db->connection()->table('anomaly_alerts')->where('user_id', $userId)->first();

    expect($landed)->toBeNull(
        'The alert was attached to whichever local charge wore the number the peer sent.',
    )->and(orphanFkHeld($this->db, $userId, 'anomaly_alerts'))->toBe([QuarantineReason::MissingReference->value]);
});

// The reason is chosen for what a later pass does with it, not for how hopeless
// the parent's own hold looks: the parent carries the terminal-or-recoverable
// verdict already, and a copy of it on every child goes stale when that one does.
it('holds the alert for a reason a later pass takes again', function (): void {
    $userId = (int) $this->user->id;

    $afterParent = orphanFkRefuseTheParent($this->db, $userId, $this->squatterId);

    orphanFkPeerCreate('anomaly_alerts', 5561945735144355233, orphanFkAlert($userId, $this->squatterId, -2050));
    orphanFkReplay($this->db, $userId, $afterParent);

    expect(QuarantineReason::recoverable())->toContain(QuarantineReason::MissingReference->value);
});

// The case a fix that refused everything unaliased would break, and it is the
// common one: the id was minted before the devices diverged, or the peer's row
// is already here under it. Nothing about that id is in doubt.
it('admits an alert naming a charge both devices hold under that number', function (): void {
    $userId = (int) $this->user->id;
    $before = orphanFkLatestOpId($this->db, $userId);

    orphanFkPeerCreate('anomaly_alerts', 3026504667045297670, orphanFkAlert($userId, $this->squatterId, -125000));
    orphanFkReplay($this->db, $userId, $before);

    $landed = $this->db->connection()->table('anomaly_alerts')->where('user_id', $userId)->first();

    expect((int) ($landed->transaction_id ?? 0))->toBe($this->squatterId)
        ->and(orphanFkHeld($this->db, $userId, 'anomaly_alerts'))->toBe([]);
});

// A hold on one number says nothing about the next. The gate reads the id the
// reference actually names, so a second charge the two devices agree on still
// admits its alert while the first is refused.
it('refuses only the alert whose own parent is unplaced', function (): void {
    $userId = (int) $this->user->id;

    $agreed = (int) $this->db->connection()->table('transactions')->insertGetId(
        orphanFkTransaction($userId, $this->accountId, $this->runId, '2026-07-15', -3399),
    );

    $afterParent = orphanFkRefuseTheParent($this->db, $userId, $this->squatterId);

    orphanFkPeerCreate('anomaly_alerts', 5561945735144355233, orphanFkAlert($userId, $this->squatterId, -2050));
    orphanFkPeerCreate('anomaly_alerts', 3026504667045297670, orphanFkAlert($userId, $agreed, -3399));
    orphanFkReplay($this->db, $userId, $afterParent);

    /** @var list<int> $attached */
    $attached = $this->db->connection()->table('anomaly_alerts')->where('user_id', $userId)->pluck('transaction_id')->all();

    expect(array_map(intval(...), $attached))->toBe([$agreed])
        ->and(orphanFkHeld($this->db, $userId, 'anomaly_alerts'))->toBe([QuarantineReason::MissingReference->value]);
});

// The hold is not a dead end. Once the peer's charge is placed — re-homed by a
// later pass, or landing when its account does — the alias is what the next
// replay of the alert spends, and it lands on the charge the peer meant.
it('lands the alert on the peer\'s own charge once that charge is placed', function (): void {
    $userId = (int) $this->user->id;

    $afterParent = orphanFkRefuseTheParent($this->db, $userId, $this->squatterId);

    orphanFkPeerCreate('anomaly_alerts', 5561945735144355233, orphanFkAlert($userId, $this->squatterId, -2050));
    orphanFkReplay($this->db, $userId, $afterParent);

    $placed = (int) $this->db->connection()->table('transactions')->insertGetId(
        orphanFkTransaction($userId, $this->accountId, $this->runId, '2026-02-02', -2050),
    );

    $this->db->connection()->table('op_log_row_aliases')->insert([
        'user_id' => $userId,
        'table_name' => 'transactions',
        'device_id' => ORPHANFK_PEER,
        'remote_id' => (string) $this->squatterId,
        'local_id' => (string) $placed,
        'created_at' => '2026-02-02 00:00:00',
    ]);

    orphanFkReplay($this->db, $userId, $afterParent);

    expect((int) $this->db->connection()->table('anomaly_alerts')->where('user_id', $userId)->value('transaction_id'))
        ->toBe($placed);
});

// The boundary the gate is keyed on. An edit a peer sent for a charge both
// devices already agree on is held for its own reasons -- an unheld key, a
// column this build has no schema for -- and says nothing about whether the
// number names a row. Reading one as a missing parent refuses good rows.
it('admits an alert whose parent is held for an edit rather than a create', function (): void {
    $userId = (int) $this->user->id;
    $before = orphanFkLatestOpId($this->db, $userId);

    $this->db->connection()->table('op_log_quarantine')->insert([
        'user_id' => $userId,
        'table_name' => 'transactions',
        'pk' => (string) $this->squatterId,
        'device_id' => ORPHANFK_PEER,
        'op_type' => OpType::Set->value,
        'reason' => QuarantineReason::GdkDecryptFailed->value,
        'created_at' => '2026-09-10 22:00:00',
    ]);

    orphanFkPeerCreate('anomaly_alerts', 3026504667045297670, orphanFkAlert($userId, $this->squatterId, -125000));
    orphanFkReplay($this->db, $userId, $before);

    expect((int) $this->db->connection()->table('anomaly_alerts')->where('user_id', $userId)->value('transaction_id'))
        ->toBe($this->squatterId)
        ->and(orphanFkHeld($this->db, $userId, 'anomaly_alerts'))->toBe([]);
});

// A transfer leg names its partner, and the partner routinely has not landed
// when the leg does -- which is what SelfReferenceDeferral is for. Refusing the
// charge because the id it links to is held would lose a charge over a link.
it('still writes a charge whose partner leg is the one being held', function (): void {
    $userId = (int) $this->user->id;

    $afterParent = orphanFkRefuseTheParent($this->db, $userId, $this->squatterId);

    $leg = orphanFkTransaction($userId, $this->accountId, $this->runId, '2026-03-03', 2050);
    $leg['type'] = 'transfer_out';
    $leg['pair_transaction_id'] = $this->squatterId;

    orphanFkPeerCreate('transactions', 90_001, $leg);
    orphanFkReplay($this->db, $userId, $afterParent);

    expect($this->db->connection()->table('transactions')->where('id', 90_001)->exists())->toBeTrue(
        'The charge was refused because the leg it names is held, which loses a charge over a link.',
    );
});
