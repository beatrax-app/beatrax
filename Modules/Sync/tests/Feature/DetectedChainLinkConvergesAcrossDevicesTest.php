<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Public\Actions\DismissChainLinkHint;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Enums\ChainHintType;
use Modules\Receipts\Public\Events\ChainHintDetected;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// chain_links has no UNIQUE of its own, so nothing in the schema ever said what
// makes a link the same link — only the insert helper's (user, from, to, kind)
// guard did, per device. Both devices resolve the same receipt hint off the
// same transaction, so both wrote a link, and the hint the reader dismissed on
// one device came straight back from the other.

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

function dclDerivedLinkId(int $userId, int $transactionId): int
{
    return DerivedRowId::for('chain_links', [
        'user_id' => $userId,
        'from_transaction_id' => $transactionId,
        'to_transaction_id' => null,
        'kind' => 'funded_by_card_hint',
    ]);
}

it('gives two devices the same chain link id for the same hint', function (): void {
    $userId = (int) $this->user->id;

    dclDetectHint($userId, $this->transactionId);
    $onPhone = (int) $this->db->connection()->table('chain_links')->where('user_id', $userId)->value('id');
    $this->db->connection()->table('chain_links')->where('user_id', $userId)->delete();

    dclDetectHint($userId, $this->transactionId);
    $onDesktop = (int) $this->db->connection()->table('chain_links')->where('user_id', $userId)->value('id');

    expect($onPhone)->toBe($onDesktop)
        ->and($onPhone)->toBe(dclDerivedLinkId($userId, $this->transactionId))
        ->and($onPhone)->toBeGreaterThan(0);

    // A hint of another kind off the same transaction is another link, and the
    // NULL endpoint is what separates a hint from a resolved pair — neither may
    // fold into the same number.
    expect(DerivedRowId::for('chain_links', [
        'user_id' => $userId,
        'from_transaction_id' => $this->transactionId,
        'to_transaction_id' => null,
        'kind' => 'refund_of_hint',
    ]))->not->toBe($onPhone);

    expect(DerivedRowId::for('chain_links', [
        'user_id' => $userId,
        'from_transaction_id' => $this->transactionId,
        'to_transaction_id' => $this->transactionId,
        'kind' => 'funded_by_card_hint',
    ]))->not->toBe($onPhone);
});

it('keeps a hint dismissed on one device dismissed on the other', function (): void {
    $userId = (int) $this->user->id;
    $linkId = dclDerivedLinkId($userId, $this->transactionId);

    [$phoneKey, $phoneOps] = dclDetectOnDevice($this->db, $userId, $this->transactionId, 'device-phone');
    [$desktopKey, $desktopOps] = dclDetectOnDevice($this->db, $userId, $this->transactionId, 'device-desktop');

    expect($phoneOps)->not->toBeEmpty('the phone resolved a hint and captured nothing')
        ->and($desktopOps)->not->toBeEmpty('the desktop resolved a hint and captured nothing');

    $deviceKeys = ['device-phone' => $phoneKey, 'device-desktop' => $desktopKey];
    $replay = fn (array $ops) => (new OpLogReplayer($this->db, $deviceKeys, new MergeRulesRegistry))->replay($ops, $userId);

    $replay([...$phoneOps, ...$desktopOps]);

    expect($this->db->connection()->table('chain_links')->where('user_id', $userId)->count())->toBe(1)
        ->and($this->db->connection()->table('chain_links')->where('id', $linkId)->exists())->toBeTrue();

    // The reader waves the hint away on the desktop. Under the old
    // autoincrement the tombstone named the desktop's own id, which the phone's
    // create had already displaced — the hint stayed in the phone's queue.
    dclBindWriter($userId, 'device-desktop');
    $watermark = dclWatermark($this->db);

    app(DismissChainLinkHint::class)($linkId, $this->user);

    $dismissOps = dclOpsAfter($this->db, $userId, $watermark);
    expect($dismissOps)->not->toBeEmpty('dismissing a hint captured nothing');

    // The exact thing the local-id scheme broke: the tombstone has to name the
    // row the OTHER device created, and under an autoincrement it named the
    // dismissing device's own number instead.
    $tombstonePks = array_values(array_unique(array_map(
        static fn (OpLogEntry $entry): int|string => $entry->pk,
        $dismissOps,
    )));
    $phoneCreatePks = array_values(array_unique(array_map(
        static fn (OpLogEntry $entry): int|string => $entry->pk,
        $phoneOps,
    )));

    expect($tombstonePks)->toBe([$linkId])
        ->and($phoneCreatePks)->toBe([$linkId]);

    // From an empty table again: the same creates that produced a row above now
    // produce none, because the tombstone is replayed beside them.
    $this->db->connection()->table('chain_links')->where('user_id', $userId)->delete();
    $replay([...$phoneOps, ...$desktopOps, ...$dismissOps]);

    expect($this->db->connection()->table('chain_links')->where('user_id', $userId)->count())->toBe(0)
        ->and($this->db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);
});
