<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DerivedRowId;
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

// SaveTransactionSplit requires a transaction's legs to add up to it exactly.
// The applier had no such rule, and once every leg carried an identity of its
// own the two devices' sets no longer collided by accident: a desktop split of
// 50/30 and a phone split of 40/40 both landed, and one 80,00 charge showed
// four legs adding to 160,00 with an empty quarantine.

/**
 * @param  array<string, mixed>  $fields
 * @return list<OpLogEntry>
 */
function overfillCreate(int|string $pk, array $fields, int $userId, int $hlcL): array
{
    $entries = [];
    $tick = 0;

    foreach ($fields as $field => $value) {
        $common = [
            'table' => 'transaction_splits',
            'pk' => $pk,
            'field' => $field,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'hlcL' => $hlcL,
            'hlcC' => $tick++,
            'deviceId' => 'device-overfill',
            'opType' => OpType::CreateRow,
            'userId' => $userId,
        ];

        $stub = new OpLogEntry(...[...$common, 'signature' => '']);
        $entries[] = new OpLogEntry(...[...$common, 'signature' => test()->signer->sign($stub->signingPayload(), test()->sk)]);
    }

    return $entries;
}

/**
 * @return list<OpLogEntry>
 */
function overfillPeerSplit(int $userId, int $transactionId, int $categoryA, int $categoryB, int $each = -4000): array
{
    $entries = [];

    foreach ([['peer-leg-a', $categoryA], ['peer-leg-b', $categoryB]] as $index => [$uuid, $categoryId]) {
        $entries = [...$entries, ...overfillCreate(
            DerivedRowId::for('transaction_splits', ['split_uuid' => $uuid]),
            [
                'user_id' => $userId,
                'transaction_id' => $transactionId,
                'category_id' => $categoryId,
                'settled_amount_minor' => $each,
                'settled_currency' => 'EUR',
                'note' => null,
                'sort_order' => $index,
                'split_uuid' => $uuid,
            ],
            $userId,
            1788400000000 + $index,
        )];
    }

    return $entries;
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'overfill-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $suffix = bin2hex(random_bytes(3));

    $account = Account::query()->create([
        'user_id' => $this->user->id, 'name' => 'ASN', 'slug' => 'overfill-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL57ASNB'.random_int(1000000000, 9999999999), 'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $this->user->id, 'source_format' => 'camt053', 'raw_file_path' => '/tmp/overfill.xml',
        'sha256' => hash('sha256', 'overfill-'.$suffix), 'uploaded_at' => CarbonImmutable::now(), 'status' => 'previewed',
    ]);

    $this->groceries = Category::query()->create(['user_id' => null, 'name' => 'G', 'slug' => 'of-g-'.$suffix, 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::query()->create(['user_id' => null, 'name' => 'H', 'slug' => 'of-h-'.$suffix, 'kind' => 'expense', 'display_order' => 2]);

    $this->tx = Transaction::query()->create([
        'user_id' => $this->user->id, 'account_id' => $account->id, 'import_run_id' => $run->id,
        'type' => 'expense', 'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => -8000, 'currency' => 'EUR',
        'settled_amount_minor' => -8000, 'settled_currency' => 'EUR',
        'counterparty_name' => 'AH', 'counterparty_normalized' => 'ah', 'normalization_version' => 1,
        'source_format' => 'camt053', 'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'overfill-tx-'.$suffix), 'fingerprint_version' => 1,
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $this->sk = sodium_crypto_sign_secretkey($keypair);
    $this->signer = new DeviceKeySigner;
    $this->deviceKeys = ['device-overfill' => bin2hex(sodium_crypto_sign_publickey($keypair))];

    /** @var DatabaseManager $db */
    $this->db = app(DatabaseManager::class);
});

it('refuses a peers split for a transaction this device has already split', function (): void {
    app(SaveTransactionSplit::class)->save($this->user, (int) $this->tx->id, [
        ['id' => null, 'category_id' => (int) $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
        ['id' => null, 'category_id' => (int) $this->household->id, 'settled_amount_minor' => -3000, 'note' => null],
    ]);

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay(
        overfillPeerSplit((int) $this->user->id, (int) $this->tx->id, (int) $this->groceries->id, (int) $this->household->id),
        (int) $this->user->id,
    );

    $legs = DB::table('transaction_splits')->where('transaction_id', $this->tx->id);

    expect($legs->count())->toBe(2)
        ->and((int) $legs->sum('settled_amount_minor'))->toBe(-8000)
        ->and(DB::table('op_log_quarantine')->pluck('reason')->all())->toContain('split_would_overfill_transaction');
});

it('applies a peers split when this device has not split the transaction', function (): void {
    (new OpLogReplayer($this->db, $this->deviceKeys))->replay(
        overfillPeerSplit((int) $this->user->id, (int) $this->tx->id, (int) $this->groceries->id, (int) $this->household->id),
        (int) $this->user->id,
    );

    $legs = DB::table('transaction_splits')->where('transaction_id', $this->tx->id);

    expect($legs->count())->toBe(2)
        ->and((int) $legs->sum('settled_amount_minor'))->toBe(-8000)
        ->and(DB::table('op_log_quarantine')->count())->toBe(0);
});

// The idempotent re-apply: a leg that is already here must not be read as a
// second leg arriving, or every replay of a split would refuse itself.
it('replays a leg it has already applied without refusing it', function (): void {
    $entries = overfillPeerSplit((int) $this->user->id, (int) $this->tx->id, (int) $this->groceries->id, (int) $this->household->id);

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay($entries, (int) $this->user->id);
    (new OpLogReplayer($this->db, $this->deviceKeys))->replay($entries, (int) $this->user->id);

    $legs = DB::table('transaction_splits')->where('transaction_id', $this->tx->id);

    expect($legs->count())->toBe(2)
        ->and((int) $legs->sum('settled_amount_minor'))->toBe(-8000)
        ->and(DB::table('op_log_quarantine')->count())->toBe(0);
});

// A leg denominated in another currency is not this gate's question: adding it
// to the transaction's own minor units would be two currencies under one sign,
// which is what AMoneyAggregateNamesTheCurrencyItCounts exists to stop.
it('leaves a leg in another currency to a rule that understands currencies', function (): void {
    app(SaveTransactionSplit::class)->save($this->user, (int) $this->tx->id, [
        ['id' => null, 'category_id' => (int) $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
        ['id' => null, 'category_id' => (int) $this->household->id, 'settled_amount_minor' => -3000, 'note' => null],
    ]);

    $entries = overfillCreate(
        DerivedRowId::for('transaction_splits', ['split_uuid' => 'peer-usd-leg']),
        [
            'user_id' => (int) $this->user->id,
            'transaction_id' => (int) $this->tx->id,
            'category_id' => (int) $this->groceries->id,
            'settled_amount_minor' => -9000,
            'settled_currency' => 'USD',
            'note' => null,
            'sort_order' => 0,
            'split_uuid' => 'peer-usd-leg',
        ],
        (int) $this->user->id,
        1788400009000,
    );

    (new OpLogReplayer($this->db, $this->deviceKeys))->replay($entries, (int) $this->user->id);

    expect(DB::table('op_log_quarantine')->pluck('reason')->all())
        ->not->toContain('split_would_overfill_transaction');
});
