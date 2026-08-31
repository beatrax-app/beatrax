<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Goals\Models\Goal;
use Modules\Import\Public\Enums\SyntheticSourceFormat;
use Modules\Ledger\Database\Seeders\Demo\DemoUsersSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Enums\PotMovementKind;

beforeEach(function (): void {
    $this->artisan('demo:seed', ['--reset' => true])->assertSuccessful();
});

function zeroDecimalCode(mixed $code): bool
{
    return is_string($code) && Money::tryOfMinor(0, $code)?->minorUnitsPerMajor() === 1;
}

/**
 * @return Collection<int, Account>
 */
function demoAccountsThatCanHoldPots(): Collection
{
    return Account::query()
        ->get(['id', 'user_id', 'kind', 'default_currency', 'starting_balance_minor', 'opening_balance_minor'])
        ->filter(static fn (Account $a): bool => AccountKind::tryFrom((string) $a->kind)?->holdsSpendableBalance() === true
            && zeroDecimalCode($a->default_currency));
}

/**
 * @return Collection<int, Pot>
 */
function demoPotsInAZeroDecimalCurrency(): Collection
{
    return Pot::query()
        ->whereIn('account_id', demoAccountsThatCanHoldPots()->pluck('id')->all())
        ->get(['id', 'user_id', 'account_id', 'goal_id', 'currency']);
}

function demoPotMovements(): Collection
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('pot_movements')
        ->whereIn('pot_id', demoPotsInAZeroDecimalCurrency()->pluck('id')->all())
        ->get(['pot_id', 'counterpart_pot_id', 'amount_minor', 'currency', 'kind', 'memo']);
}

function demoPrimaryUser(): User
{
    /** @var User $user */
    $user = User::query()->where('username', DemoUsersSeeder::usernames()[0])->firstOrFail();

    return $user;
}

// `PotWriter` refuses any account whose kind holds no allocatable balance, so
// a dataset whose only zero-decimal account is a card cannot carry a single
// yen pot -- and every surface downstream of one is then demonstrable in
// hundredths alone, where a stray divisor and the right one agree.
it('carries an account that both holds pots and has no minor unit to hold them in', function (): void {
    $accounts = demoAccountsThatCanHoldPots();

    expect($accounts)->not->toBeEmpty();

    // A baseline is what /reconcile subtracts the statement figure from; with
    // none the difference is the whole balance and no toggling closes it.
    // Six figures of it, so a stray hundredth loses two digits on screen.
    foreach ($accounts as $account) {
        $baseline = $account->opening_balance_minor ?? $account->starting_balance_minor;
        expect($baseline)->not->toBeNull();
    }

    expect($accounts->contains(
        static fn (Account $a): bool => abs((int) ($a->opening_balance_minor ?? $a->starting_balance_minor)) >= 100000,
    ))->toBeTrue();
});

it('carries pots funded, withdrawn from and moved between in a currency with no minor unit', function (): void {
    $pots = demoPotsInAZeroDecimalCurrency();
    expect($pots->count())->toBeGreaterThanOrEqual(2);

    foreach ($pots as $pot) {
        expect(zeroDecimalCode($pot->currency))->toBeTrue();
    }

    $movements = demoPotMovements();
    $kinds = $movements->pluck('kind')->unique()->all();

    // ReleasedOnArchive is written only by PotWriter::archive(), and the demo
    // ships every pot live, so the seeder cannot produce one. The assertion
    // below is the forcing function: the day the demo archives a pot this goes
    // red, and this exclusion is deleted rather than widened.
    foreach (PotMovementKind::cases() as $kind) {
        if ($kind === PotMovementKind::ReleasedOnArchive) {
            expect($kinds)->not->toContain($kind->value);

            continue;
        }
        expect($kinds)->toContain($kind->value);
    }

    // The cross-pot move is the pair, not one row: each leg names the other,
    // and the two amounts cancel.
    $legs = $movements->filter(static fn (object $m): bool => $m->counterpart_pot_id !== null);
    expect($legs->count())->toBe(2);
    expect((int) $legs->sum('amount_minor'))->toBe(0);
    expect($legs->pluck('pot_id')->sort()->values()->all())
        ->toBe($legs->pluck('counterpart_pot_id')->sort()->values()->all());
});

// The point of the figures, not a coincidence of them: with two balances a
// hundredfold apart, a surface that divides one by a hundred prints the other
// one's number, and the reader sees two rows claiming the same money.
it('carries two pot balances a hundredfold apart, so a stray divisor prints the wrong one', function (): void {
    $balances = demoPotMovements()
        ->groupBy('pot_id')
        ->map(static fn (Collection $rows): int => (int) $rows->sum('amount_minor'))
        ->values()
        ->all();

    $pairs = [];
    foreach ($balances as $larger) {
        foreach ($balances as $smaller) {
            if ($larger >= 100000 && $larger === $smaller * 100) {
                $pairs[] = [$larger, $smaller];
            }
        }
    }

    expect($pairs)->not->toBeEmpty();
});

it('carries a goal denominated in a currency with no minor unit, funded from a pot and from a credit', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $goals = Goal::query()
        ->get(['id', 'user_id', 'target_minor', 'target_currency'])
        ->filter(static fn (Goal $g): bool => zeroDecimalCode($g->target_currency));

    expect($goals)->not->toBeEmpty();

    // A target a stray hundredth would render with two fewer digits than the
    // pot balance beneath it.
    expect($goals->contains(static fn (Goal $g): bool => (int) $g->target_minor >= 100000))->toBeTrue();

    $potFundedGoalIds = demoPotsInAZeroDecimalCurrency()
        ->pluck('goal_id')
        ->filter()
        ->all();
    expect(array_intersect($goals->pluck('id')->all(), $potFundedGoalIds))->not->toBeEmpty();

    // The other funding route. A pot-linked goal takes its whole progress from
    // the pot, so an attributed credit only reads on a goal without one.
    $creditFunded = $db->connection()->table('goal_contributions')
        ->join('transactions', 'transactions.id', '=', 'goal_contributions.transaction_id')
        ->whereIn('goal_contributions.goal_id', $goals->pluck('id')->all())
        ->whereNotIn('goal_contributions.goal_id', $potFundedGoalIds)
        ->get(['transactions.settled_amount_minor', 'transactions.settled_currency']);

    expect($creditFunded)->not->toBeEmpty();

    foreach ($creditFunded as $row) {
        expect(zeroDecimalCode($row->settled_currency))->toBeTrue();
    }
});

// The cash book reads one account per user -- the first of kind `cash` -- and
// types its amount at that account's own scale. With the demo's cash account
// in the reader's own base currency, the field that follows the account and a
// field that wrongly followed the base would be indistinguishable.
it('books its demo cash entries against an account with no minor unit', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $cashAccountIds = demoAccountsThatCanHoldPots()
        ->filter(static fn (Account $a): bool => (string) $a->kind === AccountKind::Cash->value)
        ->pluck('id')
        ->all();

    expect($cashAccountIds)->not->toBeEmpty();

    $entries = $db->connection()->table('transactions')
        ->where('source_format', SyntheticSourceFormat::Manual->value)
        ->get(['account_id', 'settled_amount_minor', 'settled_currency']);

    expect($entries)->not->toBeEmpty();

    foreach ($entries as $entry) {
        expect($cashAccountIds)->toContain((int) $entry->account_id);
        expect(zeroDecimalCode($entry->settled_currency))->toBeTrue();
    }

    expect($entries->contains(static fn (object $e): bool => abs((int) $e->settled_amount_minor) >= 10000))->toBeTrue();
});

// The envelope fold runs in the reader's base currency and silently leaves out
// any line it cannot convert, so zero-decimal spend reaches the grid only
// while a rate for that pair exists.
it('can fold its zero-decimal spend into the reader base currency the envelope grid runs in', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var CrossCurrencyTotal $fx */
    $fx = app(CrossCurrencyTotal::class);
    /** @var BaseCurrency $baseCurrency */
    $baseCurrency = app(BaseCurrency::class);

    $user = demoPrimaryUser();
    $base = $baseCurrency->forUser($user);

    $categorised = $db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->where('source_format', 'demo')
        ->whereNotNull('category_id')
        ->where('settled_amount_minor', '<', 0)
        ->distinct()
        ->pluck('settled_currency')
        ->filter(static fn (mixed $code): bool => zeroDecimalCode($code))
        ->values()
        ->all();

    expect($categorised)->not->toBeEmpty();
    expect(array_keys($fx->ratesTo($categorised, $base)))->toBe($categorised);
});

// The sum-to-parent rule is where a wrong scale shows first: a leg read at a
// hundredth is weighed against a parent that has none, so the editor refuses as
// over-allocated rather than merely rendering an odd figure.
it('splits a whole-unit parent into legs that sum to it exactly', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $parents = $db->connection()->table('transaction_splits as s')
        ->join('transactions as t', 't.id', '=', 's.transaction_id')
        ->groupBy('t.id', 't.settled_amount_minor', 't.settled_currency')
        ->get([
            't.id',
            't.settled_amount_minor',
            't.settled_currency',
            $db->connection()->raw('SUM(s.settled_amount_minor) AS legs_minor'),
            $db->connection()->raw('COUNT(*) AS leg_count'),
        ])
        ->filter(static fn (object $row): bool => zeroDecimalCode($row->settled_currency));

    expect($parents)->not->toBeEmpty();

    foreach ($parents as $parent) {
        expect((int) $parent->leg_count)->toBeGreaterThanOrEqual(2);
        expect((int) $parent->legs_minor)->toBe((int) $parent->settled_amount_minor);
    }
});

it('projects an account with no minor unit in its own denomination', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $payload = $db->connection()->table('forecast_runs')
        ->where('user_id', demoPrimaryUser()->id)
        ->whereNull('scenario_id')
        ->orderByDesc('id')
        ->value('result_json');

    expect($payload)->toBeString();

    /** @var array{accounts: array<string, array{default_currency: string, points: list<array{currency: string}>}>} $decoded */
    $decoded = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);

    $zeroDecimal = array_filter(
        $decoded['accounts'],
        static fn (array $block): bool => zeroDecimalCode($block['default_currency']),
    );

    expect($zeroDecimal)->not->toBeEmpty();

    foreach ($zeroDecimal as $block) {
        expect($block['points'])->not->toBeEmpty();

        foreach ($block['points'] as $point) {
            expect($point['currency'])->toBe($block['default_currency']);
        }
    }
});
