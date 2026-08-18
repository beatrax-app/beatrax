<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Sync\Public\Events\GoalContributionMutated;

/*
 * The transaction detail screen is where a transaction is assigned to a
 * savings goal, alongside the category, split and counterparty pickers that
 * already live there. Sync capture is faked out — the op-log behaviour is
 * covered in Modules/Sync/tests/Feature/GoalContributionSyncCaptureTest.
 */

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-14 12:00:00'));

    $this->asnAccount = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();

    $this->run = $this->makeImportRun($this->fixtureUser);

    $this->tx = $this->makeTransaction($this->fixtureUser, $this->asnAccount, $this->run, [
        'type' => 'transfer_in',
        'amount_minor' => 20000,
        'posted_at' => '2026-06-14',
        'booked_at' => '2026-06-14 12:00:00',
    ]);

    $this->goal = Goal::factory()->create([
        'user_id' => $this->fixtureUser->id,
        'name' => 'Winter tyres',
        'target_minor' => 60000,
        'status' => 'active',
    ]);

    Event::fake([GoalContributionMutated::class]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('attributeToGoal writes a goal_contributions row and dispatches the sync event', function (): void {
    Livewire::test(TransactionDetail::class, ['transactionId' => $this->tx->id])
        ->call('attributeToGoal', $this->goal->id);

    expect(DB::table('goal_contributions')
        ->where('goal_id', $this->goal->id)
        ->where('transaction_id', $this->tx->id)
        ->where('user_id', $this->fixtureUser->id)
        ->count())->toBe(1);

    Event::assertDispatched(GoalContributionMutated::class, function (GoalContributionMutated $e): bool {
        return $e->mutationType === 'create'
            && ($e->dirtyFields['goal_id'] ?? null) === $this->goal->id
            && ($e->dirtyFields['transaction_id'] ?? null) === $this->tx->id;
    });
});

it('removeGoalAttribution deletes the row and dispatches a delete event', function (): void {
    $component = Livewire::test(TransactionDetail::class, ['transactionId' => $this->tx->id])
        ->call('attributeToGoal', $this->goal->id);

    $component->call('removeGoalAttribution', $this->goal->id);

    expect(DB::table('goal_contributions')->count())->toBe(0);

    Event::assertDispatched(GoalContributionMutated::class, fn (GoalContributionMutated $e): bool => $e->mutationType === 'delete');
});

it('renders the attribution picker with the users goals and the current attributions', function (): void {
    Livewire::test(TransactionDetail::class, ['transactionId' => $this->tx->id])
        ->assertSeeHtml('data-testid="goal-attribution"')
        ->assertSee('Winter tyres');
});

it('does not offer another users goal as an attribution target', function (): void {
    $other = User::create(['username' => 'other-goal-user', 'password' => 'opensesame', 'period_start_day' => 1]);
    Goal::factory()->create([
        'user_id' => $other->id,
        'name' => 'Foreign goal',
        'target_minor' => 60000,
        'status' => 'active',
    ]);

    Livewire::test(TransactionDetail::class, ['transactionId' => $this->tx->id])
        ->assertDontSee('Foreign goal');
});

it('ignores an attribution aimed at another users goal', function (): void {
    $other = User::create(['username' => 'other-goal-writer', 'password' => 'opensesame', 'period_start_day' => 1]);
    $foreign = Goal::factory()->create([
        'user_id' => $other->id,
        'name' => 'Foreign goal',
        'target_minor' => 60000,
        'status' => 'active',
    ]);

    Livewire::test(TransactionDetail::class, ['transactionId' => $this->tx->id])
        ->call('attributeToGoal', $foreign->id);

    expect(DB::table('goal_contributions')->count())->toBe(0);
});

it('ignores a removal aimed at another users goal', function (): void {
    $other = User::create(['username' => 'other-goal-remover', 'password' => 'opensesame', 'period_start_day' => 1]);
    $foreign = Goal::factory()->create([
        'user_id' => $other->id,
        'name' => 'Foreign goal',
        'target_minor' => 60000,
        'status' => 'active',
    ]);

    $component = Livewire::test(TransactionDetail::class, ['transactionId' => $this->tx->id])
        ->call('attributeToGoal', $this->goal->id);

    $component->call('removeGoalAttribution', $foreign->id);

    expect(DB::table('goal_contributions')->count())->toBe(1);
});
