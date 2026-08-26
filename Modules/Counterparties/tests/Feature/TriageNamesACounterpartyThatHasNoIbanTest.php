<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Http\Livewire\CounterpartyTriage;

// 14 of the 47 unknowns a PayPal import left on the Samsung had no IBAN — a
// PayPal counterparty is keyed on its name, and there is no account number to
// key on. The card printed an em dash where the identifier goes and headed the
// list "Recent transactions on this IBAN" anyway, so the screen that asks the
// reader to identify a counterparty withheld the only thing identifying it and
// then named a field it did not have.

function triageUnknownWithoutAnIban(string $username, string $name): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $now = now()->toDateTimeString();
    DB::table('counterparties')->insert([
        'user_id' => $user->id,
        'type' => 'unknown',
        'slug' => str($name)->slug()->value(),
        'display_name' => $name,
        'iban' => null,
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $user;
}

it('names the counterparty it is asking about when there is no IBAN to show', function (): void {
    $user = triageUnknownWithoutAnIban('triage-no-iban', 'Patreon Ireland Limited');

    Livewire::actingAs($user)
        ->test(CounterpartyTriage::class)
        ->assertSeeHtml('<span class="triage-iban">Patreon Ireland Limited</span>');
});

it('does not head the list with a field the counterparty does not have', function (): void {
    $user = triageUnknownWithoutAnIban('triage-no-iban-head', 'Jagex Limited');

    Livewire::actingAs($user)
        ->test(CounterpartyTriage::class)
        ->assertDontSee('on this IBAN');
});

it('still shows the masked IBAN, and says so, when there is one', function (): void {
    $user = User::query()->create([
        'username' => 'triage-with-iban',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $now = now()->toDateTimeString();
    DB::table('counterparties')->insert([
        'user_id' => $user->id,
        'type' => 'unknown',
        'slug' => 'mystery-with-iban',
        'display_name' => 'Mystery payee',
        'iban' => 'NL91ABNA0417164300',
        'merchant_name' => null,
        'metadata' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::actingAs($user)
        ->test(CounterpartyTriage::class)
        ->assertSee('NL · ·· ABNA ···· ···· 00')
        ->assertSee('on this IBAN');
});
