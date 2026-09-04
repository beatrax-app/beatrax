<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Calendar\Internal\Services\CalendarMonthWindow;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;

const CHF_TODAY = '2026-06-12';

function chfUser(string $suffix): User
{
    return User::query()->create([
        'username' => $suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function chfFloorMonth(): CarbonImmutable
{
    return CarbonImmutable::parse(CHF_TODAY)->startOfMonth()->subMonths(CalendarMonthWindow::HISTORY_MONTHS);
}

// The nav buttons are the only two <button> elements carrying a wire:click of
// their own, so the opening tag is addressable without depending on the classes.
function chfNavButton(string $html, string $action): string
{
    $matches = PatternScan::first('~<button\s[^>]*wire:click="'.$action.'"[^>]*>~', $html);

    return $matches[0] ?? '';
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CHF_TODAY.' 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

// nextMonth() refuses to step past the horizon; prevMonth() stepped freely, and
// the display clamp then rejected the year it produced and fell back to the
// current one — so paging back far enough landed the reader on today's year.
it('stops at the history floor instead of teleporting to the current year', function (): void {
    $floor = chfFloorMonth();

    Livewire::actingAs(chfUser('chf-floor-step'))
        ->test(CalendarPage::class, ['month' => $floor->month, 'year' => $floor->year])
        ->call('prevMonth')
        ->assertSet('month', $floor->month)
        ->assertSet('year', $floor->year);
});

it('still steps back normally one month above the floor', function (): void {
    $floor = chfFloorMonth();
    $aboveFloor = $floor->addMonth();

    Livewire::actingAs(chfUser('chf-above-floor'))
        ->test(CalendarPage::class, ['month' => $aboveFloor->month, 'year' => $aboveFloor->year])
        ->call('prevMonth')
        ->assertSet('month', $floor->month)
        ->assertSet('year', $floor->year);
});

// The ceiling clamps a tampered query string to the ceiling month. Behind the
// floor the year guard silently rendered the current month instead.
it('clamps a tampered ?year=&month= behind the floor to the floor month', function (): void {
    Livewire::actingAs(chfUser('chf-url-floor'))
        ->test(CalendarPage::class, ['month' => 1, 'year' => 1990])
        ->assertSee(chfFloorMonth()->translatedFormat('M Y'))
        ->assertDontSee('Jun 2026');
});

it('marks the previous-month control disabled at the floor, as the next one is at the ceiling', function (): void {
    $floor = chfFloorMonth();

    $html = Livewire::actingAs(chfUser('chf-floor-button'))
        ->test(CalendarPage::class, ['month' => $floor->month, 'year' => $floor->year])
        ->html();

    expect(chfNavButton($html, 'prevMonth'))->toContain('aria-disabled="true"')
        ->and(chfNavButton($html, 'nextMonth'))->not->toContain('aria-disabled');
});

it('leaves the previous-month control live above the floor', function (): void {
    $html = Livewire::actingAs(chfUser('chf-live-button'))
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->html();

    expect(chfNavButton($html, 'prevMonth'))->not->toContain('aria-disabled');
});
