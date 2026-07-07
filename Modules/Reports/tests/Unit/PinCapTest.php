<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Reports\Models\SavedReport;
use Modules\Reports\Public\Actions\SaveReport;
use Modules\Reports\Public\Actions\TogglePin;
use Modules\Reports\Public\Dto\ReportDefinition;

uses(RefreshDatabase::class);

/*
 * Pins TogglePin's 3-pin cap invariant (Req 10, T-999.6-21) — enforced in
 * the write-service layer, never trusted from the UI. Test names carry the
 * `pin_cap_enforced` token per 999.6-07-PLAN.md Task 2.
 */

function pctPinUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'pincap-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function pctPinDefinition(): ReportDefinition
{
    return new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'this_month',
        granularity: 'monthly',
        currencyMode: 'base',
        viz: 'table',
    );
}

it('pin_cap_enforced: pinning the 1st through 3rd reports all succeed', function (): void {
    $user = pctPinUser();
    test()->actingAs($user);

    $reports = [];
    for ($i = 1; $i <= 3; $i++) {
        $reports[] = app(SaveReport::class)->save($user, pctPinDefinition(), "Report {$i}");
    }

    foreach ($reports as $index => $report) {
        $pinned = app(TogglePin::class)->toggle($user, $report->id);
        expect($pinned->pinned)->toBeTrue();
        expect($pinned->pin_order)->toBe($index + 1);
    }

    expect(SavedReport::query()->where('pinned', true)->count())->toBe(3);
});

it('pin_cap_enforced: pinning a 4th report throws InvalidArgumentException with the exact copy', function (): void {
    $user = pctPinUser();
    test()->actingAs($user);

    $reports = [];
    for ($i = 1; $i <= 4; $i++) {
        $reports[] = app(SaveReport::class)->save($user, pctPinDefinition(), "Report {$i}");
    }

    foreach (array_slice($reports, 0, 3) as $report) {
        app(TogglePin::class)->toggle($user, $report->id);
    }

    $fourth = $reports[3];

    expect(fn () => app(TogglePin::class)->toggle($user, $fourth->id))
        ->toThrow(InvalidArgumentException::class, 'You can pin up to 3 reports. Unpin one to add this.');

    /** @var SavedReport $reloaded */
    $reloaded = SavedReport::query()->findOrFail($fourth->id);
    expect($reloaded->pinned)->toBeFalse();
});

it('pin_cap_enforced: unpinning one of 3 pinned reports frees a slot for a 4th', function (): void {
    $user = pctPinUser();
    test()->actingAs($user);

    $reports = [];
    for ($i = 1; $i <= 4; $i++) {
        $reports[] = app(SaveReport::class)->save($user, pctPinDefinition(), "Report {$i}");
    }

    foreach (array_slice($reports, 0, 3) as $report) {
        app(TogglePin::class)->toggle($user, $report->id);
    }

    // Unpin the first pinned report.
    $unpinned = app(TogglePin::class)->toggle($user, $reports[0]->id);
    expect($unpinned->pinned)->toBeFalse();
    expect($unpinned->pin_order)->toBeNull();

    // The 4th report can now be pinned.
    $pinnedFourth = app(TogglePin::class)->toggle($user, $reports[3]->id);
    expect($pinnedFourth->pinned)->toBeTrue();

    expect(SavedReport::query()->where('pinned', true)->count())->toBe(3);
});

it('pin_cap_enforced: unpinning compacts the remaining pin_order values to a dense sequence', function (): void {
    $user = pctPinUser();
    test()->actingAs($user);

    $reports = [];
    for ($i = 1; $i <= 3; $i++) {
        $reports[] = app(SaveReport::class)->save($user, pctPinDefinition(), "Report {$i}");
        app(TogglePin::class)->toggle($user, $reports[$i - 1]->id);
    }

    // Unpin the middle (pin_order = 2) report — the 3rd should compact down to 2.
    app(TogglePin::class)->toggle($user, $reports[1]->id);

    /** @var SavedReport $first */
    $first = SavedReport::query()->findOrFail($reports[0]->id);
    /** @var SavedReport $third */
    $third = SavedReport::query()->findOrFail($reports[2]->id);

    expect($first->pin_order)->toBe(1);
    expect($third->pin_order)->toBe(2);
});
