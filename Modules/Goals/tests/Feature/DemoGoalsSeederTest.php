<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Dto\GoalProgressRow;
use Modules\Goals\Public\Services\GoalProgressQuery;

uses(RefreshDatabase::class);

/*
 * The demo install has to stay coherent under the attribution model: the
 * pot-backed goals read their pot, and the one goal without a pot reads the
 * credits the seeder attributed to it — never an account-wide sum that could
 * overshoot its own target.
 */

/** @return array<string, GoalProgressRow> */
function demoGoalRowsByName(User $user): array
{
    $rows = app(GoalProgressQuery::class)->forUser($user);
    $byName = [];
    foreach ($rows as $row) {
        $byName[$row->name] = $row;
    }

    return $byName;
}

it('seeds a pot-less demo goal whose progress comes from attributed transactions', function (): void {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::query()->where('username', 'demo-1@beatrax.local')->firstOrFail();
    $this->actingAs($user);

    $tyres = Goal::query()
        ->withoutGlobalScopes()
        ->where('user_id', $user->id)
        ->where('name', 'Winter tyres')
        ->firstOrFail();

    $attributions = DB::table('goal_contributions')
        ->where('user_id', $user->id)
        ->where('goal_id', $tyres->id)
        ->count();

    expect($attributions)->toBeGreaterThan(0);

    $rows = demoGoalRowsByName($user);

    // The whole point of the change: a goal reads only what was attributed to
    // it, so a 600,00 target can no longer be swamped by a month's salary.
    expect($rows['Winter tyres']->contributedMinor)->toBeGreaterThan(0);
    expect($rows['Winter tyres']->contributedMinor)->toBeLessThanOrEqual($rows['Winter tyres']->targetMinor);

    // A pot-backed goal still reads its pot, untouched by the change.
    expect($rows['Emergency fund']->contributedMinor)->toBe(125000);
});
