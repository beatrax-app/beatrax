<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Reports\Models\SavedReport;
use Modules\Reports\Public\Actions\SaveReport;
use Modules\Reports\Public\Actions\TogglePin;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Enums\ReportGranularity;

uses(RefreshDatabase::class);

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
        granularity: ReportGranularity::Monthly,
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

    $unpinned = app(TogglePin::class)->toggle($user, $reports[0]->id);
    expect($unpinned->pinned)->toBeFalse();
    expect($unpinned->pin_order)->toBeNull();

    $pinnedFourth = app(TogglePin::class)->toggle($user, $reports[3]->id);
    expect($pinnedFourth->pinned)->toBeTrue();

    expect(SavedReport::query()->where('pinned', true)->count())->toBe(3);
});

it('pin_cap_enforced: WR-01 the pinned-count cap check runs inside the write transaction, not before it opens', function (): void {
    $user = pctPinUser();
    test()->actingAs($user);

    $report = app(SaveReport::class)->save($user, pctPinDefinition(), 'Report 1');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $sawCountQuery = false;
    $countQueryRanInsideTransaction = false;

    DB::listen(function ($query) use (&$sawCountQuery, &$countQueryRanInsideTransaction, $db): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'count(*)') && str_contains($sql, 'saved_reports') && str_contains($sql, 'pinned')) {
            $sawCountQuery = true;
            // The count has to run inside the write transaction: a second writer
            // blocked on SQLite's write lock must re-read it on resume rather
            // than trust a pre-transaction snapshot.
            $countQueryRanInsideTransaction = $db->connection()->transactionLevel() > 0;
        }
    });

    app(TogglePin::class)->toggle($user, $report->id);

    expect($sawCountQuery)->toBeTrue();
    expect($countQueryRanInsideTransaction)->toBeTrue();
});

it('pin_cap_enforced: unpinning compacts the remaining pin_order values to a dense sequence', function (): void {
    $user = pctPinUser();
    test()->actingAs($user);

    $reports = [];
    for ($i = 1; $i <= 3; $i++) {
        $reports[] = app(SaveReport::class)->save($user, pctPinDefinition(), "Report {$i}");
        app(TogglePin::class)->toggle($user, $reports[$i - 1]->id);
    }

    app(TogglePin::class)->toggle($user, $reports[1]->id);

    /** @var SavedReport $first */
    $first = SavedReport::query()->findOrFail($reports[0]->id);
    /** @var SavedReport $third */
    $third = SavedReport::query()->findOrFail($reports[2]->id);

    expect($first->pin_order)->toBe(1);
    expect($third->pin_order)->toBe(2);
});
