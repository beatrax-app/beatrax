<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Models\TransactionSplit;
use Modules\Ledger\Public\Actions\SaveTransactionSplit;
use Modules\Sync\Public\Events\TransactionSplitMutated;

uses(RefreshDatabase::class);

// A split rebalance is one decision over the whole leg set, but the legs are
// separate rows and merge as separate ops. Announcing only the legs whose
// numbers moved let two rebalances interleave into a set neither device chose:
// per-field last-writer-wins picks a winner per leg, and the survivors need not
// sum to the parent. The applier never re-asks -- SplitOverfillGate is reached
// from the create path only, and it refuses an overfill rather than a shortfall.

function rebalanceUser(): User
{
    return User::query()->create([
        'username' => 'rebalance-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
}

function rebalanceParent(User $user): Transaction
{
    $suffix = bin2hex(random_bytes(4));

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Rebalance '.$suffix,
        'slug' => 'rebalance-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper($suffix),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/rebalance-'.$suffix.'.xml',
        'sha256' => hash('sha256', 'rebalance-'.$suffix),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $run->id,
        'type' => 'expense',
        'posted_at' => '2026-03-04',
        'booked_at' => '2026-03-04 09:00:00',
        'value_date' => '2026-03-04',
        'amount_minor' => -10000,
        'currency' => 'EUR',
        'settled_amount_minor' => -10000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Rebalance Vendor',
        'counterparty_normalized' => 'rebalance vendor',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'rebalance-tx-'.$suffix),
        'fingerprint_version' => 3,
    ]);
}

/** @return array<int, int> leg id => announced amount */
function rebalanceAnnouncedAmounts(): array
{
    $amounts = [];

    /** @var list<TransactionSplitMutated> $captured */
    $captured = test()->rebalanceCaptured;

    foreach ($captured as $event) {
        if (array_key_exists('settled_amount_minor', $event->dirtyFields)) {
            /** @var int $amount */
            $amount = $event->dirtyFields['settled_amount_minor'];
            $amounts[$event->splitId] = $amount;
        }
    }

    return $amounts;
}

function rebalanceForget(): void
{
    test()->rebalanceCaptured = [];
}

/** @param list<int> $legIds */
function rebalanceRestoreBase(array $legIds): void
{
    foreach (array_combine($legIds, [-2000, -3000, -5000]) as $legId => $amountMinor) {
        TransactionSplit::query()->where('id', $legId)->update(['settled_amount_minor' => $amountMinor]);
    }
}

beforeEach(function (): void {
    $this->rebalanceCaptured = [];

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->listen(TransactionSplitMutated::class, function (TransactionSplitMutated $event): void {
        if ($event->mutationType === 'edit') {
            $this->rebalanceCaptured[] = $event;
        }
    });

    $this->user = rebalanceUser();
    $this->parent = rebalanceParent($this->user);

    $this->groceries = Category::query()->create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'reb-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense']);
    $this->household = Category::query()->create(['user_id' => null, 'name' => 'Household', 'slug' => 'reb-household-'.bin2hex(random_bytes(3)), 'kind' => 'expense']);

    /** @var SaveTransactionSplit $saver */
    $saver = $this->app->make(SaveTransactionSplit::class);
    $this->saver = $saver;

    $this->saver->save($this->user, (int) $this->parent->id, [
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -2000, 'note' => null],
        ['id' => null, 'category_id' => $this->household->id, 'settled_amount_minor' => -3000, 'note' => null],
        ['id' => null, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
    ]);

    /** @var list<int> $legIds */
    $legIds = TransactionSplit::query()
        ->where('transaction_id', $this->parent->id)
        ->orderBy('sort_order')
        ->pluck('id')
        ->map(intval(...))
        ->all();

    $this->legIds = $legIds;
});

it('announces every leg of the split when one leg moves', function (): void {
    [$first, $second, $third] = $this->legIds;

    rebalanceForget();

    $this->saver->save($this->user, (int) $this->parent->id, [
        ['id' => $first, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -1000, 'note' => null],
        ['id' => $second, 'category_id' => $this->household->id, 'settled_amount_minor' => -4000, 'note' => null],
        ['id' => $third, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
    ]);

    expect(rebalanceAnnouncedAmounts())
        ->toBe([$first => -1000, $second => -4000, $third => -5000]);
});

// The positive control. A save that moves no number is not a rebalance, and
// republishing three amounts for a note edit would put an op on the wire for
// every leg every time anybody typed.
it('announces no amount at all when nothing moved', function (): void {
    [$first, $second, $third] = $this->legIds;

    rebalanceForget();

    $this->saver->save($this->user, (int) $this->parent->id, [
        ['id' => $first, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -2000, 'note' => 'a note'],
        ['id' => $second, 'category_id' => $this->household->id, 'settled_amount_minor' => -3000, 'note' => null],
        ['id' => $third, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
    ]);

    expect(rebalanceAnnouncedAmounts())->toBe([]);
});

// The payoff: two rebalances made apart, merged per field with the later one
// winning per leg. The survivors have to sum to the parent, whichever landed
// last, because each save announced its whole set.
it('converges on a set that still sums to the parent', function (): void {
    [$first, $second, $third] = $this->legIds;

    rebalanceForget();
    $this->saver->save($this->user, (int) $this->parent->id, [
        ['id' => $first, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -1000, 'note' => null],
        ['id' => $second, 'category_id' => $this->household->id, 'settled_amount_minor' => -4000, 'note' => null],
        ['id' => $third, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -5000, 'note' => null],
    ]);
    $deviceA = rebalanceAnnouncedAmounts();

    // B never saw A. Put the rows back the way B still holds them, WITHOUT
    // announcing anything, so B's save computes its diff against the same base
    // A did rather than against A's result.
    rebalanceRestoreBase($this->legIds);

    rebalanceForget();
    $this->saver->save($this->user, (int) $this->parent->id, [
        ['id' => $first, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -2000, 'note' => null],
        ['id' => $second, 'category_id' => $this->household->id, 'settled_amount_minor' => -2000, 'note' => null],
        ['id' => $third, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'note' => null],
    ]);
    $deviceB = rebalanceAnnouncedAmounts();

    // Per-field last-writer-wins across the two op sets: B is later, so every
    // leg B named takes B's number and any leg only A named keeps A's.
    // array_replace, not a spread: these keys are leg ids, and a spread
    // renumbers integer keys instead of merging on them.
    $merged = array_replace($deviceA, $deviceB);

    expect(array_sum($merged))->toBe((int) $this->parent->settled_amount_minor)
        ->and($merged)->toHaveCount(3);
});
