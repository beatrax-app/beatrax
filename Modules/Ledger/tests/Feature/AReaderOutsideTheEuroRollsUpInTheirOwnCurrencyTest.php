<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

uses(RefreshDatabase::class);

// The reporting currency is offered from the `currencies` table, which held the
// four codes the app writes as literals while the bundled rate snapshot prices
// thirty. A Swedish reader could not choose the krona, so every roll-up they
// ever saw was denominated by the install rather than by them.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    $this->seedFixtureUserAndAccount();
    $this->user = $this->fixtureUser;
    $this->actingAs($this->user);

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->user);

    $this->query = $this->app->make(ThisPeriodAtAGlanceQuery::class);
    $this->periods = $this->app->make(PeriodQuery::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function chooseTheReportingCurrency(string $code): void
{
    Livewire::test(SettingsPage::class)
        ->set('baseCurrency', $code)
        ->call('save')
        ->assertHasNoErrors();
}

it('lets a reader choose any currency the bundled snapshot can price', function (string $code): void {
    chooseTheReportingCurrency($code);

    expect($this->user->fresh()->base_currency)->toBe($code);
})->with(['SEK', 'CHF', 'NOK', 'ISK', 'BRL', 'ZAR', 'INR', 'KRW']);

it('rolls the period up in the currency the reader chose', function (): void {
    // One euro figure, so the expected krona total is the snapshot rate applied
    // to it and nothing else: 100.00 EUR at 10.8430 is 1,084.30 SEK.
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => 10000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
    ]);

    chooseTheReportingCurrency('SEK');

    $summary = $this->query->for($this->user->fresh(), $this->periods->current());

    expect($summary->inflow->currency())->toBe('SEK')
        ->and($summary->outflow->currency())->toBe('SEK')
        ->and($summary->net->currency())->toBe('SEK')
        ->and($summary->inflow->toMinor())->toBe(108430);
});

it('converts every currency it spans into the chosen one and leaves none out', function (): void {
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => 10000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
    ]);
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => -5000,
        'currency' => 'USD',
        'settled_amount_minor' => -5000,
        'settled_currency' => 'USD',
        'posted_at' => '2026-05-06',
        'booked_at' => '2026-05-06 12:00:00',
    ]);

    chooseTheReportingCurrency('SEK');

    $summary = $this->query->for($this->user->fresh(), $this->periods->current());

    // A dollar leg reaches the krona through the euro the snapshot quotes both
    // against; a currency the cross could not reach would be named here instead
    // of counted, and the total would silently be the euro half alone.
    expect($summary->unconvertedCurrencies)->toBe([])
        ->and($summary->outflow->currency())->toBe('SEK')
        ->and($summary->outflow->toMinor())->toBeGreaterThan(0);
});

// A currency with no minor unit is the case a hundredfold error hides in: the
// same integer is 148.30 ISK and 1.48 EUR, and a roll-up that scaled the krona
// like the euro would read as a hundred times the money.
it('does not mis-scale a roll-up in a currency that counts no minor unit', function (): void {
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'amount_minor' => 10000,
        'posted_at' => '2026-05-05',
        'booked_at' => '2026-05-05 12:00:00',
    ]);

    chooseTheReportingCurrency('ISK');

    $summary = $this->query->for($this->user->fresh(), $this->periods->current());

    // 100.00 EUR at 148.30 is 14,830 krónur — fourteen thousand minor units at
    // a scale of one, not 1,483,000 at a scale of a hundred.
    expect($summary->inflow->currency())->toBe('ISK')
        ->and($summary->inflow->toMinor())->toBe(14830)
        ->and($summary->inflow->format())->not->toContain(',00');
});
