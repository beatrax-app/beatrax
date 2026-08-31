<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Models\Goal;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'goal-owner',
        'password' => 'x',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

// The tick sits between the pencil and the box in the phone row, and the row
// hides it the moment it lands — so a mis-tap finished the goal, took its
// progress bar and its target date off the screen, and left nothing that
// undid it. Archive, one button along, has always handed back a way out.
it('hands back the way out after a goal is marked complete', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    Livewire::test(GoalsPage::class)
        ->call('markComplete', $goal->id)
        ->assertDispatched('toast', undoAction: 'restore', undoPayload: $goal->id);

    $this->assertDatabaseHas('goals', ['id' => $goal->id, 'status' => 'completed']);
});

it('puts the goal back where it was when that way out is taken', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    Livewire::test(GoalsPage::class)
        ->call('markComplete', $goal->id)
        ->call('restore', $goal->id);

    $this->assertDatabaseHas('goals', ['id' => $goal->id, 'status' => 'active']);
});
