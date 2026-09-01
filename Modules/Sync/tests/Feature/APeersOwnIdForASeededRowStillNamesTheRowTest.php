<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Public\Services\HistoryReprojector;

uses(RefreshDatabase::class);

// Two devices that signed up independently each seed their own tax deduction
// categories, so the same six corpus keys carry different autoincrement ids —
// 109-114 on the Mac, 13-18 on the phone. The peer's create then collides with
// the local twin on (user_id, name), which the applier treats as the idempotent
// re-apply and passes over in silence.

// It is silent about the wrong thing. The row IS present; the peer's ID for it
// is what was thrown away, so all nineteen tax tags naming 109 failed their
// foreign key and were quarantined. A paired iPhone showed an empty Tax screen
// with nothing anywhere saying a row had been dropped.

const PEER_CATEGORY_ID = 109;

function peerIdUser(): User
{
    return User::query()->create([
        'username' => 'peer-id-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function peerIdTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Peer id bank',
        'slug' => 'peer-id-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00PEER'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/peer-id.csv',
        'sha256' => hash('sha256', 'peer-id-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => '2026-05-04',
        'booked_at' => '2026-05-04 12:00:00',
        'value_date' => '2026-05-04',
        'amount_minor' => -8500,
        'currency' => 'EUR',
        'settled_amount_minor' => -8500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'zorgverzekeraar',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'peer-id-tx-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 1,
        'status' => 'cleared',
        'created_at' => '2026-05-04 12:00:00',
        'updated_at' => '2026-05-04 12:00:00',
    ]);
}

// The row this device seeded for itself, under an id of its own.
function peerIdLocalCategory(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'Zorgkosten',
        'corpus_key' => 'nl_zorgkosten',
        'country_code' => 'NL',
        'status' => 'active',
        'sort_order' => 1,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

/** @return list<OpLogEntry> */
function peerIdOps(DatabaseManager $db, int $userId): array
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

/** @return array{0: int, 1: int, 2: int} local category id, transaction id, replay outcome count */
function peerIdReplay(DatabaseManager $db, int $userId): array
{
    $localCategoryId = peerIdLocalCategory($db, $userId);
    $transactionId = peerIdTransaction($db, $userId);

    $keypair = sodium_crypto_sign_keypair();
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'peer-that-seeded-its-own',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    // The peer's own id for the same corpus row, and a tag that names it.
    $writer->writeCreateRow('tax_deduction_categories', PEER_CATEGORY_ID, [
        'user_id' => $userId,
        'name' => 'Zorgkosten',
        'corpus_key' => 'nl_zorgkosten',
        'country_code' => 'NL',
        'status' => 'active',
        'sort_order' => 1,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $writer->writeCreateRow('tax_transaction_tags', 5001, [
        'user_id' => $userId,
        'transaction_id' => $transactionId,
        'deduction_category_id' => PEER_CATEGORY_ID,
        'created_at' => '2026-05-04 12:00:00',
        'updated_at' => '2026-05-04 12:00:00',
    ]);

    $replayer = new OpLogReplayer(
        $db,
        ['peer-that-seeded-its-own' => bin2hex(sodium_crypto_sign_publickey($keypair))],
        new MergeRulesRegistry,
    );
    $replayer->replay(peerIdOps($db, $userId), $userId);

    return [$localCategoryId, $transactionId, 0];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    $this->user = peerIdUser();
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('keeps the tag a peer sent, resolved onto this device id for the same category', function (): void {
    $userId = (int) $this->user->id;
    [$localCategoryId] = peerIdReplay($this->db, $userId);

    $tag = $this->db->connection()->table('tax_transaction_tags')
        ->where('user_id', $userId)
        ->first();

    expect($tag)->not->toBeNull('the tag the peer sent was dropped')
        ->and((int) $tag->deduction_category_id)->toBe(
            $localCategoryId,
            'the tag kept the peer id '.PEER_CATEGORY_ID.' instead of this device id for the same category',
        );
});

it('quarantines nothing, where it used to quarantine every tag', function (): void {
    $userId = (int) $this->user->id;
    peerIdReplay($this->db, $userId);

    $held = $this->db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->pluck('reason')
        ->all();

    expect($held)->toBe([], 'rows were refused: '.implode(', ', array_map(strval(...), $held)));
});

// The peer's id is not written as a second category — the row was always here.
it('does not duplicate the category it already had', function (): void {
    $userId = (int) $this->user->id;
    peerIdReplay($this->db, $userId);

    expect($this->db->connection()->table('tax_deduction_categories')->where('user_id', $userId)->count())->toBe(1)
        ->and($this->db->connection()->table('tax_deduction_categories')->where('id', PEER_CATEGORY_ID)->exists())->toBeFalse();
});

it('remembers the pair, so a later op naming the peer id still resolves', function (): void {
    $userId = (int) $this->user->id;
    [$localCategoryId] = peerIdReplay($this->db, $userId);

    $alias = $this->db->connection()->table('op_log_row_aliases')
        ->where('user_id', $userId)
        ->where('table_name', 'tax_deduction_categories')
        ->where('remote_id', (string) PEER_CATEGORY_ID)
        ->value('local_id');

    expect($alias)->toBe((string) $localCategoryId);
});

// The half that repairs a device already in this state. The recovery replayed
// only the rows a quarantined entry names, so the tag failed on the same
// missing id every pass; the parent's own ops were in the log the whole time.
it('recovers a tag already quarantined, by replaying the parent it names', function (): void {
    $userId = (int) $this->user->id;
    $localCategoryId = peerIdLocalCategory($this->db, $userId);
    $transactionId = peerIdTransaction($this->db, $userId);

    $keypair = sodium_crypto_sign_keypair();
    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'peer-that-seeded-its-own',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);

    $writer->writeCreateRow('tax_deduction_categories', PEER_CATEGORY_ID, [
        'user_id' => $userId,
        'name' => 'Zorgkosten',
        'corpus_key' => 'nl_zorgkosten',
        'country_code' => 'NL',
        'status' => 'active',
        'sort_order' => 1,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
    $writer->writeCreateRow('tax_transaction_tags', 5001, [
        'user_id' => $userId,
        'transaction_id' => $transactionId,
        'deduction_category_id' => PEER_CATEGORY_ID,
        'created_at' => '2026-05-04 12:00:00',
        'updated_at' => '2026-05-04 12:00:00',
    ]);

    $replayer = new OpLogReplayer(
        $this->db,
        ['peer-that-seeded-its-own' => bin2hex(sodium_crypto_sign_publickey($keypair))],
        new MergeRulesRegistry,
    );

    // Only the tag, so it is refused for the parent it names — the state the
    // phone was found in, with the parent's ops present but never re-applied.
    $tagOnly = array_values(array_filter(
        peerIdOps($this->db, $userId),
        static fn (OpLogEntry $entry): bool => $entry->table === 'tax_transaction_tags',
    ));
    $replayer->replay($tagOnly, $userId);

    expect($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())
        ->toBe(1, 'the tag should be held for its missing parent before recovery runs')
        ->and($this->db->connection()->table('tax_transaction_tags')->where('user_id', $userId)->exists())
        ->toBeFalse();

    app(HistoryReprojector::class)->replayQuarantined($userId, app(Session::class), null, null);

    $tag = $this->db->connection()->table('tax_transaction_tags')->where('user_id', $userId)->first();

    expect($tag)->not->toBeNull('the recovery pass left the tag where it found it')
        ->and((int) $tag->deduction_category_id)->toBe($localCategoryId)
        // Nothing ever cleared a spent refusal, so the backlog line reported
        // work already done and every later pass replayed rows that had landed.
        ->and($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())
        ->toBe(0, 'the refusal outlived the row landing');
});

// A reason nothing retries still has to stop being reported once the row it
// names is here. 47 transactions sat quarantined on a phone that held all 47.
it('clears a spent refusal even for a reason no pass retries', function (): void {
    $userId = (int) $this->user->id;
    $transactionId = peerIdTransaction($this->db, $userId);

    $this->db->connection()->table('op_log_quarantine')->insert([
        'user_id' => $userId,
        'op_entry_id' => 1,
        'table_name' => 'transactions',
        'pk' => (string) $transactionId,
        'device_id' => 'peer-that-seeded-its-own',
        'reason' => 'incomplete_create_row',
        'hlc_l' => 1,
        'hlc_c' => 0,
        'raw_value' => null,
        'created_at' => '2026-05-04 12:00:00',
    ]);

    expect($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(1);

    app(HistoryReprojector::class)->replayQuarantined($userId, app(Session::class), null, null);

    expect($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())
        ->toBe(0, 'the refusal outlived the row it named');
});
