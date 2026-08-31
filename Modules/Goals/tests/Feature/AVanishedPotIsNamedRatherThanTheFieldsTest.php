<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\Lang;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;
use Modules\Ledger\Models\Account;
use Modules\Pots\Models\Pot;

// The picker is built in render(), so a pot archived on the Pots page after the
// modal opened is still an option. Picking it refused the whole goal as "check
// the fields" — under a picker whose value the reader had just chosen from it.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'vanished-pot',
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
});

function vpnArchivedPot(int $userId, int $accountId): Pot
{
    /** @var Pot $pot */
    $pot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'goal_id' => null,
        'category_id' => null,
        'name' => 'Gone',
        'currency' => 'EUR',
        'status' => 'archived',
    ]);

    return $pot;
}

it('names the pot when a new goal is linked to one that has gone', function (): void {
    $pot = vpnArchivedPot($this->user->id, $this->account->id);

    Livewire::actingAs($this->user)->test(GoalsPage::class)
        ->set('name', 'Nieuwe keuken')
        ->set('targetAmount', '5000,00')
        ->set('targetDate', '2027-06-15')
        ->set('linkedPotId', (string) $pot->id)
        ->call('createGoal')
        ->assertSet('errorLinkedPot', Lang::get('goals::messages.errors.pot_missing'));
});

it('names the pot when an edited goal is linked to one that has gone', function (): void {
    $pot = vpnArchivedPot($this->user->id, $this->account->id);

    $page = Livewire::actingAs($this->user)->test(GoalsPage::class)
        ->set('name', 'Nieuwe keuken')
        ->set('targetAmount', '5000,00')
        ->set('targetDate', '2027-06-15')
        ->call('createGoal');

    $goalId = (int) DB::table('goals')->where('user_id', $this->user->id)->value('id');

    $page->call('openEdit', $goalId)
        ->set('linkedPotId', (string) $pot->id)
        ->call('updateGoal')
        ->assertSet('errorLinkedPot', Lang::get('goals::messages.errors.pot_missing'));
});

// A pot id nobody owns and a pot id nobody has must read alike, or the refusal
// answers "does this pot exist?" for another reader's pots.
it('answers a foreign pot exactly as it answers one that does not exist', function (): void {
    $stranger = User::create([
        'username' => 'neighbour',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $theirAccount = Account::create([
        'user_id' => $stranger->id,
        'name' => 'Rabo',
        'slug' => 'rabo',
        'kind' => 'bank',
        'iban' => 'NL57RABO0123456789',
        'default_currency' => 'EUR',
    ]);
    /** @var Pot $theirPot */
    $theirPot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $stranger->id,
        'account_id' => $theirAccount->id,
        'goal_id' => null,
        'category_id' => null,
        'name' => 'Theirs',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    $refusal = static function (int $potId) {
        return Livewire::actingAs(test()->user)->test(GoalsPage::class)
            ->set('name', 'Nieuwe keuken')
            ->set('targetAmount', '5000,00')
            ->set('targetDate', '2027-06-15')
            ->set('linkedPotId', (string) $potId)
            ->call('createGoal')
            ->get('errorLinkedPot');
    };

    expect($refusal($theirPot->id))->toBe($refusal(999999));
});
