<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Reports\Models\SavedReport;
use Modules\Reports\Public\Actions\SaveReport;
use Modules\Reports\Public\Dto\ReportDefinition;

uses(RefreshDatabase::class);

/*
 * Wave 0 RED stub (999.6-03 Task 2, Req 4/9).
 *
 * Pins Modules\Reports\Public\Actions\SaveReport::save(User, ReportDefinition,
 * string $name): SavedReport (PATTERNS.md transaction-closes-before-event-
 * dispatch shape, mirrors EnvelopeWriter::setOverspendMode()). Saving a
 * report persists `$definition->toArray()` into `saved_reports.definition`
 * (already-existing table + model, Plan 01); reopening it via
 * `ReportDefinition::from($row->definition)` MUST round-trip every field
 * losslessly, INCLUDING `currencyMode` (base vs original) — a saved report
 * must reopen in the currency mode it was saved with (Req 4).
 *
 * RED as intended: SaveReport does not exist yet — every test below fails
 * on `app(SaveReport::class)` (missing class), not on the (already-existing)
 * ReportDefinition DTO or `saved_reports` table (Plan 01) it's built from.
 */

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
        granularity: 'weekly',
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
        granularity: 'monthly',
        currencyMode: 'base',
        viz: 'bar',
    );

    $saved = app(SaveReport::class)->save($user, $definition, 'Monthly spend by category');

    /** @var SavedReport $reloaded */
    $reloaded = SavedReport::query()->findOrFail($saved->id);
    $reopened = ReportDefinition::from($reloaded->definition);

    expect($reopened->currencyMode)->toBe('base');
});
