<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;

// The month view used to be a flat div grid whose week rows were only
// `display: contents` wrappers carrying role="row" — the construct screen
// readers drop rows from. These assertions exist because the two versions
// render identically, so nothing else would catch a regression back to divs.
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

// June 2026 starts on a Monday and has 30 days: exactly five Mon–Sun weeks
// with no spill, so six <tr> counting the header row and 35 <td>.
it('groups the day cells into one row per week', function (): void {
    expect(substr_count($this->html, '<tr'))->toBe(6)
        ->and(substr_count($this->html, '<td'))->toBe(35);
});

// role="grid" survives the native elements: it is what makes this an
// interactive widget rather than a data table, and under ARIA-in-HTML it maps
// the tr/th/td onto row/columnheader/gridcell.
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
