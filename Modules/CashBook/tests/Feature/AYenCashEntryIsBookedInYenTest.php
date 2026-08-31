<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\CashBook\Internal\Http\Livewire\CashBookPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

// The field is labelled with the cash account's own symbol and was parsed at a
// hundredth regardless, so "1250" typed against a ¥ label was booked as
// ¥125,000 — and the unreadable-amount message offered "1250.00" as the
// example, which is not a figure a yen account can hold.

function yenCashUser(string $currency): User
{
    $user = User::query()->create([
        'username' => 'yen-cash-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => $currency,
    ]);

    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'Cash',
        'slug' => 'yen-cash-'.bin2hex(random_bytes(4)),
        'kind' => 'cash',
        'iban' => 'CASH'.str_pad((string) $user->id, 12, '0', STR_PAD_LEFT),
        'default_currency' => $currency,
    ]);

    return $user;
}

function yenCashEntryMinor(User $user): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return (int) $db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->value('settled_amount_minor');
}

it('books a yen cash entry at the figure that was typed', function (): void {
    $user = yenCashUser('JPY');

    Livewire::actingAs($user)
        ->test(CashBookPage::class)
        ->set('amount', '1250')
        ->set('date', '2026-05-17')
        ->set('counterparty', 'Kiosk')
        ->call('add')
        ->assertSet('error', '');

    expect(yenCashEntryMinor($user))->toBe(-1250);
});

it('still books a euro cash entry in cents', function (): void {
    $user = yenCashUser('EUR');

    Livewire::actingAs($user)
        ->test(CashBookPage::class)
        ->set('amount', '12,50')
        ->set('date', '2026-05-17')
        ->set('counterparty', 'Kiosk')
        ->call('add')
        ->assertSet('error', '');

    expect(yenCashEntryMinor($user))->toBe(-1250);
});

it('offers an example the yen field can actually take', function (): void {
    expect(MoneyInput::toDecimalString(125_000, 'JPY'))->toBe('125000')
        ->and(MoneyInput::toDecimalString(125_000, 'EUR'))->toBe('1250.00')
        ->and(MoneyInput::tryToMinor('125000', 'JPY'))->toBe(125_000)
        ->and(MoneyInput::tryToMinor('1250.00', 'JPY'))->toBeNull();
});

// The example was right and the sentence carrying it was not: it told a yen
// reader to use "at most two decimals", which is the very shape that produced
// the error, and to leave out a thousands separator the parser accepts.
it('describes the shape the yen field takes, not the one that was refused', function (): void {
    $error = Livewire::actingAs(yenCashUser('JPY'))
        ->test(CashBookPage::class)
        ->set('amount', '1250.00')
        ->set('date', '2026-05-17')
        ->set('counterparty', 'Kiosk')
        ->call('add')
        ->get('error');

    expect($error)->toBeString()
        ->toContain('125000')
        ->toContain('whole number')
        ->not->toContain('decimal places')
        ->not->toContain('thousands separator');
});

it('still names two decimal places to a euro reader', function (): void {
    $error = Livewire::actingAs(yenCashUser('EUR'))
        ->test(CashBookPage::class)
        ->set('amount', '1.250')
        ->set('date', '2026-05-17')
        ->set('counterparty', 'Kiosk')
        ->call('add')
        ->get('error');

    expect($error)->toBeString()
        ->toContain('at most 2 decimal places')
        ->toContain('1250.00')
        ->not->toContain('thousands separator');
});

// A grouped figure is exactly what formatMinor() writes back into the field,
// so a message telling the reader to leave the mark out was describing a
// refusal the parser never makes.
it('accepts the thousands separator the old message told the reader to leave out', function (): void {
    $user = yenCashUser('EUR');

    Livewire::actingAs($user)
        ->test(CashBookPage::class)
        ->set('amount', '1 250,00')
        ->set('date', '2026-05-17')
        ->set('counterparty', 'Kiosk')
        ->call('add')
        ->assertSet('error', '');

    expect(yenCashEntryMinor($user))->toBe(-125_000);
});

// A grouped figure is what formatMinor() writes into an editable field, so a
// currency whose separator can only be a group mark still has to read back.
it('reads back whatever it wrote into an editable yen field', function (string $locale): void {
    app('translator')->setLocale($locale);

    foreach ([0, 5, 1250, 999_999, -1250, -999_999_999] as $minor) {
        $formatted = MoneyInput::formatMinor($minor, 'JPY');
        expect(MoneyInput::tryToMinor($formatted, 'JPY'))->toBe($minor, $formatted);
    }
})->with(['en', 'nl', 'fr']);
