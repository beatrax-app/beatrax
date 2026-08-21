<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Reports\Internal\Actions\DeleteReport;
use Modules\Reports\Internal\Actions\SaveReport;
use Modules\Reports\Internal\Actions\TogglePin;
use Modules\Reports\Internal\Actions\UpdateReport;
use Modules\Reports\Internal\Dto\ReportDefinition;
use Modules\Reports\Internal\Enums\ReportGranularity;
use Modules\Reports\Models\SavedReport;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

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
        granularity: ReportGranularity::Monthly,
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

it('UpdateReport is a no-op when only the filter-array element order changed', function (): void {
    $user = srwaUser();
    test()->actingAs($user);

    $original = new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'this_month',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        accounts: [1, 2, 3],
        categories: [10, 20],
        counterparties: [100, 200, 300],
    );

    $saved = app(SaveReport::class)->save($user, $original, 'Report with filters');
    $originalUpdatedAt = $saved->fresh()?->updated_at;

    // Same filter *sets*, reordered — semantically unchanged.
    $reordered = new ReportDefinition(
        metric: 'spend',
        dimension: 'category',
        periodPreset: 'this_month',
        granularity: ReportGranularity::Monthly,
        currencyMode: 'base',
        viz: 'table',
        accounts: [3, 1, 2],
        categories: [20, 10],
        counterparties: [300, 100, 200],
    );

    app(UpdateReport::class)->update($user, $saved->id, $reordered, 'Report with filters');

    /** @var SavedReport $reloaded */
    $reloaded = SavedReport::query()->findOrFail($saved->id);
    expect($reloaded->updated_at?->equalTo($originalUpdatedAt))->toBeTrue();
    // Normalisation affects the dirty comparison only, never the stored value.
    expect($reloaded->definition['accounts'])->toBe([1, 2, 3]);
});

it('UpdateReport throws NotFoundHttpException for a foreign report id', function (): void {
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

it('DeleteReport throws NotFoundHttpException for a foreign report id and never deletes it', function (): void {
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

it('deleting a pinned report compacts the remaining pin_order values to a dense sequence', function (): void {
    $user = srwaUser();
    test()->actingAs($user);

    $reports = [];
    for ($i = 1; $i <= 3; $i++) {
        $reports[] = app(SaveReport::class)->save($user, srwaDefinition(), "Report {$i}");
        app(TogglePin::class)->toggle($user, $reports[$i - 1]->id);
    }

    // Deleted directly rather than through TogglePin's unpin path.
    app(DeleteReport::class)->delete($user, $reports[1]->id);

    /** @var SavedReport $first */
    $first = SavedReport::query()->findOrFail($reports[0]->id);
    /** @var SavedReport $third */
    $third = SavedReport::query()->findOrFail($reports[2]->id);

    expect($first->pin_order)->toBe(1);
    expect($third->pin_order)->toBe(2);
    expect(SavedReport::query()->where('pinned', true)->count())->toBe(2);
});

it('DeleteReport does not touch pin_order compaction when the deleted report was not pinned', function (): void {
    $user = srwaUser();
    test()->actingAs($user);

    $pinned = app(SaveReport::class)->save($user, srwaDefinition(), 'Pinned report');
    app(TogglePin::class)->toggle($user, $pinned->id);

    $unpinned = app(SaveReport::class)->save($user, srwaDefinition(), 'Unpinned report');
    app(DeleteReport::class)->delete($user, $unpinned->id);

    /** @var SavedReport $reloaded */
    $reloaded = SavedReport::query()->findOrFail($pinned->id);
    expect($reloaded->pinned)->toBeTrue();
    expect($reloaded->pin_order)->toBe(1);
});
