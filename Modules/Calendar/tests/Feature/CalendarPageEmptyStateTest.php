<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Calendar\Tests\TestCase;
use Modules\Core\Models\User;

uses(TestCase::class, RefreshDatabase::class);

/*
 * CalendarPage — empty state when no approved series exist.
 *
 * RED state (Phase 6 Plan 01): CalendarPage does not yet call CalendarQuery;
 * these tests will fail until Plan 02/03.
 *
 * Contract being tested:
 *   - When the user has no approved recurring series, the calendar renders
 *     the "No upcoming payments" empty-state copy.
 *   - The empty state includes a CTA link to /recurring/review so the user
 *     can approve recurring patterns and populate the calendar.
 */

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

    // No series seeded — the calendar should show the empty state

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
