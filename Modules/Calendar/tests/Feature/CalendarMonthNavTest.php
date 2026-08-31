<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Calendar\Internal\Services\CalendarMonthWindow;
use Modules\Core\Models\User;

function cmnUser(string $suffix = 'cmn'): User
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

it('prevMonth moves the display month back by one', function (): void {
    $user = cmnUser('cmn-prev');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->call('prevMonth')
        ->assertSet('month', 5)
        ->assertSet('year', 2026);
});

it('prevMonth wraps correctly from January to December of the previous year', function (): void {
    $user = cmnUser('cmn-prev-wrap');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 1, 'year' => 2026])
        ->call('prevMonth')
        ->assertSet('month', 12)
        ->assertSet('year', 2025);
});

it('nextMonth advances the display month forward by one', function (): void {
    $user = cmnUser('cmn-next');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->call('nextMonth')
        ->assertSet('month', 7)
        ->assertSet('year', 2026);
});

// The ceiling is derived from the projection the balance line is drawn from,
// never counted off in months, so the test reads it from the same place the
// page does rather than restating a number that moves with the day.
it('nextMonth is a no-op at the ceiling month the projection reaches', function (): void {
    $user = cmnUser('cmn-ceiling');
    $ceiling = app(CalendarMonthWindow::class)->ceilingMonth();

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => $ceiling->month, 'year' => $ceiling->year])
        ->call('nextMonth')
        ->assertSet('month', $ceiling->month)
        ->assertSet('year', $ceiling->year);
});

it('clamps a tampered ?year=&month= beyond the horizon to the ceiling month', function (): void {
    $user = cmnUser('cmn-url-ceiling');

    // A direct URL for 2099-12 must render the ceiling month, not a far-future
    // grid of phantom data. today = 2026-06-12 puts that at May 2027: June's
    // strip runs to 4 July 2027, three weeks past the projection behind it.
    $ceiling = app(CalendarMonthWindow::class)->ceilingMonth();
    expect($ceiling->format('M Y'))->toBe('May 2027');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 12, 'year' => 2099])
        ->assertSee($ceiling->format('M Y'));
});

it('clamps an invalid #[Url] month value (0) to the current month', function (): void {
    $user = cmnUser('cmn-clamp-zero');

    // Asserting the rendered month label proves the clamp actually happened,
    // not merely that the render survived.
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 0, 'year' => 2026])
        ->assertSee('Jun 2026');
});

it('clamps an invalid #[Url] month value (13) to the current month', function (): void {
    $user = cmnUser('cmn-clamp-thirteen');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 13, 'year' => 2026])
        ->assertSee('Jun 2026');
});
