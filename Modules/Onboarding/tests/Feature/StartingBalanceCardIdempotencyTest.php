<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\StartingBalanceCard;

/*
 * Confirms the card emits a `starting-balance.confirmed` event with a
 * stable payload across every confirm/save cycle. The first-import
 * step listens for the event and aggregates the payload into a per-
 * account `$balanceConfirmations` map — the listener is keyed on
 * `accountId` so a repeat dispatch overwrites the prior entry, but the
 * dispatch payload must always carry the same three keys so the parent
 * never sees a half-populated row.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'sbc-idempotency',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var Account $account */
    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN account',
        'slug' => 'asn-idem',
        'kind' => 'bank',
        'iban' => 'NL08ASNB0123459999',
        'default_currency' => 'EUR',
    ]);
});

it('emits starting-balance.confirmed with the same payload across confirm and save cycles', function (): void {
    $component = Livewire::test(StartingBalanceCard::class, [
        'accountId' => $this->account->id,
        'accountLabel' => 'ASN account',
        'accountShort' => 'NL18 ASNB · 9999',
        'currency' => 'EUR',
        'detectedMinor' => 250000,
        'detectedDate' => '2026-02-01',
        'state' => 'detected',
    ]);

    // First confirm — dispatches with the detector's values.
    $component
        ->call('confirm')
        ->assertDispatched(
            'starting-balance.confirmed',
            accountId: $this->account->id,
            minor: 250000,
            date: '2026-02-01',
        );

    // Re-edit and save with a tweaked value — the listener overwrites
    // the prior entry, but the dispatch payload still carries all
    // three keys with the new values.
    $component
        ->call('startEdit')
        ->set('editedMinor', 300000)
        ->set('editedDate', '2026-02-15')
        ->call('save')
        ->assertDispatched(
            'starting-balance.confirmed',
            accountId: $this->account->id,
            minor: 300000,
            date: '2026-02-15',
        );
});
