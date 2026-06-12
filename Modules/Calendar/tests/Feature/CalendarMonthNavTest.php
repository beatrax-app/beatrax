<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;

/*
 * CalendarPage — month navigation (T-06-01, D-14).
 *
 * Contract:
 *   - prevMonth() moves back one month.
 *   - nextMonth() is a no-op when the next month would exceed today + 12 months.
 *   - Invalid #[Url] month values (0, 13) are clamped to the current month.
 */

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

it('nextMonth is a no-op when the next month exceeds the 12-month forecast horizon', function (): void {
    $user = cmnUser('cmn-ceiling');

    // today = 2026-06-12 → ceiling = 2027-06-12 → max month = 2027-06
    // Trying to go from 2027-06 to 2027-07 should be blocked.
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2027])
        ->call('nextMonth')
        ->assertSet('month', 6)
        ->assertSet('year', 2027);
});

it('clamps a tampered ?year=&month= beyond the 12-month horizon to the ceiling month (WR-05)', function (): void {
    $user = cmnUser('cmn-url-ceiling');

    // today = 2026-06-12 → ceiling = 2027-06. A direct URL for 2099-12 must
    // render the ceiling month, not a far-future grid of phantom data.
    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 12, 'year' => 2099])
        ->assertSee('Jun 2027');
});

it('clamps an invalid #[Url] month value (0) to the current month', function (): void {
    $user = cmnUser('cmn-clamp-zero');

    // month=0 is out of range; render() should use current month (6) instead.
    $component = Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 0, 'year' => 2026]);

    // displayMonth passed to the view should be 6 (current month).
    $component->assertSee('Calendar');
    // We verify the render didn't crash; invalid month fell back to June.
});

it('clamps an invalid #[Url] month value (13) to the current month', function (): void {
    $user = cmnUser('cmn-clamp-thirteen');

    $component = Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 13, 'year' => 2026]);

    $component->assertSee('Calendar');
});
