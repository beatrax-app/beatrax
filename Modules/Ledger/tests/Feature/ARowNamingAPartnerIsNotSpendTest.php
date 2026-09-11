<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Public\Enums\MoneyFlow;
use Modules\Sync\Public\Events\TransactionMutated;

uses(RefreshDatabase::class);

// `type` and `pair_transaction_id` are written by two different writers and
// announced as two separate ops, so they merge on two separate clocks. A device
// that retypes a transfer while a peer's pair link is still in flight leaves a
// row typed `refund` that still names its partner -- a state no single device
// can write, and one MoneyFlow counted as spend at full amount while the
// partner stayed excluded.

function partnerLegUser(): User
{
    return User::query()->create([
        'username' => 'rnp-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function partnerLegTransaction(DatabaseManager $db, int $userId, string $type, ?int $pairId): int
{
    static $row = 700000;
    $row++;
    $suffix = bin2hex(random_bytes(4));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'RNP '.$suffix,
        'slug' => 'rnp-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/rnp-'.$suffix.'.xml',
        'sha256' => hash('sha256', 'rnp-'.$suffix),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'type' => $type,
        'status' => 'cleared',
        'posted_at' => '2026-03-04',
        'booked_at' => '2026-03-04 09:00:00',
        'value_date' => '2026-03-04',
        'amount_minor' => -4000,
        'currency' => 'EUR',
        'settled_amount_minor' => -4000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'rnp vendor',
        'counterparty_name' => 'RNP Vendor',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'source_row_index' => $row,
        'fingerprint' => hash('sha256', 'rnp-tx-'.$suffix),
        'fingerprint_version' => 3,
        'pair_transaction_id' => $pairId,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);
}

function partnerLegSpendMinor(DatabaseManager $db, int $userId): int
{
    [$sql, $bindings] = MoneyFlow::Spend->predicate();

    return (int) $db->connection()->table('transactions')
        ->where('user_id', $userId)
        ->whereRaw($sql, $bindings)
        ->sum('settled_amount_minor');
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = partnerLegUser();
});

// The positive control comes first: without a partner the row is ordinary spend,
// so a predicate that counted nothing at all would not pass here.
it('counts an ordinary expense that names no partner', function (): void {
    partnerLegTransaction($this->db, (int) $this->user->id, 'expense', null);

    expect(partnerLegSpendMinor($this->db, (int) $this->user->id))->toBe(-4000);
});

it('leaves out a row that still names its partner', function (): void {
    $partner = partnerLegTransaction($this->db, (int) $this->user->id, 'transfer_in', null);
    partnerLegTransaction($this->db, (int) $this->user->id, 'refund', $partner);

    expect(partnerLegSpendMinor($this->db, (int) $this->user->id))->toBe(0);
});

it('leaves out an expense that still names its partner', function (): void {
    $partner = partnerLegTransaction($this->db, (int) $this->user->id, 'transfer_in', null);
    partnerLegTransaction($this->db, (int) $this->user->id, 'expense', $partner);

    expect(partnerLegSpendMinor($this->db, (int) $this->user->id))->toBe(0);
});

it('keeps a transfer out of the fold whether or not it names one', function (): void {
    partnerLegTransaction($this->db, (int) $this->user->id, 'transfer_out', null);

    expect(partnerLegSpendMinor($this->db, (int) $this->user->id))->toBe(0);
});

// The other half: the retype announces the cleared link even on a device that
// never saw one, so the peer that DID make the pair is told to drop it.
it('announces the cleared link on a retype that saw no partner', function (): void {
    $captured = [];

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->listen(TransactionMutated::class, function (TransactionMutated $event) use (&$captured): void {
        if ($event->mutationType === 'edit') {
            $captured[] = $event->dirtyFields;
        }
    });

    $txId = partnerLegTransaction($this->db, (int) $this->user->id, 'expense', null);

    $this->actingAs($this->user);
    Livewire::test(TransactionDetail::class, ['transactionId' => $txId])
        ->call('reclassify', 'refund');

    expect($captured)->toHaveCount(1)
        ->and($captured[0])->toBe(['type' => 'refund', 'pair_transaction_id' => null]);
});
