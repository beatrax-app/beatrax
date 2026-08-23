<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\RangeProjector;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

uses(RefreshDatabase::class);

// The envelope tier takes its sign from latest_amount_minor, so a series
// persisted as an unsigned magnitude projects a bill as income and the
// balance line climbs on every due date.
// @link ../../../../.docs/features/forecasting/architecture.md#range-projection-tiers

it('projects every seeded expense as a fall in the balance', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1@beatrax.local')->firstOrFail();
    $this->actingAs($user);

    $expenses = array_values(array_filter(
        app(RecurringSeriesQuery::class)->allApprovedForUser($user),
        static fn (RecurringSeriesDto $dto): bool => $dto->direction === Direction::Expense->value,
    ));

    expect($expenses)->not->toBeEmpty();

    $projector = app(RangeProjector::class);

    foreach ($expenses as $dto) {
        $contributions = $projector->envelope($dto, 1, CarbonImmutable::today(), 400, $user);

        expect($contributions)->not->toBeEmpty("{$dto->detectedName} projects nothing over a year");

        foreach ($contributions as $contribution) {
            expect($contribution->pointMinor)
                ->toBeLessThan(0, "{$dto->detectedName} projects an expense as income");
        }
    }
});
