<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Actions\SaveReport;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Models\SavedReport;
use Spatie\LaravelData\Exceptions\CannotCastEnum;

uses(RefreshDatabase::class);

function srrtUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'srrt-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

it('saves and reopens a full report definition losslessly, including currencyMode', function (): void {
    $user = srrtUser();
    test()->actingAs($user);

    $definition = new ReportDefinition(
        metric: 'income',
        dimension: 'counterparty',
        periodPreset: 'last_3_months',
        granularity: ReportGranularity::Weekly,
        currencyMode: 'original',
        viz: 'donut',
        compare: true,
        counterparties: [1, 2],
        amountMin: '10.00',
        amountDirection: 'in',
    );

    $saved = app(SaveReport::class)->save($user, $definition, 'My weekly income');

    /** @var SavedReport $reloaded */
    $reloaded = SavedReport::query()->findOrFail($saved->id);
    $reopened = ReportDefinition::from($reloaded->definition);

    expect($reopened->toArray())->toBe($definition->toArray());
    expect($reopened->currencyMode)->toBe('original');
    expect($reloaded->name)->toBe('My weekly income');
});

it('reopens a saved report with currencyMode=base exactly as it was saved', function (): void {
    $user = srrtUser();
    test()->actingAs($user);

    $definition = new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'this_month',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'bar',
    );

    $saved = app(SaveReport::class)->save($user, $definition, 'Monthly spend by category');

    /** @var SavedReport $reloaded */
    $reloaded = SavedReport::query()->findOrFail($saved->id);
    $reopened = ReportDefinition::from($reloaded->definition);

    expect($reopened->currencyMode)->toBe('base');
});

// Sync replicates saved reports between devices, so a definition can arrive from
// a build whose vocabulary is not this one's. Reading an unknown granularity as
// monthly would show a different report under the name the user gave it.
it('refuses to reopen a saved report whose stored granularity is not in the vocabulary', function (): void {
    $user = srrtUser();
    test()->actingAs($user);

    $saved = app(SaveReport::class)->save($user, new ReportDefinition(
        metric: 'spend',
        dimension: 'time_bucket',
        periodPreset: 'this_year',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'line',
    ), 'Tampered');

    /** @var SavedReport $reloaded */
    $reloaded = SavedReport::query()->findOrFail($saved->id);
    $payload = $reloaded->definition;
    $payload['granularity'] = 'quarterly';

    expect(fn () => ReportDefinition::from($payload))->toThrow(CannotCastEnum::class);
});

// Quarterly is reachable as a widening outcome but is not a granularity the user
// can ask for; the widening prose names it, so the distinction is pinned here.
it('does not admit quarterly as a granularity the user can choose', function (): void {
    expect(ReportGranularity::tryFrom('quarterly'))->toBeNull()
        ->and(array_map(
            static fn (ReportGranularity $case): string => $case->value,
            ReportGranularity::cases(),
        ))->toBe(['monthly', 'weekly']);
});
