<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\FX\Public\Support\BundledRates;
use Modules\Shell\Internal\Http\Livewire\NetWorthCard;

// The card said "2 balances not converted" and named the accounts only in the
// breakdown, which is collapsed until the reader presses Breakdown. So the one
// line that admits the total is short named nothing, and C1-R15 / B10-R17 ask
// for the accounts by name.

beforeEach(function (): void {
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-07 12:00:00'));
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'nwcn-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function nwcnAccount(DatabaseManager $db, int $userId, string $name, int $openingMinor, string $currency): int
{
    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'nwcn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00NWCN'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => $currency,
        'opening_balance_minor' => $openingMinor,
        'opening_balance_as_of_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('names the accounts it could not convert without the breakdown being opened', function (): void {
    nwcnAccount($this->db, (int) $this->user->id, 'Household current', 200_000, 'EUR');
    // No rate is seeded for either pair, so both are left out of the total.
    nwcnAccount($this->db, (int) $this->user->id, 'Tokyo savings', 5_000_000, 'JPY');
    nwcnAccount($this->db, (int) $this->user->id, 'Buenos Aires cash', 57_500, 'ARS');

    $html = Livewire::test(NetWorthCard::class)->html();

    expect($html)->toContain(Lang::get('core::money.not_converted', [
        'list' => 'Buenos Aires cash, Tokyo savings',
    ]))
        // The sentence the count used to make, in the shape it used to make it.
        ->and($html)->not->toContain('2 balances not converted');
});

it('says nothing of the kind when every balance converted', function (): void {
    nwcnAccount($this->db, (int) $this->user->id, 'Household current', 200_000, 'EUR');

    Livewire::test(NetWorthCard::class)->assertDontSee('not converted');
});
