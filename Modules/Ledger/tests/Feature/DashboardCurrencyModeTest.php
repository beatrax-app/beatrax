<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Dto\PerCurrencyTile;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);

    $this->query = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $this->periods = $this->app->make(PeriodQuery::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns one tile-row per currency in original mode for a mixed EUR/USD month', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => 250000,
        'currency' => 'EUR',
        'settled_amount_minor' => 250000,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -5000,
        'currency' => 'EUR',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-08',
        'booked_at' => '2026-05-08 12:00:00',
    ]);
    // settled_currency stays USD, which is what the GROUP BY splits on.
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'USD',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'USD',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
    ]);

    $period = $this->periods->current();
    $tiles = $this->query->forByCurrency($this->fixtureUser, $period);

    expect($tiles)->toHaveCount(2);
    expect($tiles[0])->toBeInstanceOf(PerCurrencyTile::class);
    expect($tiles[0]->currency)->toBe('EUR');
    expect($tiles[0]->inflow->toMinor())->toBe(250000);
    expect($tiles[0]->outflow->toMinor())->toBe(5000);
    expect($tiles[0]->net->toMinor())->toBe(245000);
    expect($tiles[1]->currency)->toBe('USD');
    expect($tiles[1]->inflow->toMinor())->toBe(0);
    expect($tiles[1]->outflow->toMinor())->toBe(1299);
    expect($tiles[1]->net->toMinor())->toBe(-1299);
})->group('phase-3');

it('collapses an EUR-only month to one tile-row in original mode', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => 250000,
        'currency' => 'EUR',
        'settled_amount_minor' => 250000,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
    ]);

    $period = $this->periods->current();
    $tiles = $this->query->forByCurrency($this->fixtureUser, $period);

    expect($tiles)->toHaveCount(1);
    expect($tiles[0]->currency)->toBe('EUR');
    expect($tiles[0]->inflow->toMinor())->toBe(250000);
    expect($tiles[0]->outflow->toMinor())->toBe(1299);
    expect($tiles[0]->net->toMinor())->toBe(248701);
})->group('phase-3');

it('omits zero-activity currencies from the original-mode tile rows', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-08',
        'booked_at' => '2026-05-08 12:00:00',
    ]);
    // Outside the current period.
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -2500,
        'currency' => 'USD',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'USD',
        'posted_at' => '2026-03-15',
        'booked_at' => '2026-03-15 12:00:00',
    ]);

    $period = $this->periods->current();
    $tiles = $this->query->forByCurrency($this->fixtureUser, $period);

    expect($tiles)->toHaveCount(1);
    expect($tiles[0]->currency)->toBe('EUR');
})->group('phase-3');

it('returns one summary in eur_only mode (settled-EUR sum)', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => 250000,
        'currency' => 'EUR',
        'settled_amount_minor' => 250000,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
    ]);

    $period = $this->periods->current();
    $summary = $this->query->for($this->fixtureUser, $period);

    expect($summary)->toBeInstanceOf(DashboardSummary::class);
    expect($summary->inflow->currency())->toBe('EUR');
    expect($summary->inflow->toMinor())->toBe(250000);
    expect($summary->outflow->toMinor())->toBe(1299);
    expect($summary->net->toMinor())->toBe(248701);
})->group('phase-3');

it('orders the original-mode tile rows by settled_currency alphabetically', function (): void {
    // Seeded out of alphabetical order on purpose.
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'USD',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'USD',
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -999,
        'currency' => 'GBP',
        'settled_amount_minor' => -999,
        'settled_currency' => 'GBP',
        'posted_at' => '2026-05-07',
        'booked_at' => '2026-05-07 12:00:00',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -500,
        'currency' => 'EUR',
        'settled_amount_minor' => -500,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-09',
        'booked_at' => '2026-05-09 12:00:00',
    ]);

    $period = $this->periods->current();
    $tiles = $this->query->forByCurrency($this->fixtureUser, $period);

    expect($tiles)->toHaveCount(3);
    expect(array_map(static fn (PerCurrencyTile $tile): string => $tile->currency, $tiles))
        ->toBe(['EUR', 'GBP', 'USD']);
})->group('phase-3');
