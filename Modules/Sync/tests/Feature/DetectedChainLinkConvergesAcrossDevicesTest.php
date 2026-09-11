<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\DismissChainLinkHint;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DeviceMintedRowId;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Enums\ChainHintType;
use Modules\Receipts\Public\Events\ChainHintDetected;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

// What makes a link the same link is (user, from, to, kind), and it is now said
// where the database can enforce it. It used to be said only by the id, folded
// from that tuple — and two of its columns are transaction ids each device
// counts for itself, so the number meant a different pair of charges on the
// peer. The id is minted; `chain_links_pair_uq` is what two devices meet on.

// A hint carries no `to`, and SQLite counts NULLs as distinct, so the index
// does not bind one. Two devices therefore keep two hint rows off one charge —
// pinned below so it is visible. They never met before either: the derived id
// agreed only when both devices had numbered the charge alike, which is the
// same accident that put a peer's link on the wrong pair of charges.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');

    $this->user = User::create([
        'username' => 'chain-converge-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->transactionId = dclSeedTransaction($db, (int) $this->user->id);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function dclSeedTransaction(DatabaseManager $db, int $userId): int
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN chain converge',
        'slug' => 'chain-converge-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/chain-converge.csv',
        'sha256' => hash('sha256', 'chain-converge-'.bin2hex(random_bytes(8))),
        'uploaded_at' => '2026-05-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => 'expense',
        'posted_at' => '2026-05-04',
        'booked_at' => '2026-05-04 12:00:00',
        'value_date' => '2026-05-04',
        'amount_minor' => -4999,
        'currency' => 'EUR',
        'settled_amount_minor' => -4999,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Coolblue',
        'counterparty_normalized' => 'coolblue',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'chain-converge-'.bin2hex(random_bytes(8))),
        'fingerprint_version' => 3,
        'created_at' => '2026-05-04 12:00:00',
        'updated_at' => '2026-05-04 12:00:00',
    ]);
}

function dclBindWriter(int $userId, string $deviceId): string
{
    static $keypairs = [];
    $keypairs[$deviceId] ??= sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypairs[$deviceId]),
        'publicKey' => sodium_crypto_sign_publickey($keypairs[$deviceId]),
    ]);
    app()->instance(OpLogWriter::class, $writer);

    return bin2hex(sodium_crypto_sign_publickey($keypairs[$deviceId]));
}

function dclWatermark(DatabaseManager $db): int
{
    $max = $db->connection()->table('op_log_entries')->max('id');

    return is_numeric($max) ? (int) $max : 0;
}

/** @return list<OpLogEntry> */
function dclOpsAfter(DatabaseManager $db, int $userId, int $afterId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'chain_links')
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

function dclDetectHint(int $userId, int $transactionId): void
{
    app(Dispatcher::class)->dispatch(new ChainHintDetected(
        sourceTransactionId: $transactionId,
        hintType: ChainHintType::FundedByCard,
        hintPayload: new FundedByCardPayload('1234'),
        evidence: 'Paid with: Visa ending 1234',
        userId: $userId,
    ));
}

/**
 * @return array{0: string, 1: list<OpLogEntry>}
 */
function dclDetectOnDevice(DatabaseManager $db, int $userId, int $transactionId, string $deviceId): array
{
    $key = dclBindWriter($userId, $deviceId);
    $watermark = dclWatermark($db);

    dclDetectHint($userId, $transactionId);

    $ops = dclOpsAfter($db, $userId, $watermark);
    $db->connection()->table('chain_links')->where('user_id', $userId)->delete();

    return [$key, $ops];
}

// A resolved link, written and captured the way the resolvers write one: an id
// this device minted, and the four columns the index names.
/**
 * @return array{0: string, 1: list<OpLogEntry>, 2: int}
 */
function dclResolvedLinkOnDevice(DatabaseManager $db, int $userId, int $from, int $to, string $deviceId): array
{
    $key = dclBindWriter($userId, $deviceId);
    $watermark = dclWatermark($db);

    $id = DeviceMintedRowId::mint();
    $columns = [
        'user_id' => $userId,
        'from_transaction_id' => $from,
        'to_transaction_id' => $to,
        'kind' => 'paypal_funding',
        'state' => 'candidate',
        'confidence' => '0.900',
        'resolver' => 'auto',
        'evidence' => json_encode(['source_evidence' => []]),
        'created_at' => '2026-05-17 12:00:00',
        'updated_at' => '2026-05-17 12:00:00',
    ];

    $db->connection()->table('chain_links')->insert(['id' => $id] + $columns);

    app(Dispatcher::class)->dispatch(new EntityMutated(
        table: 'chain_links',
        pk: $id,
        userId: $userId,
        mutationType: 'create',
        dirtyFields: $columns,
    ));

    $ops = dclOpsAfter($db, $userId, $watermark);
    $db->connection()->table('chain_links')->where('id', $id)->delete();

    return [$key, $ops, $id];
}

it('gives one row to the resolved link two devices each detected under their own id', function (): void {
    $userId = (int) $this->user->id;
    $settlementId = dclSeedTransaction($this->db, $userId);

    [$phoneKey, $phoneOps, $onPhone] = dclResolvedLinkOnDevice($this->db, $userId, $settlementId, $this->transactionId, 'device-phone');
    [$desktopKey, $desktopOps, $onDesktop] = dclResolvedLinkOnDevice($this->db, $userId, $settlementId, $this->transactionId, 'device-desktop');

    // The point of the change: the two devices did NOT agree on a number, and
    // do not have to. Nothing they exchange carries the other's id.
    expect($onPhone)->not->toBe($onDesktop)
        ->and($phoneOps)->not->toBeEmpty()
        ->and($desktopOps)->not->toBeEmpty();

    $deviceKeys = ['device-phone' => $phoneKey, 'device-desktop' => $desktopKey];
    (new OpLogReplayer($this->db, $deviceKeys, new MergeRulesRegistry))->replay([...$phoneOps, ...$desktopOps], $userId);

    expect($this->db->connection()->table('chain_links')->where('user_id', $userId)->count())->toBe(1)
        ->and($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);

    // Both ids reach the one row: the second create was refused by the index,
    // and the pair it names was remembered so a later op from that device lands.
    $aliases = $this->db->connection()->table('op_log_row_aliases')
        ->where('user_id', $userId)
        ->where('table_name', 'chain_links')
        ->count();

    expect($aliases)->toBe(1);
});

// The gap this fix leaves, pinned rather than left to be discovered: a hint has
// no `to`, so the index cannot say two of them are one link, and the reader
// sees the same hint on both devices. Closing it needs a key the applier can
// match on without matching a NULL, which PeerRowAliases deliberately refuses.
it('still keeps a hint each device raised for itself, because a NULL endpoint is no key', function (): void {
    $userId = (int) $this->user->id;

    [$phoneKey, $phoneOps] = dclDetectOnDevice($this->db, $userId, $this->transactionId, 'device-phone');
    [$desktopKey, $desktopOps] = dclDetectOnDevice($this->db, $userId, $this->transactionId, 'device-desktop');

    $deviceKeys = ['device-phone' => $phoneKey, 'device-desktop' => $desktopKey];
    (new OpLogReplayer($this->db, $deviceKeys, new MergeRulesRegistry))->replay([...$phoneOps, ...$desktopOps], $userId);

    $rows = $this->db->connection()->table('chain_links')->where('user_id', $userId)->orderBy('id')->pluck('id')->all();

    expect($rows)->toHaveCount(2)
        ->and($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);

    // And the cost of it, shown rather than described: the reader waves one
    // away on the desktop, the tombstone travels, and the row the phone raised
    // for the same charge is a different row and stays in its queue.
    dclBindWriter($userId, 'device-desktop');
    $watermark = dclWatermark($this->db);

    app(DismissChainLinkHint::class)((int) $rows[0], $this->user);

    $dismissOps = dclOpsAfter($this->db, $userId, $watermark);
    expect($dismissOps)->not->toBeEmpty('dismissing a hint captured nothing');

    $this->db->connection()->table('chain_links')->where('user_id', $userId)->delete();
    (new OpLogReplayer($this->db, $deviceKeys, new MergeRulesRegistry))->replay([...$phoneOps, ...$desktopOps, ...$dismissOps], $userId);

    expect($this->db->connection()->table('chain_links')->where('user_id', $userId)->count())->toBe(1)
        ->and($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);
});

it('lands a decision from the device that did not create the surviving row', function (): void {
    $userId = (int) $this->user->id;
    $settlementId = dclSeedTransaction($this->db, $userId);

    [$phoneKey, $phoneOps, $onPhone] = dclResolvedLinkOnDevice($this->db, $userId, $settlementId, $this->transactionId, 'device-phone');
    [$desktopKey, $desktopOps, $onDesktop] = dclResolvedLinkOnDevice($this->db, $userId, $settlementId, $this->transactionId, 'device-desktop');

    $deviceKeys = ['device-phone' => $phoneKey, 'device-desktop' => $desktopKey];
    $replay = fn (array $ops) => (new OpLogReplayer($this->db, $deviceKeys, new MergeRulesRegistry))->replay($ops, $userId);

    $replay([...$phoneOps, ...$desktopOps]);

    $surviving = (int) $this->db->connection()->table('chain_links')->where('user_id', $userId)->value('id');

    // The reader confirms it on the desktop. The exact thing a per-device id
    // broke: the SET names the id the deciding device holds, and the other
    // device has to be able to say which of its own rows that is.
    dclBindWriter($userId, 'device-desktop');
    $watermark = dclWatermark($this->db);

    app(ConfirmChainLink::class)($surviving, $this->user);

    $decisionOps = dclOpsAfter($this->db, $userId, $watermark);
    expect($decisionOps)->not->toBeEmpty('confirming a link captured nothing');

    // From an empty table again, replayed in the order a peer would receive
    // them: the decision reaches the row the creates produced.
    $this->db->connection()->table('chain_links')->where('user_id', $userId)->delete();
    $this->db->connection()->table('op_log_row_aliases')->where('user_id', $userId)->delete();
    $replay([...$phoneOps, ...$desktopOps, ...$decisionOps]);

    $rows = $this->db->connection()->table('chain_links')->where('user_id', $userId)->get(['state']);

    expect($onPhone)->not->toBe($onDesktop)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]->state)->toBe('confirmed')
        ->and($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);
});
