<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;
use Tests\Helpers\LivewireRoundTrip;

uses(RefreshDatabase::class);

// seriesId arrives as a route segment, which is exactly the surface
// TamperedUrlParameterContractTest does not cover: that test drives #[Url]
// properties, and a route segment is neither one of those nor re-read after
// mount. Unlocked, editVarianceTolerance() wrote series 9 while the address
// bar still read /recurring/series/5.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'series-detail-lock',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->onScreen = recurringSeriesForToleranceLock($this->user, 'On screen');
    $this->neighbour = recurringSeriesForToleranceLock($this->user, 'Neighbour');
});

function recurringSeriesForToleranceLock(User $user, string $name): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'tolerance-lock::'.bin2hex(random_bytes(4)),
    ]);
}

function seriesDetailSnapshot(int $seriesId): string
{
    return LivewireRoundTrip::snapshotFor(
        (string) test()->get('/recurring/series/'.$seriesId)->assertOk()->getContent(),
        'recurring.recurring-series-detail-page',
    );
}

function toleranceOf(int $seriesId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (int) $db->connection()->table('recurring_series')->where('id', $seriesId)->value('variance_tolerance_percent');
}

it('refuses a payload that moves the tolerance write onto a second series', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        seriesDetailSnapshot($this->onScreen->id),
        ['seriesId' => $this->neighbour->id],
        [['path' => '', 'method' => 'editVarianceTolerance', 'params' => [50]]],
    )->assertForbidden();

    expect(toleranceOf($this->neighbour->id))->toBe(25)
        ->and(toleranceOf($this->onScreen->id))->toBe(25);
});

it('still writes the tolerance of the series the address bar names', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        seriesDetailSnapshot($this->onScreen->id),
        [],
        [['path' => '', 'method' => 'editVarianceTolerance', 'params' => [50]]],
    )->assertOk();

    expect(toleranceOf($this->onScreen->id))->toBe(50)
        ->and(toleranceOf($this->neighbour->id))->toBe(25);
});
