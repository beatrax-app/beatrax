<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotWriter;

// Every pot figure is read at the pot's own denomination, which the pot takes
// from the account it sits on. All eight boxes on this page spelled two
// decimals and asked the phone for a decimal key regardless, so a yen pot
// invited the very shape PotWriter refuses.

/**
 * @return array{0: User, 1: Account}
 */
function potShapeFixture(string $accountCurrency): array
{
    $user = User::create([
        'username' => 'pot-shape-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'Trip',
        'slug' => 'pot-shape-'.bin2hex(random_bytes(4)),
        'kind' => AccountKind::Bank->value,
        'iban' => 'JP'.bin2hex(random_bytes(6)),
        'default_currency' => $accountCurrency,
        'starting_balance_minor' => 500_000,
        'starting_balance_date' => '2026-01-01',
    ]);

    return [$user, $account];
}

function potAmountBox(string $html, string $fieldId): string
{
    $found = PatternScan::first('/<input[^>]*id="'.preg_quote($fieldId, '/').'"[^>]*>/', $html);

    expect($found)->not->toBe([], 'no amount box rendered for '.$fieldId);

    return $found[0];
}

it('invites a whole initial amount once a yen account is picked', function (string $fieldId): void {
    [$user, $account] = potShapeFixture(Currency::Jpy->value);

    $html = Livewire::actingAs($user)
        ->test(PotsPage::class)
        ->set('accountId', (string) $account->id)
        ->html();

    expect(potAmountBox($html, $fieldId))
        ->toContain('placeholder="0"')
        ->not->toContain('placeholder="0.00"')
        ->toContain('inputmode="numeric"');
})->with(['pot-amount', 'pot-amount-sheet']);

// A lock, not the red for this change: the box a euro account gets is
// byte-identical either side of it, because the old blade spelled `decimal`
// and the `0.00` placeholder key as literals. The yen half above is the proof;
// this half catches a currency-aware rewrite that loses the majority case.
it('locks the two decimals a euro account already invited', function (string $fieldId): void {
    [$user, $account] = potShapeFixture(Currency::Eur->value);

    $html = Livewire::actingAs($user)
        ->test(PotsPage::class)
        ->set('accountId', (string) $account->id)
        ->html();

    expect(potAmountBox($html, $fieldId))
        ->toContain('placeholder="0.00"')
        ->toContain('inputmode="decimal"')
        ->not->toContain('inputmode="numeric"');
})->with(['pot-amount', 'pot-amount-sheet']);

it('invites a whole amount on every surface that moves money in a yen pot', function (string $fieldId): void {
    [$user, $account] = potShapeFixture(Currency::Jpy->value);

    /** @var Pot $pot */
    $pot = app(PotWriter::class)->save($user, 'Ryokan', '120000', $account->id, null, null);

    $html = Livewire::actingAs($user)
        ->test(PotsPage::class)
        ->set('operationPotId', $pot->id)
        ->html();

    expect(potAmountBox($html, $fieldId))
        ->toContain('placeholder="0"')
        ->not->toContain('placeholder="0.00"')
        ->toContain('inputmode="numeric"');
})->with([
    'fund-amount',
    'fund-amount-sheet',
    'withdraw-amount',
    'withdraw-amount-sheet',
    'move-amount',
    'move-amount-sheet',
]);

// The same lock as the euro half above, over the surfaces that move money in
// an existing pot rather than the one that opens a new one.
it('locks the two decimals every euro money-moving surface already invited', function (string $fieldId): void {
    [$user, $account] = potShapeFixture(Currency::Eur->value);

    /** @var Pot $pot */
    $pot = app(PotWriter::class)->save($user, 'Ryokan', '1200.00', $account->id, null, null);

    $html = Livewire::actingAs($user)
        ->test(PotsPage::class)
        ->set('operationPotId', $pot->id)
        ->html();

    expect(potAmountBox($html, $fieldId))
        ->toContain('placeholder="0.00"')
        ->toContain('inputmode="decimal"');
})->with([
    'fund-amount',
    'fund-amount-sheet',
    'withdraw-amount',
    'withdraw-amount-sheet',
    'move-amount',
    'move-amount-sheet',
]);
