<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Transfers\Internal\Support\EarliestLegFirst;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

// The sweep decides which orphan asks for a partner first, and pairOne() writes
// the answer into pair_transaction_id, which syncs. ASN books every row at
// 12:00:00, so the tie is the ordinary case — and settling it on the id settled
// it on a number each device counts for itself, so the two devices swept in
// different orders and wrote two different pairs into one LWW column.

function orphanSweepUser(): User
{
    return User::query()->create([
        'username' => 'orphan-sweep-order',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function orphanSweepAccount(User $user, string $slug, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'sweep '.$slug,
        'slug' => $slug,
        'kind' => 'bank',
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function orphanSweepTx(User $user, Account $account, ImportRun $run, array $overrides): Transaction
{
    static $row = 0;
    $row++;

    return Transaction::query()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-04-15',
        'booked_at' => '2026-04-15 12:00:00',
        'value_date' => '2026-04-15',
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'counterparty_iban' => null,
        'counterparty_name' => 'Sweep partner',
        'counterparty_normalized' => 'sweep-partner',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => str_pad('sweep'.$row, 64, 's', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

it('orders the orphan sweep on something no two rows can tie on', function (): void {
    $user = orphanSweepUser();
    $bank = orphanSweepAccount($user, 'orphan-sweep-bank', 'NL57ASNB0123456789');
    $savings = orphanSweepAccount($user, 'orphan-sweep-savings', 'NL91ASNB0417164300');

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/orphan-sweep.csv',
        'sha256' => hash('sha256', 'orphan-sweep'),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    // Four orphans, two of them sharing a booked_at to the second — the shape
    // the demo ledger already has (477/453 and 478/454 all at 12:00:00).
    orphanSweepTx($user, $savings, $run, ['counterparty_iban' => 'NL57ASNB0123456789']);
    orphanSweepTx($user, $savings, $run, ['amount_minor' => -7500, 'settled_amount_minor' => -7500, 'counterparty_iban' => 'NL57ASNB0123456789']);
    orphanSweepTx($user, $bank, $run, ['type' => 'transfer_in', 'amount_minor' => 5000, 'settled_amount_minor' => 5000, 'counterparty_iban' => '']);
    orphanSweepTx($user, $bank, $run, ['type' => 'transfer_in', 'amount_minor' => 7500, 'settled_amount_minor' => 7500, 'counterparty_iban' => '']);

    $sweepReads = [];
    DB::listen(static function (QueryExecuted $query) use (&$sweepReads): void {
        if (str_contains($query->sql, 'from "transactions"')
            && str_contains($query->sql, 'pair_transaction_id" is null')
            && str_contains($query->sql, 'order by')) {
            $sweepReads[] = $query->sql;
        }
    });

    /** @var PairsTransferLegs $pairer */
    $pairer = app(PairsTransferLegs::class);
    $pairer->pairOrphansForUser($user);

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    expect($sweepReads)->not->toBeEmpty('the orphan sweep never ran its candidate read');

    // Every ordered read the sweep runs — its own candidate list and the
    // counter-leg search each orphan then fires — has to end on a clause built
    // only from columns both devices hold the same values in.
    $agreed = [EarliestLegFirst::ACROSS_ACCOUNTS, EarliestLegFirst::ON_ONE_ACCOUNT];

    $countedPerDevice = [];
    foreach ($sweepReads as $sql) {
        $orderBy = trim(substr($sql, (int) strrpos($sql, 'order by') + strlen('order by')));
        $orderBy = trim((string) preg_replace('/\s+limit\s+\d+$/', '', $orderBy));
        if (! in_array($orderBy, $agreed, true)) {
            $countedPerDevice[] = $orderBy;
        }
    }

    expect($countedPerDevice)->toBe(
        [],
        "These orderings name a column the peer counts for itself:\n  ".implode("\n  ", $countedPerDevice)
    );
});

it('gives a contested leg to the claimant both devices would pick, not the lower id', function (): void {
    $user = orphanSweepUser();
    $bank = orphanSweepAccount($user, 'sweep-tie-bank', 'NL57ASNB0123456789');
    $savings = orphanSweepAccount($user, 'sweep-tie-savings', 'NL91ASNB0417164300');
    $buffer = orphanSweepAccount($user, 'sweep-tie-buffer', 'NL20ASNB0123456790');

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/sweep-tie.csv',
        'sha256' => hash('sha256', 'sweep-tie'),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    // One incoming leg and two outgoing legs that agree on every column off the
    // statement — account apart. Only one can claim it, and the claim is
    // persisted. The LOWER id is created first, so an ordering ending on the id
    // hands it to the savings leg; the agreed one hands it to the buffer leg,
    // whose IBAN sorts first. The two answers are different rows.
    $lowerId = orphanSweepTx($user, $savings, $run, ['counterparty_iban' => 'NL57ASNB0123456789']);
    $firstIban = orphanSweepTx($user, $buffer, $run, ['counterparty_iban' => 'NL57ASNB0123456789']);
    $incoming = orphanSweepTx($user, $bank, $run, [
        'type' => 'transfer_in',
        'amount_minor' => 5000,
        'settled_amount_minor' => 5000,
        'counterparty_iban' => '',
    ]);

    expect($firstIban->id)->toBeGreaterThan($lowerId->id, 'the fixture no longer inverts id order against IBAN order');

    /** @var PairsTransferLegs $pairer */
    $pairer = app(PairsTransferLegs::class);
    $pairer->pairOrphansForUser($user);

    /** @var Transaction $claimed */
    $claimed = Transaction::query()->findOrFail($firstIban->id);
    /** @var Transaction $passedOver */
    $passedOver = Transaction::query()->findOrFail($lowerId->id);

    expect($claimed->pair_transaction_id)->toBe($incoming->id)
        ->and($passedOver->pair_transaction_id)->toBeNull();
});
