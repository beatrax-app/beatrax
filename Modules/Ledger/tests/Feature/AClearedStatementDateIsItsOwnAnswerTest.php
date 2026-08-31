<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\ClearedStatus;

// The date picker beside the statement balance offers Clear, and it writes
// back the empty string. Every figure here is bounded by posted_at <= that
// date, so without one there is nothing to sum — yet the cleared balance read
// zero and the null difference reached a formatter typed `int`: a 500.

beforeEach(function (): void {
    $this->user = User::create(['username' => 'cleared-date-fixture', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-cleared-date-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000021',
        'default_currency' => 'EUR',
    ]);
    $this->run = $this->makeImportRun($this->user);
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => ClearedStatus::Cleared->value,
        'amount_minor' => -5000,
        'posted_at' => '2026-06-10',
    ]);
});

it('renders the page after the date picker clears the statement date under an entered balance', function (): void {
    $html = (string) Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementBalance', '-50,00')
        ->set('statementDate', '')
        ->html();

    expect($html)->toContain('choose a statement date');
});

it('reports no cleared balance and no advice while the statement date is missing', function (): void {
    $page = Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementBalance', '-50,00')
        ->set('statementDate', '');

    $page->assertViewHas('clearedBalanceMinor', null)
        ->assertViewHas('differenceMinor', null)
        ->assertViewHas('isMatched', false);

    // €0.00 is what the account reads as while the window is unknown, and it
    // holds -€50.00; the advice would name a difference nothing has computed.
    expect((string) $page->html())
        ->not->toContain('data-testid="reconcile-advice"')
        ->not->toContain('€0.00');
});

it('answers again as soon as a statement date is chosen', function (): void {
    Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementBalance', '-50,00')
        ->set('statementDate', '')
        ->set('statementDate', '2026-06-15')
        ->assertViewHas('clearedBalanceMinor', -5000)
        ->assertViewHas('differenceMinor', 0)
        ->assertViewHas('isMatched', true);
});
