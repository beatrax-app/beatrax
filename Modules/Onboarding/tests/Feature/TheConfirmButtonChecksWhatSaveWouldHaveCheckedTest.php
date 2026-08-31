<?php

declare(strict_types=1);

use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Onboarding\Internal\Http\Livewire\StartingBalanceCard;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'sbc-confirm-check',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var Account $account */
    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ASN account',
        'slug' => 'asn-confirm-check',
        'kind' => 'bank',
        'iban' => 'NL08ASNB0123458888',
        'default_currency' => 'EUR',
    ]);
});

// The detected pair and the candidate list are locked, so a figure the rule
// has to refuse reaches the card the one way it can: mounted, as the detector
// hands it over.
/**
 * @param  list<array{minor: int, date: string, sourceLabel: string}>|list<array<string, string>>  $alternativeCandidates
 */
function detectedCard(int $accountId, int $minor, string $date, array $alternativeCandidates = []): Testable
{
    return Livewire::test(StartingBalanceCard::class, [
        'accountId' => $accountId,
        'accountLabel' => 'ASN account',
        'accountShort' => 'NL08 ASNB · 8888',
        'currency' => 'EUR',
        'detectedMinor' => $minor,
        'detectedDate' => $date,
        'state' => 'detected',
        'alternativeCandidates' => $alternativeCandidates,
    ]);
}

it('refuses to confirm a detected figure past the amount range', function (): void {
    detectedCard($this->account->id, PHP_INT_MAX, '2026-02-01')
        ->call('confirm')
        ->assertNotDispatched('starting-balance.confirmed')
        ->assertSet('isConfirmed', false)
        ->assertSet('validationError', fn (string $message): bool => $message !== '');
});

it('refuses to confirm a detected date in the future', function (): void {
    detectedCard($this->account->id, 250000, '2099-01-01')
        ->call('confirm')
        ->assertNotDispatched('starting-balance.confirmed')
        ->assertSet('isConfirmed', false)
        ->assertSet('validationError', Lang::get('onboarding::starting_balance.errors.future_date'));
});

it('refuses to confirm a detected date the parser cannot read', function (): void {
    detectedCard($this->account->id, 250000, 'whenever')
        ->call('confirm')
        ->assertNotDispatched('starting-balance.confirmed')
        ->assertSet('isConfirmed', false);
});

it('refuses to hand on a conflict candidate that is not a candidate', function (): void {
    detectedCard($this->account->id, 250000, '2026-02-01', [['minor' => PHP_INT_MAX, 'date' => '2099-01-01', 'sourceLabel' => 'x']])
        ->call('pickConflictCandidate', 0)
        ->assertNotDispatched('starting-balance.confirmed')
        ->assertSet('isConfirmed', false);
});

it('renders a conflict candidate row that arrived without its keys', function (): void {
    detectedCard($this->account->id, 250000, '2026-02-01', [['a' => 'b']])
        ->call('pickConflictCandidate', 0)
        ->assertOk()
        ->assertNotDispatched('starting-balance.confirmed');
});

it('still confirms a detected figure the detector could genuinely have produced', function (): void {
    detectedCard($this->account->id, 250000, '2026-02-01')
        ->call('confirm')
        ->assertDispatched(
            'starting-balance.confirmed',
            accountId: $this->account->id,
            minor: 250000,
            date: '2026-02-01',
        )
        ->assertSet('isConfirmed', true);
});
