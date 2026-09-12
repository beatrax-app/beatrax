<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\MergeStrategy;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpLogWriterFactory;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// One card payment the bank filed twice: EUR 12.99 on the 17th, and the same
// sequence number restated at EUR 14.99 on the 19th once the tip settled. The
// enrichment moves the stored row onto the 19th and announced nothing, so the
// reader's other device went on holding a row on a day no statement prints --
// matching no reconcile, and re-importing as a second transaction the next
// time the two statements overlap.

// The terms of one dedup tuple. Named once so a test asserting three of them
// cannot read as a test asserting the set.
const RESTATED_BOOKING = ['posted_at', 'booked_at', 'value_date', 'occurrence_ordinal'];

const RESTATEMENT_DEVICE = 'device-desktop';

function restatementFixture(string $name): string
{
    return __DIR__.'/../../../../tests/fixtures/'.$name;
}

function restatementImport(User $user, string $name): void
{
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);

    $importer->runAndConfirm(
        restatementFixture($name),
        'asn-csv',
        $user,
        formatHint: BankCsvFormatHint::Asn,
    );
}

function restatementWatermark(DatabaseManager $db): int
{
    $max = $db->connection()->table('op_log_entries')->max('id');

    return is_numeric($max) ? (int) $max : 0;
}

/**
 * @return list<OpLogEntry>
 */
function restatementOpsAfter(DatabaseManager $db, int $userId, int $afterId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('id', '>', $afterId)
        ->orderBy('id')
        ->get()
        ->map(static function (object $row): OpLogEntry {
            $pk = is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk;

            return new OpLogEntry(
                table: (string) $row->table_name,
                pk: $pk,
                field: (string) $row->field,
                value: $row->value !== null ? (string) $row->value : null,
                hlcL: (int) $row->hlc_l,
                hlcC: (int) $row->hlc_c,
                deviceId: (string) $row->device_id,
                opType: OpType::from((string) $row->op_type),
                signature: (string) $row->signature,
                userId: (int) $row->user_id,
            );
        })
        ->all();
}

/**
 * The booking columns a set of ops carries, as column => decoded value.
 *
 * @param  list<OpLogEntry>  $ops
 * @return array<string, mixed>
 */
function restatementBookingOps(array $ops): array
{
    $carried = [];

    foreach ($ops as $op) {
        if ($op->table !== 'transactions' || $op->opType !== OpType::Set) {
            continue;
        }

        if (in_array($op->field, RESTATED_BOOKING, true)) {
            $carried[$op->field] = json_decode((string) $op->value, true);
        }
    }

    return $carried;
}

// A signed Set the way a second device would have written it, so the merge
// resolves two real authors rather than one device arguing with itself.
function restatementSignedSet(string $device, string $secretKey, int $userId, int $pk, string $field, mixed $value, int $hlcL): OpLogEntry
{
    $entry = new OpLogEntry(
        table: 'transactions',
        pk: $pk,
        field: $field,
        value: json_encode($value, JSON_THROW_ON_ERROR),
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: $device,
        opType: OpType::Set,
        signature: '',
        userId: $userId,
    );

    return $entry->withSignature((new DeviceKeySigner)->sign($entry->signingPayload(), $secretKey));
}

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    ['user' => $user] = $this->seedFixtureUserAndAccount();
    $this->actingAs($user);
    $this->reader = $user;

    $this->keypair = sodium_crypto_sign_keypair();
    $this->publicKeyHex = bin2hex(sodium_crypto_sign_publickey($this->keypair));
    $this->secretKey = sodium_crypto_sign_secretkey($this->keypair);

    $this->bindWriter = function (string $secretKey): void {
        // Through the factory, never app(OpLogWriter::class, [...]): once an
        // instance is registered the container hands it back and drops the
        // parameters, so a test rebinding a broken identity would silently get
        // the working one.
        app()->instance(OpLogWriter::class, app(OpLogWriterFactory::class)->make([
            'deviceId' => RESTATEMENT_DEVICE,
            'userId' => (int) $this->reader->id,
            'secretKey' => $secretKey,
            'publicKey' => sodium_crypto_sign_publickey($this->keypair),
        ]));
    };

    ($this->bindWriter)($this->secretKey);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    restatementImport($this->reader, 'asn-a-card-row-at-the-terminals-price.csv');

    $this->storedId = (int) $db->connection()->table('transactions')
        ->where('user_id', $this->reader->id)
        ->value('id');
});

// The denominator. Both halves of this file are read off the booking columns
// the registry declares, so a registry that stopped declaring them would make
// every assertion below vacuously true.
it('declares each booking term as the merge nobody has to guess', function (): void {
    $registry = new MergeRulesRegistry;

    expect($registry->syncedColumns('transactions'))->toContain(...RESTATED_BOOKING);

    foreach (RESTATED_BOOKING as $column) {
        expect($registry->strategyFor('transactions', $column))->toBe(
            MergeStrategy::Lww,
            'A source states a booking term and a reader handed two has no ground to choose, so the later statement stands: '.$column,
        );
    }

    expect($this->storedId)->toBeGreaterThan(0)
        ->and($this->db->connection()->table('transactions')->where('id', $this->storedId)->value('posted_at'))
        ->toBe('2026-02-17', 'the fixture no longer files the first reading on the 17th, so there is nothing for a restatement to move');
});

// The inversion. Without the announcement the op log holds the create of the
// first reading and nothing else, and every assertion below this line reads
// the same on a device that was never told.
it('puts each booking term the restatement adopted on the wire', function (): void {
    $watermark = restatementWatermark($this->db);

    restatementImport($this->reader, 'asn-the-same-card-row-restated-with-the-tip.csv');

    $carried = restatementBookingOps(restatementOpsAfter($this->db, (int) $this->reader->id, $watermark));

    expect(array_keys($carried))->toEqualCanonicalizing(
        RESTATED_BOOKING,
        'A restatement that announces three of the four seats the peer on a day and an occurrence no statement ever stated together.',
    );

    expect($carried['posted_at'])->toBe('2026-02-19')
        ->and($carried['booked_at'])->toBe('2026-02-19 00:00:00')
        ->and($carried['value_date'])->toBe('2026-02-19');
});

// The other device, stood up the way the merge tests here stand one up: the
// row rolled back to what a peer that never heard still holds, then the ops
// replayed onto it.
it('moves the peer onto the day the bank booked it', function (): void {
    $watermark = restatementWatermark($this->db);

    restatementImport($this->reader, 'asn-the-same-card-row-restated-with-the-tip.csv');

    $ops = restatementOpsAfter($this->db, (int) $this->reader->id, $watermark);

    $this->db->connection()->table('transactions')->where('id', $this->storedId)->update([
        'posted_at' => '2026-02-17',
        'booked_at' => '2026-02-17 00:00:00',
        'value_date' => '2026-02-17',
        'occurrence_ordinal' => 0,
    ]);

    (new OpLogReplayer($this->db, [RESTATEMENT_DEVICE => $this->publicKeyHex], new MergeRulesRegistry))
        ->replay($ops, (int) $this->reader->id);

    $peer = $this->db->connection()->table('transactions')->where('id', $this->storedId)->first();

    expect($peer->posted_at)->toBe('2026-02-19', 'the restatement reached no peer, so the two devices file one payment on two days')
        ->and($peer->booked_at)->toBe('2026-02-19 00:00:00')
        ->and($peer->value_date)->toBe('2026-02-19');

    expect($this->db->connection()->table('op_log_quarantine')->where('user_id', $this->reader->id)->count())
        ->toBe(0, 'the announcement reached the peer and was refused, which is the same silence as never sending it');
});

// The two-device conflict the strategy is chosen for: two statements restate
// one row onto two different days, and the later one has to stand WHOLE. A
// booking half from each is a row on a tuple no source ever stated.
it('seats the later statement whole when two devices restate one row', function (): void {
    $userId = (int) $this->reader->id;

    $earlier = [
        restatementSignedSet('device-a', $this->secretKey, $userId, $this->storedId, 'posted_at', '2026-02-19', 2000),
        restatementSignedSet('device-a', $this->secretKey, $userId, $this->storedId, 'booked_at', '2026-02-19 00:00:00', 2001),
        restatementSignedSet('device-a', $this->secretKey, $userId, $this->storedId, 'value_date', '2026-02-19', 2002),
        restatementSignedSet('device-a', $this->secretKey, $userId, $this->storedId, 'occurrence_ordinal', 1, 2003),
    ];

    $later = [
        restatementSignedSet('device-b', $this->secretKey, $userId, $this->storedId, 'posted_at', '2026-02-20', 3000),
        restatementSignedSet('device-b', $this->secretKey, $userId, $this->storedId, 'booked_at', '2026-02-20 00:00:00', 3001),
        restatementSignedSet('device-b', $this->secretKey, $userId, $this->storedId, 'value_date', '2026-02-20', 3002),
        restatementSignedSet('device-b', $this->secretKey, $userId, $this->storedId, 'occurrence_ordinal', 2, 3003),
    ];

    // Arrival order is the transport's business, never the merge's.
    $arriving = [$later[3], $earlier[0], $later[1], $earlier[2], $later[0], $earlier[3], $later[2], $earlier[1]];

    (new OpLogReplayer($this->db, [
        'device-a' => $this->publicKeyHex,
        'device-b' => $this->publicKeyHex,
    ], new MergeRulesRegistry))->replay($arriving, $userId);

    $row = $this->db->connection()->table('transactions')->where('id', $this->storedId)->first();

    expect($row->posted_at)->toBe('2026-02-20')
        ->and($row->booked_at)->toBe('2026-02-20 00:00:00')
        ->and($row->value_date)->toBe('2026-02-20');

    // The counter-case the strategy choice turns on: an ordinal that merged as
    // a grow-only counter would hold 3 here, which is an occurrence neither
    // statement describes and which no re-import of either file matches.
    expect((int) $row->occurrence_ordinal)->toBe(2);
});

// The arm a reader actually hits: a device holding an identity it can no
// longer sign with. The capture contract says a device that cannot capture has
// still imported, so the confirm must carry on -- and it must announce NOTHING
// rather than the part of a booking it managed before the signer refused.
it('announces nothing and still imports when the device cannot sign', function (): void {
    $watermark = restatementWatermark($this->db);

    ($this->bindWriter)('not-an-ed25519-secret-key');

    restatementImport($this->reader, 'asn-the-same-card-row-restated-with-the-tip.csv');

    // The reader's import is not in question. The row moved where the
    // statement says, and the confirm returned rather than throwing out.
    $stored = $this->db->connection()->table('transactions')->where('id', $this->storedId)->first();

    expect($stored->posted_at)->toBe('2026-02-19')
        ->and($stored->booked_at)->toBe('2026-02-19 00:00:00')
        ->and($stored->value_date)->toBe('2026-02-19');

    expect(restatementBookingOps(restatementOpsAfter($this->db, (int) $this->reader->id, $watermark)))
        ->toBe([], 'a booking announced in part seats the peer on a day and an occurrence no statement ever stated together');
});
