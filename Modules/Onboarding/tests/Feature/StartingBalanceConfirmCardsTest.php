<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\StartingBalanceCard;

/*
 * Verifies the StartingBalanceCard child component renders correctly
 * for the two seed cases the wizard surfaces inside the first-import
 * step's starting-balance section:
 *
 *  1. Detected — one detector fired with a non-zero value: the card
 *     mounts in the `detected` state, pre-filling the numeric value
 *     and the ISO date from the supplied detector candidate.
 *  2. Manual-entry — no detector fired for the account (the PayPal
 *     case): the card mounts in the `manual-entry` state with empty
 *     inputs and renders the manual-entry lede so the user knows the
 *     wizard couldn't auto-detect.
 *
 * Both behaviours are owned by the card itself — the wizard's first-
 * import step just decides which state to mount each card in based on
 * whether DetectStartingBalancesQuery returned a candidate for the
 * account.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'sbc-confirm',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('renders a starting-balance card in the detected state with the detector value pre-filled', function (): void {
    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN account',
        'slug' => 'asn-confirm',
        'kind' => 'bank',
        'iban' => 'NL54ASNB0123454321',
        'default_currency' => 'EUR',
    ]);

    Livewire::test(StartingBalanceCard::class, [
        'accountId' => $account->id,
        'accountLabel' => 'ASN account',
        'accountShort' => 'NL18 ASNB · 4321',
        'currency' => 'EUR',
        'detectedMinor' => 123456,
        'detectedDate' => '2026-01-01',
        'state' => 'detected',
    ])
        ->assertSee('NL18 ASNB · 4321')
        ->assertSee('ASN account')
        ->assertSee('2026-01-01');
});

it('renders a starting-balance card in the manual-entry state when no detector fired', function (): void {
    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'PayPal balance',
        'slug' => 'pp-confirm',
        'kind' => 'paypal',
        'iban' => 'PAYPAL-WALLET',
        'default_currency' => 'EUR',
    ]);

    Livewire::test(StartingBalanceCard::class, [
        'accountId' => $account->id,
        'accountLabel' => 'PayPal balance',
        'accountShort' => 'PAYPAL · wallet',
        'currency' => 'EUR',
        'detectedMinor' => null,
        'detectedDate' => null,
        'state' => 'manual-entry',
    ])
        ->assertSee('PAYPAL · wallet')
        ->assertSee('manually');
});
