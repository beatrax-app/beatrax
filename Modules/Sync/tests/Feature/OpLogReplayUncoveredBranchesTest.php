<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

/*
 * The branches of OpLogReplayer::replay() that the rest of the Sync suite never
 * reaches. Measured, not guessed: replay() sat at 78.7% line coverage, and the
 * gaps were concentrated in the row-precedence and create paths.
 *
 * The most consequential gap was a pair of blocks that look identical — the
 * transfer pair-link cascade is written out twice, once where a row also has
 * field edits and once where only a tombstone arrives. Only the second copy had
 * a test. A tombstone that arrives alongside an edit for the same row takes the
 * first copy, so the orphaned-partner reclassification on that path was
 * unverified even though its twin was covered four times over.
 *
 * These tests describe the CONTRACT of each branch rather than its current
 * shape, so they hold across a restructuring of the method.
 */

const OLR_DEVICE = 'device-olr';

/**
 * @return array{userId: int, pkHex: string, sk: string}
 */
function olrDevice(DatabaseManager $db, string $username): array
{
    $userId = $db->connection()->table('users')->insertGetId([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keypair = sodium_crypto_sign_keypair();

    return [
        'userId' => $userId,
        'pkHex' => bin2hex(sodium_crypto_sign_publickey($keypair)),
        'sk' => sodium_crypto_sign_secretkey($keypair),
    ];
}

// Signs a real Ed25519 op so it clears the replayer's verification gate — the
// signature is over the entry's own payload, so it has to be built twice: once
// to derive the payload, then again carrying the signature.
function olrSignedEntry(
    string $sk,
    string $table,
    int|string $pk,
    string $field,
    ?string $value,
    int $hlcL,
    OpType $opType,
    int $userId,
): OpLogEntry {
    $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
        table: $table,
        pk: $pk,
        field: $field,
        value: $value,
        hlcL: $hlcL,
        hlcC: 0,
        deviceId: OLR_DEVICE,
        opType: $opType,
        signature: $signature,
        userId: $userId,
    );

    return $make((new DeviceKeySigner)->sign($make('')->signingPayload(), $sk));
}

/**
 * Seeds an account and import run, then a paired transfer_out / transfer_in.
 *
 * @return array{0: int, 1: int}
 */
function olrPairedTransfers(DatabaseManager $db, int $userId, string $suffix): array
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'OLR account',
        'slug' => 'olr-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00OLR'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/olr-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'olr-'.$suffix),
        'uploaded_at' => '2026-06-15 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-15 00:00:00',
        'updated_at' => '2026-06-15 00:00:00',
    ]);

    $ids = [];
    foreach ([['transfer_out', -5000, 1], ['transfer_in', 5000, 2]] as [$type, $amount, $index]) {
        $ids[] = $db->connection()->table('transactions')->insertGetId([
            'user_id' => $userId,
            'account_id' => $accountId,
            'import_run_id' => $runId,
            'fingerprint' => hash('sha256', 'olr-'.$suffix.'-'.$index),
            'posted_at' => '2026-06-15',
            'booked_at' => '2026-06-15 10:00:00',
            'value_date' => '2026-06-15',
            'amount_minor' => $amount,
            'currency' => 'EUR',
            'settled_amount_minor' => $amount,
            'settled_currency' => 'EUR',
            'counterparty_normalized' => 'own account',
            'counterparty_name' => 'OWN ACCOUNT',
            'normalization_version' => 3,
            'description' => 'olr fixture '.$index,
            'type' => $type,
            'source_format' => 'asn-csv',
            'source_row_index' => $index,
            'fingerprint_version' => 3,
            'created_at' => '2026-06-15 00:00:00',
            'updated_at' => '2026-06-15 00:00:00',
        ]);
    }

    $db->connection()->table('transactions')->where('id', $ids[0])->update(['pair_transaction_id' => $ids[1]]);
    $db->connection()->table('transactions')->where('id', $ids[1])->update(['pair_transaction_id' => $ids[0]]);

    return [$ids[0], $ids[1]];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-15 10:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// ---------------------------------------------------------------------
// Row precedence: a tombstone competing with field edits on the same row.
// ---------------------------------------------------------------------

it('cascades a transfer pair reclassification when the deleted row also had field edits', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-cascade-with-edit');
    [$outId, $inId] = olrPairedTransfers($db, $device['userId'], 'CWE');

    // An edit AND a tombstone for the same row. The edit puts the row in the
    // field-resolution pass, so the delete is decided there rather than in the
    // tombstone-only pass — the copy of the cascade that had no test.
    $edit = olrSignedEntry($device['sk'], 'transactions', $outId, 'description',
        json_encode('edited before delete'), 1000, OpType::Set, $device['userId']);
    $tomb = olrSignedEntry($device['sk'], 'transactions', $outId, '__tombstone__',
        null, 2000, OpType::DeleteTombstone, $device['userId']);

    (new OpLogReplayer($db, [OLR_DEVICE => $device['pkHex']]))
        ->replay([$edit, $tomb], $device['userId']);

    expect($db->connection()->table('transactions')->where('id', $outId)->exists())->toBeFalse();

    $partner = $db->connection()->table('transactions')->where('id', $inId)->first();
    expect($partner)->not->toBeNull()
        ->and($partner->pair_transaction_id)->toBeNull()
        ->and($partner->type)->toBe('income');

    // The compensating op must be durable, or a later rebuild would revert the
    // reclassification and resurrect a transfer with no counterpart.
    $compensating = $db->connection()->table('op_log_entries')
        ->where('user_id', $device['userId'])
        ->where('table_name', 'transactions')
        ->where('pk', (string) $inId)
        ->where('field', 'type')
        ->where('op_type', 'set')
        ->first();

    expect($compensating)->not->toBeNull()
        ->and($compensating->value)->toBe(json_encode('income'));
});

it('leaves the row alone when its newest field edit outranks the tombstone', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-edit-wins');
    [$outId, $inId] = olrPairedTransfers($db, $device['userId'], 'EW');

    $tomb = olrSignedEntry($device['sk'], 'transactions', $outId, '__tombstone__',
        null, 1000, OpType::DeleteTombstone, $device['userId']);
    $edit = olrSignedEntry($device['sk'], 'transactions', $outId, 'description',
        json_encode('edit wins'), 2000, OpType::Set, $device['userId']);

    (new OpLogReplayer($db, [OLR_DEVICE => $device['pkHex']]))
        ->replay([$tomb, $edit], $device['userId']);

    $row = $db->connection()->table('transactions')->where('id', $outId)->first();
    expect($row)->not->toBeNull()
        ->and($row->description)->toBe('edit wins');

    // No cascade fired, so the partner keeps both its pair link and its type.
    $partner = $db->connection()->table('transactions')->where('id', $inId)->first();
    expect($partner->pair_transaction_id)->toBe($outId)
        ->and($partner->type)->toBe('transfer_in');
});

it('deletes an unpaired transaction without recording a cascade', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-unpaired');
    [$outId, $inId] = olrPairedTransfers($db, $device['userId'], 'UP');

    // Break the pair so the tombstoned row has no partner to orphan.
    $db->connection()->table('transactions')->where('id', $outId)->update(['pair_transaction_id' => null]);

    $tomb = olrSignedEntry($device['sk'], 'transactions', $outId, '__tombstone__',
        null, 2000, OpType::DeleteTombstone, $device['userId']);

    (new OpLogReplayer($db, [OLR_DEVICE => $device['pkHex']]))
        ->replay([$tomb], $device['userId']);

    expect($db->connection()->table('transactions')->where('id', $outId)->exists())->toBeFalse();

    // The other side is untouched: no cascade, so its type is not rewritten.
    $partner = $db->connection()->table('transactions')->where('id', $inId)->first();
    expect($partner->type)->toBe('transfer_in');
});

it('records no reclassification when both sides of a transfer are deleted together', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-both-sides');
    [$outId, $inId] = olrPairedTransfers($db, $device['userId'], 'BS');

    $tombOut = olrSignedEntry($device['sk'], 'transactions', $outId, '__tombstone__',
        null, 2000, OpType::DeleteTombstone, $device['userId']);
    $tombIn = olrSignedEntry($device['sk'], 'transactions', $inId, '__tombstone__',
        null, 2001, OpType::DeleteTombstone, $device['userId']);

    (new OpLogReplayer($db, [OLR_DEVICE => $device['pkHex']]))
        ->replay([$tombOut, $tombIn], $device['userId']);

    expect($db->connection()->table('transactions')->whereIn('id', [$outId, $inId])->count())->toBe(0);

    // Each delete queued a cascade for a partner that the other delete had
    // already removed, so neither may produce a compensating op.
    $compensating = $db->connection()->table('op_log_entries')
        ->where('user_id', $device['userId'])
        ->where('field', 'type')
        ->where('op_type', 'set')
        ->count();

    expect($compensating)->toBe(0);
});

// ---------------------------------------------------------------------
// CreateRow precedence and rejection.
// ---------------------------------------------------------------------

/**
 * @return list<OpLogEntry>
 */
function olrAliasCreate(string $sk, int $userId, int $pk, int $hlcL): array
{
    return [
        olrSignedEntry($sk, 'merchant_aliases', $pk, 'pattern', json_encode('ALBERT HEIJN%'), $hlcL, OpType::CreateRow, $userId),
        olrSignedEntry($sk, 'merchant_aliases', $pk, 'generalized_pattern', json_encode('albert heijn'), $hlcL, OpType::CreateRow, $userId),
        olrSignedEntry($sk, 'merchant_aliases', $pk, 'friendly_name', json_encode('Albert Heijn'), $hlcL, OpType::CreateRow, $userId),
    ];
}

it('creates a row when the create outranks a competing tombstone', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-create-wins');

    $tomb = olrSignedEntry($device['sk'], 'merchant_aliases', 77, '__tombstone__',
        null, 1000, OpType::DeleteTombstone, $device['userId']);

    (new OpLogReplayer($db, [OLR_DEVICE => $device['pkHex']]))->replay(
        [...olrAliasCreate($device['sk'], $device['userId'], 77, 2000), $tomb],
        $device['userId'],
    );

    $row = $db->connection()->table('merchant_aliases')->where('user_id', $device['userId'])->first();
    expect($row)->not->toBeNull()
        ->and($row->pattern)->toBe('ALBERT HEIJN%')
        ->and($row->friendly_name)->toBe('Albert Heijn');
});

it('suppresses a create that a newer tombstone outranks', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-create-loses');

    $tomb = olrSignedEntry($device['sk'], 'merchant_aliases', 78, '__tombstone__',
        null, 3000, OpType::DeleteTombstone, $device['userId']);

    (new OpLogReplayer($db, [OLR_DEVICE => $device['pkHex']]))->replay(
        [...olrAliasCreate($device['sk'], $device['userId'], 78, 1000), $tomb],
        $device['userId'],
    );

    // Delete-wins applies to a row that never existed locally too, otherwise a
    // replayed create would resurrect a row deleted on another device.
    expect($db->connection()->table('merchant_aliases')->where('user_id', $device['userId'])->count())->toBe(0);
});

it('quarantines a create that is missing a required column instead of inserting a partial row', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-incomplete-create');

    // `pattern` is required for merchant_aliases and is absent here.
    $partial = olrSignedEntry($device['sk'], 'merchant_aliases', 79, 'friendly_name',
        json_encode('No Pattern'), 1000, OpType::CreateRow, $device['userId']);

    (new OpLogReplayer($db, [OLR_DEVICE => $device['pkHex']]))->replay([$partial], $device['userId']);

    expect($db->connection()->table('merchant_aliases')->where('user_id', $device['userId'])->count())->toBe(0);

    $quarantined = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $device['userId'])
        ->where('reason', 'incomplete_create_row')
        ->count();

    expect($quarantined)->toBe(1);
});

it('quarantines a create whose merge strategy rejects the value, leaving no row behind', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-create-strategy-error');

    // merged_from is an OR-Set field; a bare string is not {added, removed}, so
    // the strategy throws while resolving the create.
    $entries = [
        ...olrAliasCreate($device['sk'], $device['userId'], 80, 1000),
        olrSignedEntry($device['sk'], 'merchant_aliases', 80, 'merged_from',
            json_encode('not-an-or-set'), 1000, OpType::CreateRow, $device['userId']),
    ];

    (new OpLogReplayer($db, [OLR_DEVICE => $device['pkHex']]))->replay($entries, $device['userId']);

    // The whole row is abandoned, not inserted with the bad column dropped.
    expect($db->connection()->table('merchant_aliases')->where('user_id', $device['userId'])->count())->toBe(0);

    $quarantined = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $device['userId'])
        ->where('reason', 'strategy_error')
        ->count();

    expect($quarantined)->toBe(1);
});

it('indexes a transaction that arrives as a create rather than an edit', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-create-transaction');

    // Reuse the fixture only for its account and import run, then delete the
    // rows so the create below is the only transaction in play.
    [$outId, $inId] = olrPairedTransfers($db, $device['userId'], 'CT');
    $seed = $db->connection()->table('transactions')->where('id', $outId)->first();
    $db->connection()->table('transactions')->whereIn('id', [$outId, $inId])->delete();

    $columns = [
        'type' => 'expense',
        'account_id' => $seed->account_id,
        'import_run_id' => $seed->import_run_id,
        'amount_minor' => -1234,
        'currency' => 'EUR',
        'settled_amount_minor' => -1234,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'created by sync',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'source_row_index' => 9,
        'fingerprint' => hash('sha256', 'olr-created-transaction'),
        'fingerprint_version' => 3,
        'posted_at' => '2026-06-15',
        'booked_at' => '2026-06-15 11:00:00',
        'value_date' => '2026-06-15',
    ];

    $entries = [];
    foreach ($columns as $field => $value) {
        $entries[] = olrSignedEntry($device['sk'], 'transactions', 4242, $field,
            json_encode($value), 1000, OpType::CreateRow, $device['userId']);
    }

    $writer = olrRecordingSearchWriter();

    (new OpLogReplayer($db, [OLR_DEVICE => $device['pkHex']], null, null, $writer))
        ->replay($entries, $device['userId']);

    $row = $db->connection()->table('transactions')->where('user_id', $device['userId'])->first();
    expect($row)->not->toBeNull()
        ->and($row->counterparty_normalized)->toBe('created by sync')
        ->and($row->amount_minor)->toBe(-1234);

    // A created transaction is as searchable as an edited one — the index hook
    // has to fire on the create path too, not just on field resolution.
    expect($writer->upserted)->toContain(4242);
});

it('deletes an edited but unpaired transaction without looking for a partner', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-edit-unpaired');
    [$outId, $inId] = olrPairedTransfers($db, $device['userId'], 'EU');

    $db->connection()->table('transactions')->where('id', $outId)->update(['pair_transaction_id' => null]);

    $edit = olrSignedEntry($device['sk'], 'transactions', $outId, 'description',
        json_encode('edited then deleted'), 1000, OpType::Set, $device['userId']);
    $tomb = olrSignedEntry($device['sk'], 'transactions', $outId, '__tombstone__',
        null, 2000, OpType::DeleteTombstone, $device['userId']);

    (new OpLogReplayer($db, [OLR_DEVICE => $device['pkHex']]))
        ->replay([$edit, $tomb], $device['userId']);

    expect($db->connection()->table('transactions')->where('id', $outId)->exists())->toBeFalse();

    $partner = $db->connection()->table('transactions')->where('id', $inId)->first();
    expect($partner->type)->toBe('transfer_in');
});

// ---------------------------------------------------------------------
// Verification gate.
// ---------------------------------------------------------------------

it('quarantines an op from a device it holds no public key for', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-unknown-device');
    [$outId] = olrPairedTransfers($db, $device['userId'], 'UD');

    $entry = olrSignedEntry($device['sk'], 'transactions', $outId, 'description',
        json_encode('from a stranger'), 1000, OpType::Set, $device['userId']);

    // The replayer is given an EMPTY key map, so the signature cannot be
    // checked at all — an unverifiable op must never be applied.
    (new OpLogReplayer($db, []))->replay([$entry], $device['userId']);

    $row = $db->connection()->table('transactions')->where('id', $outId)->first();
    expect($row->description)->toBe('olr fixture 1');

    $quarantined = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $device['userId'])
        ->where('reason', 'missing_device_key')
        ->count();

    expect($quarantined)->toBe(1);

    // Nothing unverifiable reaches the authoritative log either.
    expect($db->connection()->table('op_log_entries')->where('user_id', $device['userId'])->count())->toBe(0);
});

// ---------------------------------------------------------------------
// Search-index freshness, which runs after the merge transaction commits.
// ---------------------------------------------------------------------

/**
 * @return SearchIndexWriterContract&object{upserted: list<int>, deleted: list<int>}
 */
function olrRecordingSearchWriter(): SearchIndexWriterContract
{
    return new class implements SearchIndexWriterContract
    {
        /** @var list<int> */
        public array $upserted = [];

        /** @var list<int> */
        public array $deleted = [];

        public function upsertForTransaction(int $transactionId, int $actorUserId): void
        {
            $this->upserted[] = $transactionId;
        }

        public function deleteForTransaction(int $transactionId, int $actorUserId): void
        {
            $this->deleted[] = $transactionId;
        }
    };
}

it('refreshes the search index for edited rows and drops it for deleted ones', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-search-fresh');
    [$outId, $inId] = olrPairedTransfers($db, $device['userId'], 'SF');

    $writer = olrRecordingSearchWriter();

    $edit = olrSignedEntry($device['sk'], 'transactions', $inId, 'description',
        json_encode('reindex me'), 1000, OpType::Set, $device['userId']);
    $tomb = olrSignedEntry($device['sk'], 'transactions', $outId, '__tombstone__',
        null, 2000, OpType::DeleteTombstone, $device['userId']);

    (new OpLogReplayer($db, [OLR_DEVICE => $device['pkHex']], null, null, $writer))
        ->replay([$edit, $tomb], $device['userId']);

    expect($writer->deleted)->toContain($outId)
        ->and($writer->upserted)->toContain($inId);
});

it('quarantines a search-index failure instead of failing the merge', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $device = olrDevice($db, 'olr-search-throws');
    [$outId, $inId] = olrPairedTransfers($db, $device['userId'], 'ST');

    $writer = new class implements SearchIndexWriterContract
    {
        public function upsertForTransaction(int $transactionId, int $actorUserId): void
        {
            throw new RuntimeException('FTS5 upsert refused');
        }

        public function deleteForTransaction(int $transactionId, int $actorUserId): void
        {
            throw new RuntimeException('FTS5 delete refused');
        }
    };

    $edit = olrSignedEntry($device['sk'], 'transactions', $inId, 'description',
        json_encode('index will fail'), 1000, OpType::Set, $device['userId']);
    $tomb = olrSignedEntry($device['sk'], 'transactions', $outId, '__tombstone__',
        null, 2000, OpType::DeleteTombstone, $device['userId']);

    (new OpLogReplayer($db, [OLR_DEVICE => $device['pkHex']], null, null, $writer))
        ->replay([$edit, $tomb], $device['userId']);

    // The merge itself stands: a search hiccup must never undo a committed merge.
    expect($db->connection()->table('transactions')->where('id', $outId)->exists())->toBeFalse();
    $row = $db->connection()->table('transactions')->where('id', $inId)->first();
    expect($row->description)->toBe('index will fail');

    // Both failures are recorded so the index can be repaired later.
    $reasons = $db->connection()->table('op_log_quarantine')
        ->where('user_id', $device['userId'])
        ->pluck('reason')
        ->all();

    expect($reasons)->not->toBeEmpty();
});
