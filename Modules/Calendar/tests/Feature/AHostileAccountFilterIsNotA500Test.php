<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;

function hostileUser(): User
{
    return User::query()->create([
        'username' => 'hostile-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = hostileUser();
    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'hostile asn',
        'slug' => 'hostile-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'HOST-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
});

// The save path already sanitised; the render path handed the raw property
// straight to the query, and a nested array came back as
// "Array to string conversion".
it('renders rather than 500s when the entries filter carries a nested array', function (): void {
    Livewire::actingAs($this->user)
        ->test(CalendarPage::class)
        ->set('visibleAccountIds', [['a' => 'b']])
        ->assertOk()
        ->assertSet('visibleAccountIds', []);
});

it('renders rather than 500s when the balance filter carries a nested array', function (): void {
    Livewire::actingAs($this->user)
        ->test(CalendarPage::class)
        ->set('balanceAccountIds', [['a' => 'b']])
        ->assertOk()
        ->assertSet('balanceAccountIds', []);
});

it('drops an account id the reader does not own', function (): void {
    Livewire::actingAs($this->user)
        ->test(CalendarPage::class)
        ->set('visibleAccountIds', [424242])
        ->assertOk()
        ->assertSet('visibleAccountIds', []);
});

it('keeps an account id the reader does own', function (): void {
    Livewire::actingAs($this->user)
        ->test(CalendarPage::class)
        ->set('visibleAccountIds', [(int) $this->account->id])
        ->assertOk()
        ->assertSet('visibleAccountIds', [(int) $this->account->id]);
});
