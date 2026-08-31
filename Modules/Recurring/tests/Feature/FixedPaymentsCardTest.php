<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Recurring\Internal\Enums\FixedPaymentsFilter;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Http\Livewire\FixedPaymentsCard;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Modules\Recurring\Public\Support\SeriesDueWindow;

function fpcUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function fpcSeries(User $user, string $detectedName, int $monthlyEur, array $overrides = []): RecurringSeries
{
    return RecurringSeries::query()->create(array_merge([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $detectedName,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => $monthlyEur,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => $monthlyEur,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'expense::'.$detectedName.'::eur::monthly',
        'next_expected_at' => '2026-06-17',
        'next_expected_confidence_low' => false,
    ], $overrides));
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    $this->user = fpcUser('fpc');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders the top six approved series sorted DESC by absolute monthly equivalent (renders-top-six)', function (): void {
    for ($i = 0; $i < 10; $i++) {
        fpcSeries($this->user, 'expense-'.$i, -(100 + $i) * 100, [
            'cluster_key' => 'expense::expense-'.$i.'::eur::monthly',
        ]);
    }

    $component = Livewire::actingAs($this->user)->test(FixedPaymentsCard::class);

    $rows = $component->viewData('rows');
    expect($rows)->toBeArray();
    /** @var array<int, object> $rows */
    expect(count($rows))->toBe(6);

    // Highest absolute first (expense-9 is -10900 minor → magnitude 10900).
    expect($rows[0]->detectedName)->toBe('expense-9');
})->group('renders-top-six');

it('hides series from other users (cross-user-empty)', function (): void {
    $other = fpcUser('fpc-other');
    fpcSeries($other, 'other-thing', -50000);

    $component = Livewire::actingAs($this->user)->test(FixedPaymentsCard::class);

    $rows = $component->viewData('rows');
    expect($rows)->toBeArray();
    expect($rows)->toBe([]);
})->group('cross-user-empty');

it('filters rows to series whose next-expected falls in the current month when this-month is active', function (): void {
    fpcSeries($this->user, 'this-month-row', -1000, [
        'next_expected_at' => '2026-05-25',
        'cluster_key' => 'expense::this-month-row::eur::monthly',
    ]);
    fpcSeries($this->user, 'next-month-row', -2000, [
        'next_expected_at' => '2026-06-25',
        'cluster_key' => 'expense::next-month-row::eur::monthly',
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(FixedPaymentsCard::class)
        ->set('filter', 'this-month');

    $rows = $component->viewData('rows');
    expect($rows)->toBeArray();
    /** @var array<int, object> $rows */
    expect(count($rows))->toBe(1);
    expect($rows[0]->detectedName)->toBe('this-month-row');
})->group('this-month-filter');

it('renders a View all link pointing at recurring.index (view-all-link-points-at-recurring-index)', function (): void {
    fpcSeries($this->user, 'spotify', -999);

    $component = Livewire::actingAs($this->user)->test(FixedPaymentsCard::class);
    $component->assertSee(route('recurring.index'), false);
})->group('view-all-link-points-at-recurring-index');

it('exposes the filter via a #[Url] query string binding (url-attribute-binds-filter)', function (): void {
    fpcSeries($this->user, 'spotify', -999);

    $reflection = new ReflectionClass(FixedPaymentsCard::class);
    $filterProperty = $reflection->getProperty('filter');
    $attributes = $filterProperty->getAttributes(Url::class);
    expect($attributes)->not->toBe([]);
    /** @var ReflectionAttribute<Url> $attribute */
    $attribute = $attributes[0];
    $arguments = $attribute->getArguments();
    expect($arguments)->toMatchArray(['as' => 'fp-filter']);
})->group('url-attribute-binds-filter');

// The filter reached the query and the highlight as a bare literal in three
// places, and an unknown value from the query string fell through to "all"
// only in setFilter() — never on the #[Url] hydration path.
it('renders the card under the All filter when the query string carries a value the enum does not name', function (): void {
    fpcSeries($this->user, 'spotify', -999);

    $component = Livewire::actingAs($this->user)
        ->test(FixedPaymentsCard::class)
        ->set('filter', 'last-century');

    expect($component->viewData('activeFilter'))->toBe(FixedPaymentsFilter::All);
    $component->assertOk();
});

it('marks the active filter button when the reader picks this month', function (): void {
    fpcSeries($this->user, 'spotify', -999, ['next_expected_at' => '2026-05-25']);

    $html = Livewire::actingAs($this->user)
        ->test(FixedPaymentsCard::class)
        ->call('setFilter', FixedPaymentsFilter::ThisMonth->value)
        ->html();

    expect(substr_count($html, 'bg-white font-medium text-slate-900 shadow-sm'))->toBe(1);
});

// The filter labels and both empty states are reached through
// FixedPaymentsFilter::labelKey() and ::emptyKey(), which assemble the key from
// a prefix and an arm. No literal key exists for three of the four, so a sweep
// reading call sites off literals reports them as lines nothing renders.
it('renders the copy its filter enum assembles rather than spells', function (): void {
    fpcSeries($this->user, 'spotify', -999, ['next_expected_at' => '2026-05-25']);

    $card = Livewire::actingAs($this->user)->test(FixedPaymentsCard::class);

    // Named here, never asked of the enum: reading the key back off the object
    // under test passes whichever arm it returns, including one arm twice.
    $card->assertSee(Lang::get('recurring::fixed_payments.filter_all'), escape: false);
    $card->assertSee(Lang::get('recurring::fixed_payments.filter_this_month'), escape: false);
});

it('names the empty state after the filter that emptied the card', function (): void {
    $thisMonth = Livewire::actingAs($this->user)
        ->test(FixedPaymentsCard::class)
        ->call('setFilter', FixedPaymentsFilter::ThisMonth->value);

    $thisMonth->assertSee(Lang::get('recurring::fixed_payments.empty_this_month'), escape: false);
    $thisMonth->assertDontSee(Lang::get('recurring::fixed_payments.empty_all'), escape: false);

    $all = Livewire::actingAs($this->user)
        ->test(FixedPaymentsCard::class)
        ->call('setFilter', FixedPaymentsFilter::All->value);

    $all->assertSee(Lang::get('recurring::fixed_payments.empty_all'), escape: false);
    $all->assertDontSee(Lang::get('recurring::fixed_payments.empty_this_month'), escape: false);
});

// The card and the position summary's "upcoming" list are stacked on one
// dashboard. Asked as a calendar month here and as the reader's period there,
// they disagreed by 24 days at each end for anyone whose period opens on the
// 25th: a series due on the 10th of next month was in the list and off the
// card, and one due on the 5th of this month was on the card and off the list.
it('draws "This month" over the same window the upcoming list is drawn over', function (): void {
    $user = fpcUser('fpc-period-25');
    $user->period_start_day = 25;
    $user->save();

    $periods = app(PeriodQuery::class);
    $period = $periods->containingForUser($user, CarbonImmutable::now());

    // 2026-05-17 with a period opening on the 25th puts the reader inside
    // 25 Apr … 24 May, which the calendar month of May covers neither end of.
    expect($period->start->toDateString())->toBe('2026-04-25')
        ->and($period->endExclusive->toDateString())->toBe('2026-05-25');

    fpcSeries($user, 'inside-period-outside-month', -900000, [
        'cluster_key' => 'expense::inside-period-outside-month::eur::monthly',
        'next_expected_at' => $period->start->toDateString(),
    ]);
    fpcSeries($user, 'inside-month-outside-period', -800000, [
        'cluster_key' => 'expense::inside-month-outside-period::eur::monthly',
        'next_expected_at' => $period->endExclusive->toDateString(),
    ]);

    $upcoming = SeriesDueWindow::dueWithin(
        app(RecurringSeriesQuery::class)->allApprovedForUser($user),
        $period,
    );
    $onCard = array_map(
        static fn (object $row): string => (string) $row->detectedName,
        app(FixedPaymentsViewQuery::class)->topByMonthlyEquivalent($user, 6, $period),
    );

    expect($onCard)->toBe(
        array_map(static fn (object $row): string => (string) $row->detectedName, $upcoming),
        'the card and the upcoming list must name the same series',
    );
    expect($onCard)->toBe(['inside-period-outside-month']);
});

it('renders the period-window rows through the component itself', function (): void {
    $user = fpcUser('fpc-period-render');
    $user->period_start_day = 25;
    $user->save();

    fpcSeries($user, 'due-in-period', -900000, [
        'cluster_key' => 'expense::due-in-period::eur::monthly',
        'next_expected_at' => '2026-04-25',
    ]);
    fpcSeries($user, 'due-after-period', -800000, [
        'cluster_key' => 'expense::due-after-period::eur::monthly',
        'next_expected_at' => '2026-05-25',
    ]);

    Livewire::actingAs($user)
        ->test(FixedPaymentsCard::class, ['filter' => FixedPaymentsFilter::ThisMonth->value])
        ->assertSee('due-in-period')
        ->assertDontSee('due-after-period');
});
