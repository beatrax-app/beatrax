<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Tests\Helpers\LivewireRoundTrip;

uses(RefreshDatabase::class);

// editGoalId names the row updateGoal() saves the form over, and the Blade only
// reads it — openEdit() is the sole writer, and it refuses a goal the reader
// does not own. Unlocked, opening goal A's sheet and naming goal B in the same
// payload wrote A's name, amount and date over B, on a screen still headed with
// A's. PotsPage::$editPotId is the same shape with the opposite owner: the
// sheet writes it with $wire.set, so locking that one would break the page.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'goal-edit-lock',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->onScreen = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'On screen',
        'target_minor' => 100000,
        'status' => 'active',
    ]);
    $this->neighbour = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Neighbour',
        'target_minor' => 500000,
        'status' => 'active',
    ]);
});

function goalsPageSnapshot(): string
{
    return LivewireRoundTrip::snapshotFor(
        (string) test()->get('/goals')->assertOk()->getContent(),
        'goals.goals-page',
    );
}

it('refuses a payload that moves the save onto a second goal', function (): void {
    LivewireRoundTrip::tamper(
        $this,
        goalsPageSnapshot(),
        [
            'editGoalId' => $this->neighbour->id,
            'name' => 'Overwritten',
            'targetAmount' => '10,00',
            'targetDate' => '2027-01-01',
        ],
        [['path' => '', 'method' => 'updateGoal', 'params' => []]],
    )->assertForbidden();

    $this->assertDatabaseHas('goals', ['id' => $this->neighbour->id, 'name' => 'Neighbour', 'target_minor' => 500000]);
    $this->assertDatabaseHas('goals', ['id' => $this->onScreen->id, 'name' => 'On screen']);
});

it('still saves the goal openEdit put in the form', function (): void {
    $snapshot = goalsPageSnapshot();

    $edited = LivewireRoundTrip::tamper(
        $this,
        $snapshot,
        [],
        [['path' => '', 'method' => 'openEdit', 'params' => [$this->onScreen->id]]],
    )->assertOk();

    $carried = $edited->json('components.0.snapshot');
    expect($carried)->toBeString();

    LivewireRoundTrip::tamper(
        $this,
        (string) $carried,
        ['name' => 'Renamed', 'targetAmount' => '10,00', 'targetDate' => '2027-01-01'],
        [['path' => '', 'method' => 'updateGoal', 'params' => []]],
    )->assertOk();

    $this->assertDatabaseHas('goals', ['id' => $this->onScreen->id, 'name' => 'Renamed', 'target_minor' => 1000]);
    $this->assertDatabaseHas('goals', ['id' => $this->neighbour->id, 'name' => 'Neighbour']);
});
