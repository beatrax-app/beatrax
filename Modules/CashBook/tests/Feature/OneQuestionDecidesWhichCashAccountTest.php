<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\CashBook\Internal\Services\ManualEntryAnchors;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;

// The amount box was labelled and parsed off one where(kind = cash) and the
// entry was booked off another, so with two cash rows "1250" could be read as
// €1250,00 and written as ¥1250. Neither read was ordered, so which row each
// one answered with was SQLite's choice rather than a rule.

function oneCashQuestionUser(): User
{
    return User::query()->create([
        'username' => 'one-cash-question-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

// Two cash rows whose `id` order and whose `iban` order disagree, so a read
// that walks the (user_id, kind) index answers with the euro one and a read
// ordered on the account's own identity answers with the yen one.
function oneCashQuestionTwoAccounts(User $user): Account
{
    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Wallet',
        'slug' => 'one-cash-question-eur-'.bin2hex(random_bytes(4)),
        'kind' => 'cash',
        'iban' => 'CASH-Z-EUR-'.bin2hex(random_bytes(4)),
        'default_currency' => 'EUR',
    ]);

    /** @var Account $yen */
    $yen = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Trip cash',
        'slug' => 'one-cash-question-jpy-'.bin2hex(random_bytes(4)),
        'kind' => 'cash',
        'iban' => 'CASH-A-JPY-'.bin2hex(random_bytes(4)),
        'default_currency' => 'JPY',
    ]);

    return $yen;
}

it('labels the amount box in the currency the entry is booked in', function (): void {
    $user = oneCashQuestionUser();
    $yen = oneCashQuestionTwoAccounts($user);

    $component = Livewire::actingAs($user)
        ->test(CashBookPage::class)
        ->set('amount', '1250')
        ->set('date', '2026-05-17')
        ->set('counterparty', 'Kiosk');

    expect($component->viewData('entryCurrency'))->toBe('JPY');

    $component->call('add')->assertSet('error', '');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('transactions')->where('user_id', $user->id)->first();

    expect($row)->not->toBeNull();
    expect((int) $row->account_id)->toBe($yen->id);
    expect($row->settled_currency)->toBe('JPY');
    expect((int) $row->settled_amount_minor)->toBe(-1250);
});

it('books the entry on the same cash account the page named', function (): void {
    $user = oneCashQuestionUser();
    $yen = oneCashQuestionTwoAccounts($user);

    /** @var ManualEntryAnchors $anchors */
    $anchors = app(ManualEntryAnchors::class);

    expect($anchors->accountIdFor($user))->toBe($yen->id);
    expect($anchors->currencyForUser($user))->toBe($anchors->currencyFor($yen->id, $user));
});

// No reader at all, which is the console path the demo seeder runs on:
// BaseCurrency::code() answers with the install default there, and the column
// this writes is what every later entry on the account is booked in.
it('denominates the account it mints in the owner\'s currency, not the install default', function (): void {
    config()->set('currency.base', 'EUR');

    $user = User::query()->create([
        'username' => 'one-cash-question-owner-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'JPY',
    ]);

    /** @var ManualEntryAnchors $anchors */
    $anchors = app(ManualEntryAnchors::class);
    $accountId = $anchors->accountIdFor($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($db->connection()->table('accounts')->where('id', $accountId)->value('default_currency'))->toBe('JPY');
});
