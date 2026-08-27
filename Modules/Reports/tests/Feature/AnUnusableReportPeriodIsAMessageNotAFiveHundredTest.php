<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;
use Modules\Reports\Internal\Support\ReportDefinitionFactory;
use Modules\Reports\Public\Http\Livewire\PinnedReportsRow;
use Symfony\Component\HttpFoundation\Response;

// Editing a two-date picker passes through inverted and half-typed states as a
// matter of course, and each of them reached PeriodPresetResolver and threw an
// HTML error page, losing the composition. A stored definition is worse: one
// unreadable saved_reports.definition row took /reports down, and the dashboard
// with it if the report was pinned.

function aurpUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'aurp-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function aurpSaveDefinition(User $user, string $definitionJson, bool $pinned = false): int
{
    return app(DatabaseManager::class)->connection()->table('saved_reports')->insertGetId([
        'user_id' => $user->id,
        'name' => 'From a peer on another build',
        'definition' => $definitionJson,
        'pinned' => $pinned,
        'pin_order' => $pinned ? 1 : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('tells the reader the end date is before the start date instead of crashing the page', function (): void {
    test()->actingAs(aurpUser());

    Livewire::test(ReportBuilder::class)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-06-01')
        ->set('customTo', '2026-08-31')
        ->set('customFrom', '2026-09-30')
        ->assertOk()
        ->assertSee('The end date falls before the start date.');
});

it('keeps the rest of the composition while the range is unusable', function (): void {
    test()->actingAs(aurpUser());

    Livewire::test(ReportBuilder::class)
        ->set('metric', 'income')
        ->set('viz', 'bar')
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-08-31')
        ->set('customTo', '2026-08-01')
        ->assertOk()
        ->assertSet('metric', 'income')
        ->assertSet('viz', 'bar')
        ->assertSet('customFrom', '2026-08-31');
});

it('names a date that is not a date rather than resolving it to one that is', function (): void {
    test()->actingAs(aurpUser());

    Livewire::test(ReportBuilder::class)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-02-30')
        ->set('customTo', '2026-03-31')
        ->assertOk()
        ->assertSee('Use a valid date in YYYY-MM-DD form.');
});

it('answers the export route with a reason when the custom range carries no dates', function (): void {
    $user = aurpUser();
    test()->actingAs($user);

    $response = test()->get('/reports/export?period=custom');

    expect($response->status())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->and($response->getContent())->toContain('Pick both a start and an end date.');
});

it('answers the export route with a reason when the custom range is inverted', function (): void {
    test()->actingAs(aurpUser());

    $response = test()->get('/reports/export?period=custom&from=2026-08-31&to=2026-08-01');

    expect($response->status())->toBe(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->and($response->getContent())->toContain('The end date falls before the start date.');
});

it('refuses the builder own export with a message rather than streaming a truncated file', function (): void {
    test()->actingAs(aurpUser());

    Livewire::test(ReportBuilder::class)
        ->set('periodPreset', 'custom')
        ->set('customFrom', '2026-08-31')
        ->set('customTo', '2026-08-01')
        ->call('export')
        ->assertOk()
        ->assertSet('flashMessage', 'The end date falls before the start date.');
});

it('opens a saved report whose stored definition this build cannot read', function (string $definitionJson): void {
    $user = aurpUser();
    test()->actingAs($user);
    $id = aurpSaveDefinition($user, $definitionJson);

    Livewire::test(ReportBuilder::class, ['report' => $id])->assertOk();

    test()->get('/reports?report='.$id)->assertOk();
})->with([
    'an empty object' => ['{}'],
    'a granularity this build does not know' => ['{"metric":"spend","dimension":"category","periodPreset":"this_month","granularity":"daily","currencyMode":"base","viz":"table"}'],
    'a customFrom that is not a date' => ['{"metric":"spend","dimension":"category","periodPreset":"custom","granularity":"monthly","currencyMode":"base","viz":"table","customFrom":"nope","customTo":"2026-08-31"}'],
    'a definition that is not JSON at all' => ['not json'],
]);

it('keeps the dashboard up when a pinned report carries an unusable range', function (): void {
    $user = aurpUser();
    test()->actingAs($user);
    aurpSaveDefinition(
        $user,
        '{"metric":"spend","dimension":"category","periodPreset":"custom","granularity":"monthly","currencyMode":"base","viz":"donut","customFrom":"nope","customTo":"nope"}',
        pinned: true,
    );

    Livewire::test(PinnedReportsRow::class)
        ->assertOk()
        ->assertSee('From a peer on another build');
});

it('falls back to the vocabulary this build knows rather than refusing the whole row', function (): void {
    $definition = ReportDefinitionFactory::fromStored('{"metric":"whatever","dimension":"planet","periodPreset":"custom","granularity":"daily","currencyMode":"bitcoin","viz":"sankey","customFrom":"2026-02-30","accounts":["7","nope",-3]}');

    expect($definition->metric)->toBe('spend')
        ->and($definition->dimension)->toBe('category')
        ->and($definition->currencyMode)->toBe('base')
        ->and($definition->viz)->toBe('table')
        ->and($definition->granularity->value)->toBe('monthly')
        ->and($definition->customFrom)->toBeNull()
        ->and($definition->accounts)->toBe([7]);
});
