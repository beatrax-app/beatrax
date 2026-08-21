<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\ValueObjects\Money;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('defaults the currency property to the user default_currency_view preference when no URL override is present', function (): void {
    $this->fixtureUser->update(['default_currency_view' => 'original']);

    Livewire::test(TransactionsList::class)
        ->assertSet('currency', 'original');

    $this->fixtureUser->update(['default_currency_view' => 'eur_only']);

    Livewire::test(TransactionsList::class)
        ->assertSet('currency', 'eur');
})->group('phase-3');

it('overrides the user preference when ?currency=eur is present in the URL', function (): void {
    $this->fixtureUser->update(['default_currency_view' => 'original']);

    Livewire::withQueryParams(['currency' => 'eur'])
        ->test(TransactionsList::class)
        ->assertSet('currency', 'eur');
})->group('phase-3');

it('overrides the user preference when ?currency=original is present in the URL', function (): void {
    $this->fixtureUser->update(['default_currency_view' => 'eur_only']);

    Livewire::withQueryParams(['currency' => 'original'])
        ->test(TransactionsList::class)
        ->assertSet('currency', 'original');
})->group('phase-3');

it('renders two lines for a foreign-currency row in original mode', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'USD',
        'settled_amount_minor' => -1207,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'USD Merchant',
    ]);

    $nativeUsd = Money::ofMinor(-1299, 'USD')->format();
    $settledEur = Money::ofMinor(-1207, 'EUR')->format();

    Livewire::test(TransactionsList::class)
        ->set('currency', 'original')
        ->assertSeeText($nativeUsd)
        ->assertSeeText($settledEur);
})->group('phase-3');

it('renders one line for a foreign-currency row in eur mode', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'USD',
        'settled_amount_minor' => -1207,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'USD Merchant',
    ]);

    $nativeUsd = Money::ofMinor(-1299, 'USD')->format();
    $settledEur = Money::ofMinor(-1207, 'EUR')->format();

    Livewire::test(TransactionsList::class)
        ->set('currency', 'eur')
        ->assertSeeText($settledEur)
        ->assertDontSeeText($nativeUsd);
})->group('phase-3');

it('renders one line for an EUR-native row in original mode', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'EUR Merchant',
    ]);

    // secondaryAmount is null on a EUR-native row in original mode, so the
    // second-line class signature must be absent. The amount still appears
    // twice: the desktop table row and the phone card are both in the DOM,
    // toggled by CSS rather than by rendering only one of them.
    $eur = Money::ofMinor(-2500, 'EUR')->format();

    $component = Livewire::test(TransactionsList::class)
        ->set('currency', 'original');

    $body = $component->html();
    expect(substr_count($body, $eur))->toBe(2);
    expect($body)->not->toContain('mt-1 block text-xs text-slate-500');
})->group('phase-3');

it('keeps the URL clean when the toggle is on the default value', function (): void {
    // Asserted on the dehydrated effects array rather than on a URL: what
    // matters is that the effect carries `except: ''`, so a refresh at the
    // sentinel value strips `?currency=` instead of pinning it.
    $this->fixtureUser->update(['default_currency_view' => 'eur_only']);

    $component = Livewire::test(TransactionsList::class);
    $effects = $component->effects;

    expect($effects)->toHaveKey('url');
    /** @var array<string, array<string, mixed>> $urlEffects */
    $urlEffects = $effects['url'];
    expect($urlEffects)->toHaveKey('currency');
    expect($urlEffects['currency']['except'] ?? null)->toBe('');
})->group('phase-3');
