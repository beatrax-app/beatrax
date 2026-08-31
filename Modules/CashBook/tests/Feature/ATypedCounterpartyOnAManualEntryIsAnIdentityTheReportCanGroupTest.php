<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\CashBook\Internal\Actions\RecordManualTransaction;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Direction;

// A cash entry typed on an iPhone — amount 12.34, counterparty "Cash Test
// Merchant" — stored the name and left `counterparty_id` NULL, because the
// resolver stage every imported row runs through was the one stage this path
// skipped. /reports grouped by counterparty then filed that €12.34 under "No
// counterparty" while the ledger row beside it printed the typed name.

function typedCounterpartyUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function typedCounterpartyEntry(User $user, string $counterparty, ?string $note = null): bool
{
    return app(RecordManualTransaction::class)(
        $user,
        Direction::Expense->value,
        1234,
        CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        $counterparty,
        null,
        $note,
    );
}

function typedCounterpartyRow(User $user): stdClass
{
    $row = DB::table('transactions')->where('user_id', $user->id)->first();

    return $row instanceof stdClass ? $row : new stdClass;
}

// What CounterpartySpendQuery reads: one bucket per counterparty_id, and the
// NULL bucket is the row the report labels "No counterparty".
/**
 * @return list<int|null>
 */
function typedCounterpartyGroupKeys(User $user): array
{
    return DB::table('transactions')
        ->where('user_id', $user->id)
        ->groupBy('counterparty_id')
        ->pluck('counterparty_id')
        ->map(static fn (mixed $id): ?int => $id === null ? null : (int) $id)
        ->all();
}

it('gives a hand-entered row the counterparty identity an imported row gets', function (): void {
    $user = typedCounterpartyUser('typed-counterparty-identity');

    expect(typedCounterpartyEntry($user, 'Cash Test Merchant'))->toBeTrue();

    $row = typedCounterpartyRow($user);

    expect($row->counterparty_name)->toBe('Cash Test Merchant')
        ->and($row->counterparty_id)->not->toBeNull();

    $counterparty = DB::table('counterparties')->where('id', $row->counterparty_id)->first();

    expect($counterparty)->toBeInstanceOf(stdClass::class)
        ->and($counterparty->user_id)->toBe($user->id)
        ->and($counterparty->display_name)->toBe('Cash Test Merchant')
        ->and($counterparty->slug)->toBe('cash-test-merchant');
});

it('groups the entry under the counterparty the reader typed, not under no counterparty', function (): void {
    $user = typedCounterpartyUser('typed-counterparty-grouping');

    typedCounterpartyEntry($user, 'Cash Test Merchant');

    expect(typedCounterpartyGroupKeys($user))->not->toContain(null);
});

it('invents no counterparty for an entry the reader named none on', function (): void {
    $user = typedCounterpartyUser('typed-counterparty-blank');

    expect(typedCounterpartyEntry($user, '  '))->toBeTrue();

    $row = typedCounterpartyRow($user);

    expect($row->counterparty_name)->toBeNull();

    expect(DB::table('counterparties')->where('user_id', $user->id)->count())->toBe(0);
});
