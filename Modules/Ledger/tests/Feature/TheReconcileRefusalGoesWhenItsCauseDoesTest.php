<?php

declare(strict_types=1);

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\ClearedStatus;

// Complete refuses a mismatch by writing a line above the panel. The panel
// recomputes on every keystroke and the line did not, so a reader who closed
// the gap read "does not match the cleared balance yet" directly above a pill
// saying matched and a Complete button that had just re-enabled itself.

beforeEach(function (): void {
    $this->user = User::create(['username' => 'refusal-fixture', 'password' => 'fixture-password', 'period_start_day' => 1]);
    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-refusal-fixture',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000022',
        'default_currency' => 'EUR',
    ]);
    $this->run = $this->makeImportRun($this->user);
    $this->makeTransaction($this->user, $this->account, $this->run, [
        'status' => ClearedStatus::Cleared->value,
        'amount_minor' => -5000,
        'posted_at' => '2026-06-10',
    ]);
});

it('drops the mismatch refusal once the entered balance matches', function (): void {
    $page = Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementDate', '2026-06-15')
        ->set('statementBalance', '-40,00')
        ->call('confirmReconcile');

    $page->assertSet('error', 'The statement balance does not match the cleared balance yet — adjust cleared rows or the entered balance until the difference is zero.');

    $page->set('statementBalance', '-50,00')
        ->assertViewHas('isMatched', true)
        ->assertSet('error', '');
});

it('drops the refusal once the statement date moves too', function (): void {
    $page = Livewire::actingAs($this->user)
        ->test(ReconcilePage::class, ['accountId' => $this->account->id])
        ->set('statementDate', '2026-06-05')
        ->set('statementBalance', '-50,00')
        ->call('confirmReconcile');

    expect($page->get('error'))->not->toBe('');

    $page->set('statementDate', '2026-06-15')
        ->assertViewHas('isMatched', true)
        ->assertSet('error', '');
});

// The line is written by confirmReconcile() and by nothing else; no control
// binds it. A payload that could set it would put the app's own error styling
// around a sentence the app never wrote.
it('refuses a client write to the refusal line', function (): void {
    $page = Livewire::actingAs($this->user)->test(ReconcilePage::class, ['accountId' => $this->account->id]);

    expect(static fn (): mixed => $page->set('error', 'Your session has expired. Sign in again at'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
