<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Goals\Models\Goal;
use Modules\Ledger\Models\Account;
use Modules\Pots\Models\Pot;

// Editing a goal, dismissing the modal and pressing "Add goal" saved the edited
// goal's own values as a second goal -- and took the first goal's linked pot
// with it, leaving the original at 0% with no pot. Two guards, because either
// one alone still ships half of it: the form is cleared before a create, and a
// pot that already funds a goal is refused to a second one.

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
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->japan = Goal::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Japan trip',
        'target_minor' => 500000,
        'target_currency' => 'EUR',
        'start_date' => CarbonImmutable::now()->subDays(30)->toDateString(),
        'target_date' => CarbonImmutable::now()->addYearNoOverflow()->toDateString(),
        'status' => 'active',
    ]);

    $this->pot = Pot::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => $this->japan->id,
        'category_id' => null,
    ]);
});

it('empties every field the edit filled when the create form is opened', function (): void {
    $component = Livewire::test(GoalsPage::class)
        ->call('openEdit', $this->japan->id)
        ->assertSet('name', 'Japan trip')
        ->assertSet('linkedPotId', (string) $this->pot->id);

    $component->call('startCreate')
        ->assertSet('editGoalId', 0)
        ->assertSet('name', '')
        ->assertSet('targetAmount', '')
        ->assertSet('targetDate', '')
        ->assertSet('linkedPotId', '');
});

it('refuses to give a second goal the pot the first one is funded by', function (): void {
    // The dismissed modal left every field standing; "Add goal" used to clear
    // only editGoalId, so re-typing the edited goal's own values over a cleared
    // form is exactly the payload the next submit sent. editGoalId itself is
    // locked, so the client reaches 0 through startCreate() and nowhere else.
    Livewire::test(GoalsPage::class)
        ->call('openEdit', $this->japan->id)
        ->call('startCreate')
        ->set('name', 'Japan trip')
        ->set('targetAmount', '5000,00')
        ->set('targetDate', CarbonImmutable::now()->addYearNoOverflow()->toDateString())
        ->set('linkedPotId', (string) $this->pot->id)
        ->call('createGoal')
        ->assertNotDispatched('toast')
        ->assertSet('errorLinkedPot', Lang::get('goals::messages.errors.pot_already_linked'));

    expect(Goal::query()->where('name', 'Japan trip')->count())->toBe(1);

    $this->assertDatabaseHas('pots', ['id' => $this->pot->id, 'goal_id' => $this->japan->id]);
});

it('still lets a second goal be created once the form has been cleared', function (): void {
    Livewire::test(GoalsPage::class)
        ->call('openEdit', $this->japan->id)
        ->call('startCreate')
        ->set('name', 'Winterbanden')
        ->set('targetAmount', '400,00')
        ->set('targetDate', CarbonImmutable::now()->addMonthsNoOverflow(6)->toDateString())
        ->call('createGoal')
        ->assertDispatched('toast');

    expect(Goal::query()->where('name', 'Winterbanden')->count())->toBe(1);

    // The first goal keeps the pot it was funded by.
    $this->assertDatabaseHas('pots', ['id' => $this->pot->id, 'goal_id' => $this->japan->id]);
});

it('names the real date a goal cannot start before rather than calling it unreal', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', 'Backdated')
        ->set('targetAmount', '100,00')
        ->set('targetDate', '2020-01-01')
        ->call('createGoal')
        ->assertSet('errorDate', Lang::get('goals::messages.errors.date_before_start'));

    expect(Lang::get('goals::messages.errors.date_before_start'))
        ->not->toBe(Lang::get('goals::messages.errors.date_invalid'));
});

it('still calls a date the calendar does not have unreal', function (): void {
    Livewire::test(GoalsPage::class)
        ->set('name', 'Impossible')
        ->set('targetAmount', '100,00')
        ->set('targetDate', '2027-02-30')
        ->call('createGoal')
        ->assertSet('errorDate', Lang::get('goals::messages.errors.date_invalid'));
});
