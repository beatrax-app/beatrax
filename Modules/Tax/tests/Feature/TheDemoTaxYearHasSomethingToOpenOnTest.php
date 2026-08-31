<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Tax\Internal\Services\TaxYearQuery;
use Modules\Tax\Internal\Support\FilingSeason;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// January through April are still spent filing last year's return, so /tax
// opens on year - 1. Every demo tag sat inside the 90-day window, and for four
// months of the year that window holds no part of the year the page opens on.

it('opens on a year with items whenever the reader is in filing season', function (string $now): void {
    CarbonImmutable::setTestNow($now);

    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1')->firstOrFail();
    $this->actingAs($user);

    $year = FilingSeason::defaultYear(app(Clock::class)->now());

    expect(app(TaxYearQuery::class)->forUser($user->id, $year)->itemCount)
        ->toBeGreaterThan(0, "/tax opens on {$year} with nothing to show");
})->with([
    '2027-01-14 09:00:00',
    '2027-02-14 09:00:00',
    '2027-03-14 09:00:00',
    '2027-04-14 09:00:00',
]);

it('offers the previous calendar year in the switcher', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1')->firstOrFail();
    $this->actingAs($user);

    expect(app(TaxYearQuery::class)->availableYears($user->id))
        ->toContain(CarbonImmutable::today()->year - 1);
});
