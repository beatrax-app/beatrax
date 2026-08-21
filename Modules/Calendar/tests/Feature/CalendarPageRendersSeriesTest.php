<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;

function cprsUser(string $suffix = 'cprs'): User
{
    return User::query()->create([
        'username' => $suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cprsSeries(User $user, string $state, ?CarbonImmutable $nextExpectedAt, string $name): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => $state,
        'cadence' => 'monthly',
        'latest_amount_minor' => -9900,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cprs::'.$name,
        'next_expected_at' => $nextExpectedAt,
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('renders an approved monthly series name on the calendar for the current display month', function (): void {
    $user = cprsUser('cprs-approved');
    cprsSeries($user, 'approved', CarbonImmutable::parse('2026-06-20'), 'Netflix Monthly');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertSee('Netflix Monthly');
});

it('does not render a pending series on the calendar', function (): void {
    $user = cprsUser('cprs-pending');
    cprsSeries($user, 'pending', CarbonImmutable::parse('2026-06-15'), 'Pending Subscription');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertDontSee('Pending Subscription');
});

it('does not render another user\'s series on the calendar', function (): void {
    $owner = cprsUser('cprs-owner');
    $other = cprsUser('cprs-other');

    cprsSeries($other, 'approved', CarbonImmutable::parse('2026-06-10'), 'Foreign Series');

    Livewire::actingAs($owner)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertDontSee('Foreign Series');
});
