<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// One device holds both household members and one id sequence, so the two
// readers' rows interleave: on the live database user 1 holds transactions 1
// to 174 and user 2 holds 146 to 167. A leg that predates split_uuid took its
// id from that same sequence, so the id a peer names is a number, not a row.

// The split gate turned that number into money: it read the transaction and
// the leg by id alone and compared one household's arriving leg against the
// other's charge, refusing a legitimate change or admitting an overfill.

/**
 * @return array{0: int, 1: int}
 */
function splitOwnerLedger(DatabaseManager $db, int $userId, string $suffix, int $settledMinor): array
{
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ASN split owner',
        'slug' => 'split-owner-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/split-owner-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'split-owner-run-'.$suffix),
        'uploaded_at' => '2026-06-01 00:00:00',
        'status' => 'previewed',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => 'SplitOwner '.$suffix,
        'slug' => 'split-owner-cat-'.$suffix,
        'kind' => 'expense',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    $transactionId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'split-owner-'.$suffix),
        'posted_at' => '2026-06-01',
        'booked_at' => '2026-06-01 10:00:00',
        'value_date' => '2026-06-01',
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'albert heijn',
        'counterparty_name' => 'ALBERT HEIJN',
        'normalization_version' => 3,
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint_version' => 3,
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);

    return [$transactionId, $categoryId];
}

// insertGetId rather than a chosen id: the legs of both readers come off the
// one sequence the table still declares, which is what makes a peer's number
// ambiguous here and is the shape the fixture has to keep.
function splitOwnerLeg(DatabaseManager $db, int $userId, int $transactionId, int $categoryId, int $minor, int $order): int
{
    return $db->connection()->table('transaction_splits')->insertGetId([
        'user_id' => $userId,
        'transaction_id' => $transactionId,
        'category_id' => $categoryId,
        'settled_amount_minor' => $minor,
        'settled_currency' => 'EUR',
        'note' => null,
        'sort_order' => $order,
        'split_uuid' => 'legacy:'.hash('sha256', $transactionId.':'.$order.':'.$minor),
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

function splitOwnerRaise(int|string $pk, int $minor, int $userId, int $hlcL): OpLogEntry
{
    $common = [
        'table' => 'transaction_splits',
        'pk' => $pk,
        'field' => 'settled_amount_minor',
        'value' => (string) $minor,
        'hlcL' => $hlcL,
        'hlcC' => 0,
        'deviceId' => 'device-split-owner',
        'opType' => OpType::Set,
        'userId' => $userId,
    ];

    $stub = new OpLogEntry(...[...$common, 'signature' => '']);

    return new OpLogEntry(...[...$common, 'signature' => test()->signer->sign($stub->signingPayload(), test()->sk)]);
}

/**
 * @return array<int, int>
 */
function splitOwnerLegAmounts(DatabaseManager $db): array
{
    /** @var array<int, int> $amounts */
    $amounts = $db->connection()->table('transaction_splits')
        ->orderBy('id')
        ->pluck('settled_amount_minor', 'id')
        ->map(static fn (mixed $minor): int => (int) $minor)
        ->all();

    return $amounts;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-14 10:00:00');

    $this->reader = User::query()->create(['username' => 'split-owner-reader', 'password' => 'fixture-password-12chars', 'period_start_day' => 1]);
    $this->housemate = User::query()->create(['username' => 'split-owner-housemate', 'password' => 'fixture-password-12chars', 'period_start_day' => 1]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    // The housemate's charge is split first, so their legs take the low
    // numbers a peer holding only the reader's rows also counts from.
    [$theirTransaction, $theirCategory] = splitOwnerLedger($db, (int) $this->housemate->id, 'housemate', -8000);
    $this->theirFirstLeg = splitOwnerLeg($db, (int) $this->housemate->id, $theirTransaction, $theirCategory, -5000, 0);
    splitOwnerLeg($db, (int) $this->housemate->id, $theirTransaction, $theirCategory, -3000, 1);

    [$ourTransaction, $ourCategory] = splitOwnerLedger($db, (int) $this->reader->id, 'reader', -9000);
    $this->ourFirstLeg = splitOwnerLeg($db, (int) $this->reader->id, $ourTransaction, $ourCategory, -6000, 0);
    splitOwnerLeg($db, (int) $this->reader->id, $ourTransaction, $ourCategory, -3000, 1);

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['device-split-owner' => bin2hex(sodium_crypto_sign_publickey($keypair))];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('builds the one sequence both readers take their leg ids from', function (): void {
    expect((int) $this->db->connection()->table('transaction_splits')->where('id', $this->theirFirstLeg)->value('user_id'))
        ->toBe((int) $this->housemate->id, 'the id the peer names has to land on the other reader here, or nothing below is about a cross-owner read')
        ->and($this->theirFirstLeg)->toBeLessThan($this->ourFirstLeg, 'both readers must draw from one id sequence, as they do on a shared device');
});

it('does not judge a leg against the other readers charge', function (): void {
    $before = splitOwnerLegAmounts($this->db);

    // -6000 beside the housemate's remaining -3000 overfills THEIR -80,00
    // charge. Under the reader's own -90,00 it is nobody's overfill.
    (new OpLogReplayer($this->db, $this->deviceKeys))->replay(
        [splitOwnerRaise($this->theirFirstLeg, -6000, (int) $this->reader->id, 1788400001000)],
        (int) $this->reader->id,
    );

    expect($this->db->connection()->table('op_log_quarantine')->where('reason', 'split_would_overfill_transaction')->count())
        ->toBe(0, 'the gate summed the reader\'s arriving leg against the housemate\'s charge and refused the reader over it')
        ->and(splitOwnerLegAmounts($this->db))->toBe($before, 'a replay scoped to one reader must leave every stored leg alone');
});

// The same gate on the same field, with the id naming the reader's own leg:
// raised past what is left of their charge it still has to refuse, or the
// case above passes because the gate stopped answering rather than because it
// started reading the right row.
it('still refuses a leg that overfills the readers own charge', function (): void {
    (new OpLogReplayer($this->db, $this->deviceKeys))->replay(
        [splitOwnerRaise($this->ourFirstLeg, -7000, (int) $this->reader->id, 1788400002000)],
        (int) $this->reader->id,
    );

    expect($this->db->connection()->table('op_log_quarantine')->where('reason', 'split_would_overfill_transaction')->count())
        ->toBe(1)
        ->and((int) $this->db->connection()->table('transaction_splits')->where('id', $this->ourFirstLeg)->value('settled_amount_minor'))
        ->toBe(-6000, 'the refused leg must keep the amount it had');
});

it('admits a leg the readers own charge still has room for', function (): void {
    (new OpLogReplayer($this->db, $this->deviceKeys))->replay(
        [splitOwnerRaise($this->ourFirstLeg, -5000, (int) $this->reader->id, 1788400003000)],
        (int) $this->reader->id,
    );

    expect($this->db->connection()->table('op_log_quarantine')->count())
        ->toBe(0)
        ->and((int) $this->db->connection()->table('transaction_splits')->where('id', $this->ourFirstLeg)->value('settled_amount_minor'))
        ->toBe(-5000);
});
