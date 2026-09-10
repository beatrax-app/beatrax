<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

// Two ways a rollup took `type` at face value. The importer types from the
// amount's sign, so a refund arrives typed `income` and was counted as money
// in rather than as the spend it gives back; and the reclassify picker will
// write any type onto any row, so an expense labelled `income` put its debit
// in the tile that means money in.

function flowCategory(DatabaseManager $db, int $userId, string $name): int
{
    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

/** @return array{in: int, out: int, net: int} */
function flowTiles(User $user, Period $period): array
{
    $summary = app(ThisPeriodAtAGlanceQuery::class)->for($user, $period);

    return [
        'in' => $summary->inflow->toMinor(),
        'out' => $summary->outflow->toMinor(),
        'net' => $summary->net->toMinor(),
    ];
}

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);
    $this->groceries = flowCategory(app(DatabaseManager::class), (int) $this->fixtureUser->id, 'Groceries');

    $this->period = new Period(
        start: CarbonImmutable::parse('2026-05-01'),
        endExclusive: CarbonImmutable::parse('2026-06-01'),
        label: 'May 2026',
    );
});

afterEach(fn () => CarbonImmutable::setTestNow());

// EUR 100.00 of groceries, EUR 30.00 of it returned. The return is a credit,
// so the importer types it `income`; what says it is a return is payment_type.
$flowSeedPurchaseAndRefund = function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'type' => 'expense',
        'amount_minor' => -10000,
        'settled_amount_minor' => -10000,
        'posted_at' => '2026-05-05',
        'category_id' => $this->groceries,
        'payment_type' => 'pin',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'type' => 'income',
        'amount_minor' => 3000,
        'settled_amount_minor' => 3000,
        'posted_at' => '2026-05-09',
        'category_id' => $this->groceries,
        'payment_type' => 'refund',
    ]);
};

it('counts a refund as spend given back, not as money coming in', function () use ($flowSeedPurchaseAndRefund): void {
    $flowSeedPurchaseAndRefund->call($this);

    expect(flowTiles($this->fixtureUser, $this->period))->toBe(['in' => 0, 'out' => 7000, 'net' => -7000]);
});

it('leaves a refund out of the income an envelope period has to assign', function () use ($flowSeedPurchaseAndRefund): void {
    $flowSeedPurchaseAndRefund->call($this);

    expect(app(ThisPeriodAtAGlanceQuery::class)->incomeForPeriod($this->fixtureUser, $this->period, 'EUR'))->toBe(0);
});

// The span read the envelope fold walks with, which has to answer the same
// figure as the period read above or the grid and its header disagree.
it('leaves a refund out of the per-day income the fold walks', function () use ($flowSeedPurchaseAndRefund): void {
    $flowSeedPurchaseAndRefund->call($this);

    $byDay = app(ThisPeriodAtAGlanceQuery::class)->incomeForSpanByCurrencyPerDay($this->fixtureUser, $this->period);

    expect($byDay)->toBe([]);
});

it('takes a refund off the category it was refunded from', function () use ($flowSeedPurchaseAndRefund): void {
    $flowSeedPurchaseAndRefund->call($this);

    $spend = app(SpendByCategoryQuery::class)->forUserAndPeriodByCurrency((int) $this->fixtureUser->id, $this->period);

    expect($spend)->toBe([$this->groceries.'|EUR' => 7000]);
});

it('reads a debit as money out however the row is labelled', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'type' => 'income',
        'amount_minor' => -10000,
        'settled_amount_minor' => -10000,
        'posted_at' => '2026-05-05',
    ]);

    expect(flowTiles($this->fixtureUser, $this->period))->toBe(['in' => 0, 'out' => 10000, 'net' => -10000]);
});

it('reads a credit as money in however the row is labelled', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'type' => 'expense',
        'amount_minor' => 50000,
        'settled_amount_minor' => 50000,
        'posted_at' => '2026-05-05',
    ]);

    expect(flowTiles($this->fixtureUser, $this->period))->toBe(['in' => 50000, 'out' => 0, 'net' => 50000]);
});
