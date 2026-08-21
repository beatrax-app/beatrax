<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Http\Livewire\FixedPaymentsCard;

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
