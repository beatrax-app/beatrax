<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;

function cpesUser(string $suffix = 'cpes'): User
{
    return User::query()->create([
        'username' => $suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('renders the "No upcoming payments" empty state when no approved series exist', function (): void {
    $user = cpesUser('cpes-empty');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertSee('No upcoming payments');
});

it('renders a "Review recurring" CTA link to /recurring/review in the empty state', function (): void {
    $user = cpesUser('cpes-cta');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertSee('/recurring/review', false);
});

it('does not ask a reader who has already approved a series to approve one', function (): void {
    $user = cpesUser('cpes-approved');

    RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'Woonstichting Delta',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -145000,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cpes::approved',
        'next_expected_at' => CarbonImmutable::parse('2026-07-15'),
    ]);

    // A month the series cannot reach is a quiet month, not an empty account.
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 3, 'year' => 2026])
        ->assertDontSee('No upcoming payments');
});
