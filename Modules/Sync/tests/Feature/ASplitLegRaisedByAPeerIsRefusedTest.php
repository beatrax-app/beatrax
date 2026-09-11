<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;

uses(RefreshDatabase::class);

// The overfill gate was reached from the create path only. A peer that
// re-split a transaction while apart raises an EXISTING leg rather than
// creating one, and the Set carrying it passed a date gate, an ownership gate
// and nothing else: -8000 ended up holding legs of -8000 and -1000.

/**
 * @param  list<array{0: int|string, 1: string, 2: mixed}>  $ops
 * @return list<OpLogEntry>
 */
function raisedSetOps(string $table, array $ops, int $userId): array
{
    $entries = [];

    foreach ($ops as $index => [$pk, $field, $value]) {
        $common = [
            'table' => $table,
            'pk' => $pk,
            'field' => $field,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'hlcL' => 1788500000000 + $index,
            'hlcC' => 0,
            'deviceId' => 'device-raised',
            'opType' => OpType::Set,
            'userId' => $userId,
        ];

        $stub = new OpLogEntry(...[...$common, 'signature' => '']);
        $entries[] = new OpLogEntry(...[...$common, 'signature' => test()->signer->sign($stub->signingPayload(), test()->sk)]);
    }

    return $entries;
}

function raisedReplay(OpLogEntry ...$entries): void
{
    (new OpLogReplayer(test()->db, test()->deviceKeys))->replay(array_values($entries), (int) test()->user->id);
}

/** @return list<int> */
function raisedSplitInto(int $first, int $second): array
{
    app(SaveTransactionSplit::class)->save(test()->user, (int) test()->tx->id, [
        ['id' => null, 'category_id' => (int) test()->groceries->id, 'settled_amount_minor' => $first, 'note' => null],
        ['id' => null, 'category_id' => (int) test()->household->id, 'settled_amount_minor' => $second, 'note' => null],
    ]);

    /** @var list<int> $ids */
    $ids = DB::table('transaction_splits')
        ->where('transaction_id', test()->tx->id)
        ->orderBy('sort_order')
        ->pluck('id')
        ->map(intval(...))
        ->all();

    return $ids;
}

function raisedLegAmount(int $legId): int
{
    return (int) DB::table('transaction_splits')->where('id', $legId)->value('settled_amount_minor');
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'raised-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $suffix = bin2hex(random_bytes(3));

    $account = Account::query()->create([
        'user_id' => $this->user->id, 'name' => 'ASN', 'slug' => 'raised-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL57ASNB'.random_int(1000000000, 9999999999), 'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $this->user->id, 'source_format' => 'camt053', 'raw_file_path' => '/tmp/raised.xml',
        'sha256' => hash('sha256', 'raised-'.$suffix), 'uploaded_at' => CarbonImmutable::now(), 'status' => 'previewed',
    ]);

    $this->groceries = Category::query()->create(['user_id' => null, 'name' => 'G', 'slug' => 'rs-g-'.$suffix, 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::query()->create(['user_id' => null, 'name' => 'H', 'slug' => 'rs-h-'.$suffix, 'kind' => 'expense', 'display_order' => 2]);

    $this->tx = Transaction::query()->create([
        'user_id' => $this->user->id, 'account_id' => $account->id, 'import_run_id' => $run->id,
        'type' => 'expense', 'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => -8000, 'currency' => 'EUR',
        'settled_amount_minor' => -8000, 'settled_currency' => 'EUR',
        'counterparty_name' => 'AH', 'counterparty_normalized' => 'ah', 'normalization_version' => 1,
        'source_format' => 'camt053', 'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'raised-tx-'.$suffix), 'fingerprint_version' => 1,
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['device-raised' => bin2hex(sodium_crypto_sign_publickey($keypair))];

    /** @var DatabaseManager $db */
    $this->db = app(DatabaseManager::class);
});

it('refuses a set that raises a leg past its transaction', function (): void {
    [$first, $second] = raisedSplitInto(-5000, -3000);

    raisedReplay(...raisedSetOps('transaction_splits', [
        [$first, 'settled_amount_minor', -8000],
    ], (int) $this->user->id));

    expect(raisedLegAmount($first))->toBe(-5000)
        ->and(raisedLegAmount($second))->toBe(-3000)
        ->and(DB::table('op_log_quarantine')->pluck('reason')->all())->toBe(['split_would_overfill_transaction']);
});

// The positive control. A gate that refused everything would pass the test
// above and cost the reader every leg edit a peer ever makes, so the value has
// to be read back off the row rather than inferred from an empty quarantine.
it('applies a set that leaves the legs still fitting the transaction', function (): void {
    [$first, $second] = raisedSplitInto(-5000, -3000);

    raisedReplay(...raisedSetOps('transaction_splits', [
        [$first, 'settled_amount_minor', -4000],
    ], (int) $this->user->id));

    expect(raisedLegAmount($first))->toBe(-4000)
        ->and(raisedLegAmount($second))->toBe(-3000)
        ->and(DB::table('op_log_quarantine')->count())->toBe(0);
});

// The same value again is the idempotent re-apply: counting the leg's own row
// as one of the legs already there would make every replay refuse itself.
it('applies a set that writes a leg the amount it already holds', function (): void {
    [$first, $second] = raisedSplitInto(-5000, -3000);

    raisedReplay(...raisedSetOps('transaction_splits', [
        [$first, 'settled_amount_minor', -5000],
    ], (int) $this->user->id));

    expect(raisedLegAmount($first))->toBe(-5000)
        ->and(raisedLegAmount($second))->toBe(-3000)
        ->and(DB::table('op_log_quarantine')->count())->toBe(0);
});

// A rebalance is one decision over the whole leg set and announces every leg,
// so the raised one routinely arrives before the lowered one. Judged against
// the legs merely STORED, the first op of a set that adds up exactly is an
// overfill, and refusing it leaves the reader a split that no longer sums.
it('applies a whole rebalance whose raised leg arrives before its lowered one', function (): void {
    [$first, $second] = raisedSplitInto(-2000, -6000);

    raisedReplay(...raisedSetOps('transaction_splits', [
        [$first, 'settled_amount_minor', -6000],
        [$second, 'settled_amount_minor', -2000],
    ], (int) $this->user->id));

    expect(raisedLegAmount($first))->toBe(-6000)
        ->and(raisedLegAmount($second))->toBe(-2000)
        ->and(DB::table('op_log_quarantine')->count())->toBe(0);
});

it('leaves a set on another table to the gates that table has', function (): void {
    raisedSplitInto(-5000, -3000);

    raisedReplay(...raisedSetOps('transactions', [
        [(int) $this->tx->id, 'description', 'a peer renamed it'],
    ], (int) $this->user->id));

    expect(DB::table('transactions')->where('id', $this->tx->id)->value('description'))->toBe('a peer renamed it')
        ->and(DB::table('op_log_quarantine')->count())->toBe(0);
});

// Keyed on the column as well as the table: read as an amount, a category id
// is a positive number on a negative charge, and the leg would be refused for
// a sum nobody asked it to take.
it('leaves a set on another column of the leg table alone', function (): void {
    [$first] = raisedSplitInto(-5000, -3000);

    raisedReplay(...raisedSetOps('transaction_splits', [
        [$first, 'category_id', (int) $this->household->id],
    ], (int) $this->user->id));

    expect((int) DB::table('transaction_splits')->where('id', $first)->value('category_id'))->toBe((int) $this->household->id)
        ->and(raisedLegAmount($first))->toBe(-5000)
        ->and(DB::table('op_log_quarantine')->count())->toBe(0);
});
