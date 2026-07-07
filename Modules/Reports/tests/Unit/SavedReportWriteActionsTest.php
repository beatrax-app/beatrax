<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Reports\Models\SavedReport;
use Modules\Reports\Public\Actions\DeleteReport;
use Modules\Reports\Public\Actions\SaveReport;
use Modules\Reports\Public\Actions\UpdateReport;
use Modules\Reports\Public\Dto\ReportDefinition;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/*
 * Companion coverage (Rule 2, following the 999.6-06 SUMMARY precedent of
 * adding tests the plan's own acceptance criteria require but its named
 * <verify> command does not directly exercise): SavedReportRoundTripTest
 * pins SaveReport's round-trip contract; this file pins UpdateReport's
 * dirty-field-only emit + NotFoundHttpException, DeleteReport's
 * NotFoundHttpException + row removal, and cross-user isolation for both —
 * every claim in Task 1's acceptance_criteria beyond the save round trip.
 */

function srwaUser(): User
{
    /** @var User */
    return User::query()->create([
        'username' => 'srwa-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function srwaDefinition(): ReportDefinition
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

it('UpdateReport writes only the genuinely-changed fields and renames the report', function (): void {
    $user = srwaUser();
    test()->actingAs($user);

    $saved = app(SaveReport::class)->save($user, srwaDefinition(), 'Original name');

    $updated = app(UpdateReport::class)->update($user, $saved->id, srwaDefinition(), 'Renamed report');

    expect($updated->name)->toBe('Renamed report');

    /** @var SavedReport $reloaded */
    $reloaded = SavedReport::query()->findOrFail($saved->id);
    expect($reloaded->name)->toBe('Renamed report');
});

it('UpdateReport is a no-op when nothing actually changed', function (): void {
    $user = srwaUser();
    test()->actingAs($user);

    $definition = srwaDefinition();
    $saved = app(SaveReport::class)->save($user, $definition, 'Same name');
    $originalUpdatedAt = $saved->fresh()?->updated_at;

    app(UpdateReport::class)->update($user, $saved->id, $definition, 'Same name');

    /** @var SavedReport $reloaded */
    $reloaded = SavedReport::query()->findOrFail($saved->id);
    expect($reloaded->updated_at?->equalTo($originalUpdatedAt))->toBeTrue();
});

it('UpdateReport throws NotFoundHttpException for a foreign report id (T-999.6-17)', function (): void {
    $owner = srwaUser();
    $other = srwaUser();
    test()->actingAs($owner);
    $saved = app(SaveReport::class)->save($owner, srwaDefinition(), 'Owner report');

    expect(fn () => app(UpdateReport::class)->update($other, $saved->id, srwaDefinition(), 'Hijacked'))
        ->toThrow(NotFoundHttpException::class);
});

it('UpdateReport throws NotFoundHttpException for a missing report id', function (): void {
    $user = srwaUser();

    expect(fn () => app(UpdateReport::class)->update($user, 999_999, srwaDefinition(), 'Ghost'))
        ->toThrow(NotFoundHttpException::class);
});

it('DeleteReport removes the row', function (): void {
    $user = srwaUser();
    test()->actingAs($user);
    $saved = app(SaveReport::class)->save($user, srwaDefinition(), 'To be deleted');

    app(DeleteReport::class)->delete($user, $saved->id);

    expect(SavedReport::query()->find($saved->id))->toBeNull();
});

it('DeleteReport throws NotFoundHttpException for a foreign report id and never deletes it (T-999.6-17)', function (): void {
    $owner = srwaUser();
    $other = srwaUser();
    test()->actingAs($owner);
    $saved = app(SaveReport::class)->save($owner, srwaDefinition(), 'Owner report');

    expect(fn () => app(DeleteReport::class)->delete($other, $saved->id))
        ->toThrow(NotFoundHttpException::class);

    expect(SavedReport::query()->withoutGlobalScopes()->find($saved->id))->not->toBeNull();
});

it('DeleteReport throws NotFoundHttpException for a missing report id', function (): void {
    $user = srwaUser();

    expect(fn () => app(DeleteReport::class)->delete($user, 999_999))
        ->toThrow(NotFoundHttpException::class);
});
