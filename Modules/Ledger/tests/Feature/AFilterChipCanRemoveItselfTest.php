<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// The no-results panel exists to invite a filter to be dropped, and its account
// and category chips removed themselves with a PHP expression written into an
// attribute the browser evaluates as JavaScript:
// `array_filter($filterAccounts, fn($id) => …)`. `fn($id) =>` is a syntax error
// there, so both close buttons threw in the evaluator and did nothing.
it('drops the one filter a chip names and keeps the rest', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('filterAccounts', [11, 22, 33])
        ->set('filterCategories', [44, 55]);

    $component->call('removeAccountFilter', '22');
    $component->call('removeCategoryFilter', '44');

    expect($component->instance()->filterAccounts)->toBe([11, 33])
        ->and($component->instance()->filterCategories)->toBe([55]);
});

// Paging is reset by the `updated` hook, which fires for a property the wire
// writes and not for one a method writes — so these two reset it themselves, or
// the narrowed list starts mid-history the way refining a filter used to.
it('sends the narrowed list back to its first page', function (): void {
    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('filterAccounts', [11, 22]);

    $component->set('cursorId', 900);
    $component->call('removeAccountFilter', '11');

    expect($component->instance()->cursorId)->toBeNull();
});

// The half no arch test can reach: what the browser is actually handed.
it('hands the browser a call it can evaluate, with the id quoted', function (): void {
    $html = Livewire::test(TransactionsList::class)
        ->set('currency', 'eur_only')
        ->set('searchQuery', 'nothing-matches-this-query-at-all')
        ->set('filterAccounts', [$this->account->id])
        ->html();

    expect($html)->toContain("removeAccountFilter('".$this->account->id."')")
        ->and($html)->not->toContain('array_filter')
        ->and($html)->not->toContain('fn($id)');
});
