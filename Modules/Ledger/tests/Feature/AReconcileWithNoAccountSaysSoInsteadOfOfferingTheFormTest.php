<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Currency;

// A reconcile holds a statement against one account's cleared rows, and a
// fresh install has no account. The page opened on the whole form anyway: a
// select carrying only its placeholder, a date picker, a balance field, and
// Check, which reported a difference computed from nothing.

function reconcileGateReader(string $username): User
{
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['minor_unit' => 2]);

    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

it('says there is no account rather than offering controls with nothing to act on', function (): void {
    $html = Livewire::actingAs(reconcileGateReader('reconcile-gate-empty'))
        ->test(ReconcilePage::class)
        ->html();

    $page = RenderedMarkup::of($html);

    expect($page->has('[data-testid="reconcile-no-accounts"]'))->toBeTrue()
        ->and($page->text())->toContain(Lang::get('ledger::account_currency.no_accounts'))
        ->and($page->has('select'))->toBeFalse()
        ->and($page->has('input'))->toBeFalse()
        ->and($html)->not->toContain('wire:click="checkDiscrepancy"')
        ->and($html)->not->toContain('wire:click="confirmReconcile"');
});

// The gate has to be the account, not the page: without this the sentence
// above is indistinguishable from a form that never renders.
it('offers the form as soon as there is an account to reconcile', function (): void {
    $user = reconcileGateReader('reconcile-gate-one');

    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN Betalen',
        'slug' => 'reconcile-gate-one-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000043',
        'default_currency' => 'EUR',
    ]);

    $html = Livewire::actingAs($user)->test(ReconcilePage::class)->html();

    expect(RenderedMarkup::of($html)->has('[data-testid="reconcile-no-accounts"]'))->toBeFalse()
        ->and(RenderedMarkup::of($html)->has('select'))->toBeTrue()
        ->and($html)->toContain('wire:click="checkDiscrepancy"');
});
