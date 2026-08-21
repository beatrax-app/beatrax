<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Goals\Public\Dto\GoalProgressRow;
use Modules\Goals\Public\Services\GoalProgressQuery;

uses(RefreshDatabase::class);

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

    // A goal reads only what was attributed to it, so a 600,00 target can no
    // longer be swamped by a month's salary.
    expect($rows['Winter tyres']->contributedMinor)->toBeGreaterThan(0);
    expect($rows['Winter tyres']->contributedMinor)->toBeLessThanOrEqual($rows['Winter tyres']->targetMinor);

    // 125000 is the seeded Emergency fund pot: a pot-backed goal still reads it.
    expect($rows['Emergency fund']->contributedMinor)->toBe(125000);
});
