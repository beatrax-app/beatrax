<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

// The fold walks from genesis, so reading spend, income and the FX rates a
// period at a time cost a round trip per month of the reader's whole history.

it('reads the same number of times whether the walk is one month or twelve', function (): void {
    // Genesis is the earliest assignment, so the walk is one period for the
    // first reader and twelve for the second. Both start their month on the
    // first, so the grid is the same for each.
    $shortReader = foldReaderAssignedAt(null);
    $periods = app(PeriodQuery::class);
    $current = $periods->current();

    $twelveMonthsBack = $current;
    for ($i = 0; $i < 11; $i++) {
        $twelveMonthsBack = $periods->previous($twelveMonthsBack);
    }

    foldAssign($shortReader, $current->start);
    $shortWalk = foldQueryCount($shortReader, $current);

    $longReader = foldReaderAssignedAt($twelveMonthsBack->start);
    $longWalk = foldQueryCount($longReader, $current);

    expect($longWalk)->toBe($shortWalk);
});

function foldReaderAssignedAt(?CarbonImmutable $periodStart): User
{
    $user = User::query()->create([
        'username' => 'fold-cost-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
    test()->actingAs($user);

    if ($periodStart !== null) {
        foldAssign($user, $periodStart);
    }

    return $user;
}

function foldAssign(User $user, CarbonImmutable $periodStart): void
{
    test()->actingAs($user);

    $category = Category::query()->create([
        'user_id' => null, 'name' => 'Groceries',
        'slug' => 'foldcost-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 1,
    ]);

    app(EnvelopeWriter::class)->setAssigned($user, $category->id, $periodStart, 10000);
}

function foldQueryCount(User $user, Period $target): int
{
    $count = 0;
    DB::listen(function (QueryExecuted $q) use (&$count): void {
        $count++;
    });

    app(CarryoverQuery::class)->forUserAndPeriod($user, $target);

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    return $count;
}
