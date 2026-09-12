<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\FX\Public\Dto\RateSet;
use Modules\FX\Public\Dto\RateUsed;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

// Not a Currency case: the enum names only the codes the code itself spells,
// and this one is here because the rate table cannot reach it.
const TGT_UNPRICED = 'ZAR';

// The three period tiles render ONE disclosure, taken from the inflow fold,
// while the outflow is a separately folded result of its own. That is safe only
// because the two folds cannot disagree, and nothing said so: a currency with
// no rate, zero inflow and a real outflow would otherwise be money missing from
// OUT that only the inflow's list could have named, and the inflow's list would
// not have it.
//
// It holds because the converted/unconverted decision is rate availability and
// not amount -- `convert()` answers null when the pair has no rate whatever the
// figure is, and a zero bucket converts perfectly well when it has one. One
// GROUP BY settled_currency fills both maps with the same keys, and both folds
// read the same RateSet, so the two sides are the same answer twice.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Jpy->value,
        'rate_date' => '2026-02-10',
        'rate' => '159.10',
        'source' => 'ecb',
        'created_at' => '2026-05-15 00:00:00',
        'updated_at' => '2026-05-15 00:00:00',
    ]);

    $this->glance = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $this->period = $this->app->make(PeriodQuery::class)->current();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// A currency the rate table cannot reach, spent and never received: the tile
// that is short of it is OUT, and the list that has to name it is the one all
// three tiles read.
it('names a currency only the outflows held, on the list the tiles are given', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => 250_000, 'settled_amount_minor' => 250_000, 'posted_at' => '2026-05-05',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -111_100, 'currency' => TGT_UNPRICED,
        'settled_amount_minor' => -111_100, 'settled_currency' => TGT_UNPRICED,
        'posted_at' => '2026-05-06',
    ]);

    $summary = $this->glance->for($this->fixtureUser, $this->period);

    expect($summary->unconvertedCurrencies)->toBe([TGT_UNPRICED])
        ->and($summary->conversion?->unconvertedList())->toBe(TGT_UNPRICED)
        // Left out rather than added at one to one: the euro figure is the euro
        // row alone, and the rand is named beside it.
        ->and($summary->outflow->toMinor())->toBe(0);
});

// The same for the rate half: a yen expense in a period with no yen income
// puts the yen leg in the disclosure the IN tile renders too, because the three
// tiles are one roll-up over the period's currencies and were built from one
// rate set.
it('names a rate only the outflows used, on that same list', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => 250_000, 'settled_amount_minor' => 250_000, 'posted_at' => '2026-05-05',
    ]);
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -480_000, 'currency' => Currency::Jpy->value,
        'settled_amount_minor' => -480_000, 'settled_currency' => Currency::Jpy->value,
        'posted_at' => '2026-05-06',
    ]);

    $summary = $this->glance->for($this->fixtureUser, $this->period);

    expect($summary->conversion?->rates[0]->from)->toBe(Currency::Jpy->value)
        ->and($summary->conversion?->asOf()?->toDateString())->toBe('2026-02-10');
});

// The invariant the shared disclosure rests on, asked of the seam directly: the
// two folds differ only in their amounts, and an amount decides neither half.
it('folds the same two answers for a bucket map that differs only in its figures', function (): void {
    /** @var CrossCurrencyTotal $fx */
    $fx = app(CrossCurrencyTotal::class);

    $rates = RateSet::of(Currency::Eur->value, [
        Currency::Jpy->value => new RateUsed(
            from: Currency::Jpy->value,
            to: Currency::Eur->value,
            rate: '0.00628536',
            source: 'ecb',
            asOf: CarbonImmutable::parse('2026-02-10'),
            isStale: true,
        ),
    ]);

    $keys = [Currency::Eur->value => 0, Currency::Jpy->value => 0, TGT_UNPRICED => 0];

    $withFigures = $fx->withRates([...$keys, Currency::Jpy->value => -480_000, TGT_UNPRICED => -111_100], Currency::Eur->value, $rates);
    $allZero = $fx->withRates($keys, Currency::Eur->value, $rates);

    // A pair with no rate is named at zero as it is at any figure, and a pair
    // with one is disclosed at zero as it is at any figure.
    expect($allZero->unconverted)->toBe($withFigures->unconverted)
        ->and($allZero->unconverted)->toBe([TGT_UNPRICED])
        ->and($allZero->rates->codes())->toBe($withFigures->rates->codes())
        ->and($allZero->rates->codes())->toBe([Currency::Jpy->value])
        ->and($allZero->minor)->toBe(0);
});
