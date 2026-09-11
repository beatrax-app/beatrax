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

// One install, two readers, one autoincrement. The primary key sequence is
// shared, so the id a peer minted for the reader's transaction lands on a row
// the HOUSEMATE owns -- measured on the dev database, where user 4 holds ids
// 164..327 and user 5 holds 303..325 inside them.
const OWNER_SCOPE_PEER_DEVICE = 'the-phone-the-reader-pairs-with';

function ownerScopeUser(string $name): User
{
    return User::query()->create([
        'username' => $name.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

/** @return array{account: int, run: int} */
function ownerScopeParents(DatabaseManager $db, int $userId): array
{
    $accountId = (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN betaalrekening',
        'slug' => 'ownerscope-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00OWNR'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $runId = (int) $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/ownerscope.csv',
        'sha256' => hash('sha256', 'ownerscope-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-02 00:00:00',
        'status' => 'confirmed',
        'created_at' => '2026-01-02 00:00:00',
        'updated_at' => '2026-01-02 00:00:00',
    ]);

    return ['account' => $accountId, 'run' => $runId];
}

/** @return array<string, mixed> */
function ownerScopeRow(int $userId, int $accountId, int $runId, string $day, int $amountMinor, string $writtenAt): array
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

/**
 * @param  array<string, mixed>  $row
 */
function ownerScopePeerCreate(int $userId, int|string $peerPk, array $row): void
{
    unset($row['user_id']);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => OWNER_SCOPE_PEER_DEVICE,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey(test()->peerKeypair),
        'publicKey' => sodium_crypto_sign_publickey(test()->peerKeypair),
    ]);

    $writer->writeCreateRow('transactions', $peerPk, $row);
}

function ownerScopeReplay(DatabaseManager $db, int $userId): void
{
    $ops = $db->connection()->table('op_log_entries')
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

    $keys = [OWNER_SCOPE_PEER_DEVICE => bin2hex(sodium_crypto_sign_publickey(test()->peerKeypair))];

    (new OpLogReplayer($db, $keys, new MergeRulesRegistry))->replay($ops, $userId);
}

/** @return list<string> */
function ownerScopeQuarantineReasons(DatabaseManager $db, int $userId): array
{
    /** @var list<string> $reasons */
    $reasons = $db->connection()->table('op_log_quarantine')->where('user_id', $userId)->pluck('reason')->all();

    return $reasons;
}

function ownerScopeCount(DatabaseManager $db, int $userId): int
{
    return $db->connection()->table('transactions')->where('user_id', $userId)->count();
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-09-11 09:15:00');

    $this->peerKeypair = sodium_crypto_sign_keypair();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->reader = ownerScopeUser('reader');
    $this->housemate = ownerScopeUser('housemate');

    $readerParents = ownerScopeParents($db, (int) $this->reader->id);
    $this->readerAccount = $readerParents['account'];
    $this->readerRun = $readerParents['run'];

    $housemateParents = ownerScopeParents($db, (int) $this->housemate->id);
    $this->housemateAccount = $housemateParents['account'];
    $this->housemateRun = $housemateParents['run'];

    // The id the housemate's own import took out of the shared sequence. The
    // reader holds nothing at it, and nothing the reader owns ever will.
    $this->housemateId = (int) $db->connection()->table('transactions')->insertGetId(
        ownerScopeRow((int) $this->housemate->id, $this->housemateAccount, $this->housemateRun, '2026-08-01', -125000, '2026-08-01 18:12:03'),
    );
});

afterEach(fn () => CarbonImmutable::setTestNow());

// Both rows were written in the same second -- two members of one household
// importing on one evening, and created_at holds seconds. That is the whole
// of the agreement: every other column disagrees, and both belong to a
// different reader.
it('stores a create whose id another reader already holds', function (): void {
    $readerId = (int) $this->reader->id;

    ownerScopePeerCreate($readerId, $this->housemateId, ownerScopeRow(
        $readerId, $this->readerAccount, $this->readerRun, '2026-02-02', -399, '2026-08-01 18:12:03',
    ));

    ownerScopeReplay($this->db, $readerId);

    expect(ownerScopeCount($this->db, $readerId))->toBe(
        1,
        'The peer sent a transaction of the reader\'s and it is on no row of the reader\'s, in no quarantine row.',
    );

    expect(ownerScopeQuarantineReasons($this->db, $readerId))->toBe([]);
});

// The write is owner-scoped and the read is not, so the other reader's row is
// what the create was compared against and never what it was written into.
it('leaves the other reader\'s row exactly as it was', function (): void {
    $readerId = (int) $this->reader->id;

    ownerScopePeerCreate($readerId, $this->housemateId, ownerScopeRow(
        $readerId, $this->readerAccount, $this->readerRun, '2026-02-02', -399, '2026-08-01 18:12:03',
    ));

    ownerScopeReplay($this->db, $readerId);

    $held = $this->db->connection()->table('transactions')->where('id', $this->housemateId)->first();

    expect((int) ($held?->user_id ?? 0))->toBe((int) $this->housemate->id)
        ->and($held?->amount_minor)->toBe(-125000)
        ->and($held?->posted_at)->toBe('2026-08-01')
        ->and((int) ($held?->account_id ?? 0))->toBe($this->housemateAccount);
});

// The positive control. The same collision, on the reader's OWN row: the
// stored row is compared, found to be a different one, and the arriving create
// is stored under an id this device minted. Nothing about this changes.
it('still re-homes a create colliding with a row the reader owns', function (): void {
    $readerId = (int) $this->reader->id;

    $ownId = (int) $this->db->connection()->table('transactions')->insertGetId(
        ownerScopeRow($readerId, $this->readerAccount, $this->readerRun, '2026-07-07', -4500, '2026-07-07 11:00:00'),
    );

    ownerScopePeerCreate($readerId, $ownId, ownerScopeRow(
        $readerId, $this->readerAccount, $this->readerRun, '2026-02-02', -399, '2026-02-02 08:30:00',
    ));

    ownerScopeReplay($this->db, $readerId);

    expect(ownerScopeCount($this->db, $readerId))->toBe(2)
        ->and($this->db->connection()->table('transactions')->where('id', $ownId)->value('amount_minor'))->toBe(-4500)
        ->and(ownerScopeQuarantineReasons($this->db, $readerId))->toBe([]);
});

// The second positive control, and the one that shows the fixture itself is
// sound: the same cross-reader collision, differing on when the two rows were
// written, is already answered -- so what the case above measures is the
// agreement, not the two readers.
it('still re-homes a create the other reader\'s row disagrees with', function (): void {
    $readerId = (int) $this->reader->id;

    ownerScopePeerCreate($readerId, $this->housemateId, ownerScopeRow(
        $readerId, $this->readerAccount, $this->readerRun, '2026-02-02', -399, '2026-02-02 08:30:00',
    ));

    ownerScopeReplay($this->db, $readerId);

    expect(ownerScopeCount($this->db, $readerId))->toBe(1)
        ->and(ownerScopeCount($this->db, (int) $this->housemate->id))->toBe(1)
        ->and(ownerScopeQuarantineReasons($this->db, $readerId))->toBe([]);
});
