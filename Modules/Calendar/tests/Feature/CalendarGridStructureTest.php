<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;

/**
 * The calendar grid's markup, as accessibility depends on it.
 *
 * The month view used to be a flat div grid where the week rows existed only
 * as `display: contents` wrappers carrying role="row" — the construct screen
 * readers have historically dropped rows from, leaving the cells with no
 * position. It is a real table now. These assertions exist because that is
 * invisible from the rendered page: the two versions look identical, so
 * nothing else would notice a regression back to divs.
 */
function cgsUser(): User
{
    return User::query()->create([
        'username' => 'cgs-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
    $this->html = Livewire::actingAs(cgsUser())
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->html();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('builds the month view from real table elements', function (): void {
    expect($this->html)
        ->toContain('<table')
        ->toContain('<thead>')
        ->toContain('<tbody>');
});

// June 2026 starts on a Monday and has 30 days, so the grid is exactly five
// Mon–Sun weeks with no leading or trailing spill. Any other row count means
// the weeks stopped being rows.
it('groups the day cells into one row per week', function (): void {
    expect(substr_count($this->html, '<tr'))->toBe(6)
        ->and(substr_count($this->html, '<td'))->toBe(35);
});

// The native elements replace the roles, but role="grid" stays: it is what
// makes this an interactive widget rather than a data table, and under ARIA
// in HTML it also gives the tr/th/td their row/columnheader/gridcell mapping.
// Dropping it would silently downgrade the whole grid.
it('keeps the grid role and drops the roles the elements now imply', function (): void {
    expect($this->html)
        ->toContain('role="grid"')
        ->not->toContain('role="row"')
        ->not->toContain('role="gridcell"')
        ->not->toContain('role="columnheader"');
});

it('marks the weekday headings as column headers', function (): void {
    expect(substr_count($this->html, 'scope="col"'))->toBe(7);
});
