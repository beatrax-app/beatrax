<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Pots\Internal\Exceptions\AccountCannotHoldPotsException;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Enums\PotStatus;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;

// A pot carves up money the reader HOLDS. On a credit card the balance is what
// is owed, so a pot there is over-allocated the moment it exists and can never
// be funded. The account picker never offered one; accountId is the client's.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->card = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ICS card',
        'slug' => 'ics-card',
        'kind' => AccountKind::IcsCard->value,
        'iban' => 'NL11ICSB0000000001',
        'default_currency' => 'EUR',
    ]);

    $this->bank = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
});

it('refuses to create a pot on an account that holds no allocatable balance', function (): void {
    app(PotWriter::class)->save($this->user, 'Kaartpot', null, $this->card->id, null, null);
})->throws(AccountCannotHoldPotsException::class);

it('writes nothing and names the field when the page is handed such an account', function (): void {
    Livewire::test(PotsPage::class)
        ->set('name', 'Kaartpot')
        ->set('accountId', (string) $this->card->id)
        ->call('createPot')
        ->assertNotDispatched('toast')
        ->assertSet('errorName', Lang::get('pots::messages.errors.account_cannot_hold_pots'));

    $this->assertDatabaseMissing('pots', ['name' => 'Kaartpot']);
});

it('still creates a pot on an account that does hold a balance', function (): void {
    Livewire::test(PotsPage::class)
        ->set('name', 'Buffer')
        ->set('accountId', (string) $this->bank->id)
        ->call('createPot')
        ->assertDispatched('toast');

    $this->assertDatabaseHas('pots', ['name' => 'Buffer', 'account_id' => $this->bank->id]);
});

it('keeps the account out of the picker it was never meant to be in', function (): void {
    $ids = array_map(
        static fn (object $account): int => (int) $account->id,
        app(PotBalanceQuery::class)->accountsForUser($this->user),
    );

    expect($ids)->toBe([$this->bank->id]);
});

// The page's empty state was gated on the accounts a pot may be created on, so
// a pot already sitting on a card account vanished from the list along with
// every action on it -- unusable, unfundable and with no way off the page.
it('still lists and archives a pot already sitting on such an account', function (): void {
    $this->bank->delete();

    /** @var Pot $stranded */
    $stranded = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->card->id,
        'goal_id' => null,
        'category_id' => null,
        'name' => 'Gestrande pot',
        'currency' => 'EUR',
        'status' => PotStatus::Active->value,
    ]);

    expect(app(PotBalanceQuery::class)->accountsForUser($this->user))->toBe([]);

    Livewire::test(PotsPage::class)
        ->assertOk()
        ->assertSee('Gestrande pot')
        ->call('archivePot', $stranded->id);

    expect($stranded->fresh()->status)->toBe(PotStatus::Archived->value);
});

it('does not offer Add pot on a group whose account can no longer hold one', function (): void {
    foreach ([[$this->card->id, 'Kaartpot'], [$this->bank->id, 'Bankpot']] as [$accountId, $name]) {
        Pot::query()->withoutGlobalScope(UserScope::class)->create([
            'user_id' => $this->user->id,
            'account_id' => $accountId,
            'goal_id' => null,
            'category_id' => null,
            'name' => $name,
            'currency' => 'EUR',
            'status' => PotStatus::Active->value,
        ]);
    }

    $html = (string) Livewire::test(PotsPage::class)->html();

    // Both groups render; only the one a pot may still be added to carries the
    // button, so the page never offers an action the writer now refuses.
    expect($html)->toContain('Kaartpot')
        ->and($html)->toContain('Bankpot')
        ->and(substr_count($html, "\$wire.set('accountId', '".$this->bank->id."')"))->toBe(1)
        ->and(substr_count($html, "\$wire.set('accountId', '".$this->card->id."')"))->toBe(0);
});
