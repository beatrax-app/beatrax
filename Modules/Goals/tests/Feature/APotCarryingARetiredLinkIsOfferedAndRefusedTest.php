<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\Lang;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Enums\PotStatus;
use Modules\Pots\Public\Services\PotWriter;

// Category links are retired: no create and no edit makes one, and the
// envelope cutover archives every active pot that carries one. The shape still
// exists in the field — restore() put it back until it was taught to clear the
// column, and nothing has swept the rows that write left behind.
//
// PotWriter::linkGoal() refuses such a pot and names the control that clears
// it. The goal picker filtered it out of the list, so that sentence could never
// reach a reader — while the union branch for the edited goal's own pot applied
// no such filter, so one pot was present in one branch and absent in the other.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'retired-link',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->category = Category::create([
        'user_id' => $this->user->id,
        'name' => 'Boodschappen',
        'slug' => 'boodschappen',
        'kind' => 'expense',
        'display_order' => 1,
    ]);
});

// Written through the model rather than through PotWriter on purpose: every
// writer refuses this shape, which is the point. It is what the field holds,
// not what the app would write today.
function retiredLinkPot(object $test, ?int $goalId = null): Pot
{
    /** @var Pot $pot */
    $pot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $test->user->id,
        'account_id' => $test->account->id,
        'goal_id' => $goalId,
        'category_id' => $test->category->id,
        'name' => 'Boodschappenpot',
        'currency' => 'EUR',
        'status' => PotStatus::Active->value,
    ]);

    return $pot;
}

function retiredLinkGoalId(User $user): int
{
    return (int) DB::table('goals')->where('user_id', $user->id)->value('id');
}

it('offers an active pot that still carries a retired category link', function (): void {
    $pot = retiredLinkPot($this);

    $offered = Livewire::actingAs($this->user)->test(GoalsPage::class)->viewData('pots');

    $ids = array_map(static fn (object $row): int => (int) $row->id, $offered);

    // in_array, not toContain: toContain takes variadic NEEDLES, so a trailing
    // explanation is asserted as a second thing the array must hold.
    expect(in_array((int) $pot->id, $ids, true))->toBeTrue(
        'The pot is active, unlinked from any goal, and on screen on the Pots page — and the goal picker '
        .'left it out with nothing anywhere saying why. The refusal it would meet names the control that '
        .'clears the link; silence names nothing. Offered: '.implode(', ', $ids),
    );
});

it('refuses it with the sentence that says how to clear the link', function (): void {
    $pot = retiredLinkPot($this);

    Livewire::actingAs($this->user)->test(GoalsPage::class)
        ->set('name', 'Nieuwe keuken')
        ->set('targetAmount', '5000,00')
        ->set('targetDate', '2027-06-15')
        ->set('linkedPotId', (string) $pot->id)
        ->call('createGoal')
        ->assertSet('errorLinkedPot', Lang::get('goals::messages.errors.pot_linked_category'));
});

// The remedy the sentence names has to work, or offering the pot only moves the
// dead end one screen along.
it('lets the pot back into a goal once the Pots page has cleared the link', function (): void {
    $pot = retiredLinkPot($this);

    app(PotWriter::class)
        ->update($this->user, (int) $pot->id, 'Boodschappenpot', null, null);

    Livewire::actingAs($this->user)->test(GoalsPage::class)
        ->set('name', 'Nieuwe keuken')
        ->set('targetAmount', '5000,00')
        ->set('targetDate', '2027-06-15')
        ->set('linkedPotId', (string) $pot->id)
        ->call('createGoal')
        ->assertSet('errorLinkedPot', '');

    expect((int) DB::table('pots')->where('id', $pot->id)->value('goal_id'))
        ->toBe(retiredLinkGoalId($this->user));
});

// Both branches of the picker answer the same question about the same pot. The
// union branch never carried the filter, so one pot could be in the list while
// editing its own goal and out of it a moment later.
it('answers the same for a pot whether or not the edited goal already holds it', function (): void {
    $page = Livewire::actingAs($this->user)->test(GoalsPage::class)
        ->set('name', 'Nieuwe keuken')
        ->set('targetAmount', '5000,00')
        ->set('targetDate', '2027-06-15')
        ->call('createGoal');

    $goalId = retiredLinkGoalId($this->user);

    $held = retiredLinkPot($this, $goalId);
    $free = retiredLinkPot($this);

    $page->call('openEdit', $goalId);

    $ids = array_map(static fn (object $row): int => (int) $row->id, $page->viewData('pots'));

    expect(in_array((int) $held->id, $ids, true))->toBeTrue('The edited goal\'s own pot is not offered at all.')
        ->and(in_array((int) $free->id, $ids, true))->toBeTrue(
            'The edited goal\'s own pot carries a retired category link and is offered; a pot in exactly '
            .'the same state that the goal does not hold is not. One filter, on one of two branches of one '
            .'query, is the whole of the difference. Offered: '.implode(', ', $ids),
        );
});
