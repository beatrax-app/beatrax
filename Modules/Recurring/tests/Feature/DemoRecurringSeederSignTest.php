<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

uses(RefreshDatabase::class);

function demoSeededUser(): User
{
    return User::query()->where('username', 'demo-1')->firstOrFail();
}

it('seeds every demo expense series with the signed amount the detector writes', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = demoSeededUser();
    $this->actingAs($user);

    $series = app(RecurringSeriesQuery::class)->allApprovedForUser($user);

    expect($series)->not->toBeEmpty();

    foreach ($series as $dto) {
        if ($dto->direction !== Direction::Expense->value) {
            continue;
        }

        expect($dto->latestAmount->toMinor())
            ->toBeLessThan(0, "{$dto->detectedName} stores an unsigned expense amount");
        expect($dto->monthlyEquivalent->toMinor())
            ->toBeLessThan(0, "{$dto->detectedName} stores an unsigned monthly equivalent");
    }
});

it('reports the demo fixed-payment month as a net loss', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = demoSeededUser();
    $this->actingAs($user);

    $totals = app(FixedPaymentsViewQuery::class)->monthlyEquivalentTotals($user);

    expect($totals->expense->toMinor())->toBeLessThan(0);
    expect($totals->net->toMinor())->toBeLessThan(0);
});
