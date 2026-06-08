<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Models\Account;

/**
 * Wave 0 RED stubs for GOAL-01 and GOAL-05.
 *
 * These tests reference GoalsPage (Plan 04) and GoalWriter (Plan 03). They
 * will error with "class not found" until Plans 03 and 04 land — that is
 * correct Wave 0 RED behaviour.
 *
 * Covers:
 *   GOAL-01: createGoal writes a goals row and dispatches toast; invalid
 *            amount is rejected with an inline error.
 *   GOAL-05: edit updates the row; markComplete sets status=completed (goal
 *            stays in list); archive sets status=archived (hidden from active
 *            view); restore returns to active; cross-user cannot edit/archive
 *            another user's goal.
 */
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'asn',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
});

// ---------------------------------------------------------------------------
// GOAL-01: Create goal
// ---------------------------------------------------------------------------

it('renders the goals page', function (): void {
    Livewire::test(GoalsPage::class)
        ->assertOk()
        ->assertSee('Goals');
});

it('creates a goal, writes a goals row, and dispatches a toast', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', 'Emergency fund')
        ->set('targetAmount', '1000.00')
        ->set('targetDate', '2027-01-01')
        ->set('accountId', (string) $this->account->id)
        ->call('createGoal')
        ->assertDispatched('toast');

    $this->assertDatabaseHas('goals', [
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Emergency fund',
        'target_minor' => 100000,
        'target_currency' => 'EUR',
        'status' => 'active',
    ]);
});

it('creates an unlinked goal when no account is selected', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', 'Holiday fund')
        ->set('targetAmount', '500')
        ->set('targetDate', '2027-06-01')
        ->set('accountId', '')
        ->call('createGoal')
        ->assertDispatched('toast');

    $this->assertDatabaseHas('goals', [
        'user_id' => $this->user->id,
        'account_id' => null,
        'name' => 'Holiday fund',
        'target_minor' => 50000,
    ]);
});

it('rejects an invalid amount without writing a goal row', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', 'Bad goal')
        ->set('targetAmount', 'not-a-number')
        ->set('targetDate', '2027-01-01')
        ->call('createGoal');

    $this->assertDatabaseMissing('goals', [
        'name' => 'Bad goal',
    ]);
});

it('rejects a zero or negative amount', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', 'Zero goal')
        ->set('targetAmount', '0')
        ->set('targetDate', '2027-01-01')
        ->call('createGoal');

    $this->assertDatabaseMissing('goals', ['name' => 'Zero goal']);
});

it('parses the Dutch grouped amount format', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', 'Dutch amount goal')
        ->set('targetAmount', '1.234,56')
        ->set('targetDate', '2027-01-01')
        ->call('createGoal');

    $this->assertDatabaseHas('goals', [
        'name' => 'Dutch amount goal',
        'target_minor' => 123456,
    ]);
});

// ---------------------------------------------------------------------------
// GOAL-05: Edit / lifecycle status transitions
// ---------------------------------------------------------------------------

it('updates an existing goals name and target via edit', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Old name',
        'target_minor' => 50000,
        'status' => 'active',
    ]);

    Livewire::test(GoalsPage::class)
        ->set('editGoalId', $goal->id)
        ->set('name', 'New name')
        ->set('targetAmount', '750.00')
        ->set('targetDate', '2027-06-01')
        ->call('updateGoal')
        ->assertDispatched('toast');

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'name' => 'New name',
        'target_minor' => 75000,
    ]);
});

it('openEdit prefills the target date so the edit can be saved without re-entry', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'name' => 'Old name',
        'target_minor' => 50000,
        'target_date' => '2027-06-01',
        'status' => 'active',
    ]);

    // openEdit must populate targetDate from the stored goal (WR-01); the
    // previous behaviour blanked it, so a subsequent save failed date validation.
    Livewire::test(GoalsPage::class)
        ->call('openEdit', $goal->id)
        ->assertSet('editGoalId', $goal->id)
        ->assertSet('name', 'Old name')
        ->assertSet('targetDate', '2027-06-01')
        // Save immediately without re-entering the date — must succeed.
        ->set('name', 'New name')
        ->set('targetAmount', '750.00')
        ->call('updateGoal')
        ->assertDispatched('toast')
        ->assertSet('errorDate', '');

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'name' => 'New name',
        'target_minor' => 75000,
    ]);
});

it('markComplete sets status to completed and the goal remains in the list', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    Livewire::test(GoalsPage::class)
        ->call('markComplete', $goal->id)
        ->assertDispatched('toast');

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'status' => 'completed',
    ]);
});

it('archive sets status to archived and hides goal from active view', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);

    Livewire::test(GoalsPage::class)
        ->call('archive', $goal->id)
        ->assertDispatched('toast');

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'status' => 'archived',
    ]);
});

it('restore returns an archived goal to active status', function (): void {
    $goal = Goal::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'archived',
    ]);

    Livewire::test(GoalsPage::class)
        ->call('restore', $goal->id)
        ->assertDispatched('toast');

    $this->assertDatabaseHas('goals', [
        'id' => $goal->id,
        'status' => 'active',
    ]);
});

it('cross-user cannot edit another users goal', function (): void {
    $other = User::create([
        'username' => 'mallory',
        'password' => 'x',
        'period_start_day' => 1,
    ]);
    $foreignGoal = Goal::factory()->create([
        'user_id' => $other->id,
        'name' => 'Mallory goal',
        'target_minor' => 100000,
        'status' => 'active',
    ]);

    // Wessel (actingAs) attempts to edit Mallory's goal — should be silently ignored.
    Livewire::test(GoalsPage::class)
        ->set('editGoalId', $foreignGoal->id)
        ->set('name', 'Hacked')
        ->set('targetAmount', '1')
        ->call('updateGoal');

    $this->assertDatabaseMissing('goals', [
        'id' => $foreignGoal->id,
        'name' => 'Hacked',
    ]);
});

it('cross-user cannot archive another users goal', function (): void {
    $other = User::create([
        'username' => 'mallory',
        'password' => 'x',
        'period_start_day' => 1,
    ]);
    $foreignGoal = Goal::factory()->create([
        'user_id' => $other->id,
        'status' => 'active',
    ]);

    Livewire::test(GoalsPage::class)
        ->call('archive', $foreignGoal->id);

    // Status must remain active — BelongsToUser scope rejects the foreign id.
    $this->assertDatabaseHas('goals', [
        'id' => $foreignGoal->id,
        'status' => 'active',
    ]);
});
