<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Ledger\Models\Account;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    /** @var Account $icsAccount */
    $icsAccount = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'ICS-CARD')
        ->firstOrFail();
    $this->icsAccount = $icsAccount;
    $this->run = $this->makeImportRun($this->fixtureUser);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders the Effective rate line when fx_rate_used is populated', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->icsAccount, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'USD',
        'settled_amount_minor' => -1207,
        'settled_currency' => 'EUR',
        'fx_rate_used' => '0.92917629',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'USD Merchant',
    ]);

    $response = $this->get(route('transactions.show', $tx->id));

    $response->assertOk();
    $response->assertSee('Effective rate', false);
    $response->assertSee('USD', false);
})->group('phase-3');

it('omits the Effective rate line when fx_rate_used is NULL', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->icsAccount, $this->run, [
        'amount_minor' => -2500,
        'currency' => 'EUR',
        'settled_amount_minor' => -2500,
        'settled_currency' => 'EUR',
        'fx_rate_used' => null,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'EUR Merchant',
    ]);

    $response = $this->get(route('transactions.show', $tx->id));

    $response->assertOk();
    $response->assertDontSee('Effective rate', false);
})->group('phase-3');

it('formats the rate as EUR-per-unit-native with 3 decimal places (€0.929 / USD)', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->icsAccount, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'USD',
        'settled_amount_minor' => -1207,
        'settled_currency' => 'EUR',
        'fx_rate_used' => '0.92917629',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'USD Merchant',
    ]);

    $response = $this->get(route('transactions.show', $tx->id));

    $response->assertOk();
    $response->assertSee('€0.929 / USD', false);
})->group('phase-3');

it('renders the Includes any ICS markup helper text below the rate', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->icsAccount, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'USD',
        'settled_amount_minor' => -1207,
        'settled_currency' => 'EUR',
        'fx_rate_used' => '0.92917629',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'USD Merchant',
    ]);

    $response = $this->get(route('transactions.show', $tx->id));

    $response->assertOk();
    $response->assertSee('Includes any ICS markup.', false);
})->group('phase-3');

it('returns 404 when the transaction belongs to a different user', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->icsAccount, $this->run, [
        'amount_minor' => -1299,
        'currency' => 'USD',
        'settled_amount_minor' => -1207,
        'settled_currency' => 'EUR',
        'fx_rate_used' => '0.92917629',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'USD Merchant',
    ]);

    $intruder = User::query()->create([
        'username' => 'intruder',
        'password' => 'intruder-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($intruder);

    $response = $this->get(route('transactions.show', $tx->id));

    $response->assertStatus(404);
})->group('phase-3');
