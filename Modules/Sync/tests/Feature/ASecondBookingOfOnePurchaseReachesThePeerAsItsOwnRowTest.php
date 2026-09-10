<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// A statement books the same purchase twice and states no time of day for
// either, so the two rows agree in every identity column but the occurrence
// ordinal. That makes the ordinal load-bearing on the wire twice over: it has
// to travel, and it has to sit inside the UNIQUE index the applier finds a
// peer's row by, or the second booking is aliased onto the first and one of the
// reader's two purchases never reaches the other device.

// The peer's own autoincrement, which on the receiving device names nothing.
const SECOND_BOOKING_PEER_IDS = [9001, 9002];

function secondBookingUser(): User
{
    return User::query()->create([
        'username' => 'second-booking-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/** @return array{account: int, run: int} */
function secondBookingParents(DatabaseManager $db, int $userId): array
{
    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN two bookings',
        'slug' => 'second-booking-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00BOOK'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-02-01 00:00:00',
        'updated_at' => '2026-02-01 00:00:00',
    ]);

    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/second-booking.csv',
        'sha256' => hash('sha256', 'second-booking-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-02-03 00:00:00',
        'status' => 'confirmed',
        'created_at' => '2026-02-03 00:00:00',
        'updated_at' => '2026-02-03 00:00:00',
    ]);

    return ['account' => $accountId, 'run' => $runId];
}

// One €3.50 coffee, as the ordinal-th booking of it on the statement. Every
// column of the row, because that is what a capture puts on the wire.
/** @return array<string, mixed> */
function secondBookingRow(int $userId, int $accountId, int $runId, int $ordinal): array
{
    /** @var FingerprintComposer $composer */
    $composer = app(FingerprintComposer::class);

    return [
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => '2026-02-03',
        'booked_at' => '2026-02-03 00:00:00',
        'value_date' => '2026-02-03',
        'amount_minor' => -350,
        'currency' => 'EUR',
        'settled_amount_minor' => -350,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Koffiehuis',
        'counterparty_normalized' => 'koffiehuis',
        'normalization_version' => FingerprintComposer::NORMALIZATION_VERSION,
        'description' => 'Koffie',
        'source_format' => 'asn-csv',
        'source_row_index' => $ordinal,
        'occurrence_ordinal' => $ordinal,
        'fingerprint' => $composer->composeTuple(
            $userId, $accountId, '2026-02-03', '2026-02-03 00:00:00', -350, 'EUR', 'koffiehuis', $ordinal,
        ),
        'fingerprint_version' => FingerprintComposer::NORMALIZATION_VERSION,
        'status' => 'cleared',
        'created_at' => '2026-02-03 10:00:00',
        'updated_at' => '2026-02-03 10:00:00',
    ];
}

// The peer's capture: both bookings as CREATE_ROW ops under the peer's own ids.
function secondBookingPeerCaptures(DatabaseManager $db, int $userId, int $accountId, int $runId): string
{
    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'the-other-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => $publicKey,
    ]);

    foreach (SECOND_BOOKING_PEER_IDS as $ordinal => $peerId) {
        $fields = secondBookingRow($userId, $accountId, $runId, $ordinal);
        unset($fields['user_id']);
        $writer->writeCreateRow('transactions', $peerId, $fields);
    }

    return bin2hex($publicKey);
}

/** @return list<OpLogEntry> */
function secondBookingOps(DatabaseManager $db, int $userId): array
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

function secondBookingReplay(DatabaseManager $db, int $userId, string $publicKeyHex): void
{
    (new OpLogReplayer($db, ['the-other-device' => $publicKeyHex], new MergeRulesRegistry))
        ->replay(secondBookingOps($db, $userId), $userId);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-02-04 09:00:00');
    $this->user = secondBookingUser();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $parents = secondBookingParents($db, (int) $this->user->id);
    $this->accountId = $parents['account'];
    $this->runId = $parents['run'];
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('lands both bookings on a device that imported neither', function (): void {
    $userId = (int) $this->user->id;
    $publicKeyHex = secondBookingPeerCaptures($this->db, $userId, $this->accountId, $this->runId);

    secondBookingReplay($this->db, $userId, $publicKeyHex);

    expect($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(
        2,
        'The peer sent two bookings of one purchase and this device kept one, so the two ledgers disagree by 3.50.',
    );

    expect($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);
});

// The other direction, and the one the ordinal has to be inside the index for:
// both devices imported the same statement, so each minted its own ids and the
// arriving creates are refused by the UNIQUE index. Matching them onto the
// local twin reads that index, so an index blind to the ordinal answers "the
// first booking" for both and the peer's id for the second names the wrong row.
it('matches each arriving booking onto the local row that is the same booking', function (): void {
    $userId = (int) $this->user->id;

    $localIds = [];
    foreach ([0, 1] as $ordinal) {
        $localIds[$ordinal] = (int) $this->db->connection()->table('transactions')
            ->insertGetId(secondBookingRow($userId, $this->accountId, $this->runId, $ordinal));
    }

    $publicKeyHex = secondBookingPeerCaptures($this->db, $userId, $this->accountId, $this->runId);
    secondBookingReplay($this->db, $userId, $publicKeyHex);

    expect($this->db->connection()->table('transactions')->where('user_id', $userId)->count())->toBe(
        2,
        'The same statement imported on both devices wrote a third row rather than being recognised.',
    );

    $aliases = [];
    foreach (SECOND_BOOKING_PEER_IDS as $ordinal => $peerId) {
        $aliases[$ordinal] = $this->db->connection()->table('op_log_row_aliases')
            ->where('user_id', $userId)
            ->where('table_name', 'transactions')
            ->where('remote_id', (string) $peerId)
            ->value('local_id');
    }

    expect($aliases[1])->toBe(
        (string) $localIds[1],
        'The peer id for the second booking resolves to the local row for the first, so every op naming it '
        .'edits the wrong purchase.',
    );
    expect($aliases[0])->toBe((string) $localIds[0]);
});
