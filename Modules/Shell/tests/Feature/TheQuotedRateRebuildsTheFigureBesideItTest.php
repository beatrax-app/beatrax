<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Shell\Internal\Http\Livewire\NetWorthCard;

beforeEach(function (): void {
    App::setLocale('en');
    $this->db = app(DatabaseManager::class);
    $this->db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    $this->user = User::create([
        'username' => 'yen-rate-line',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-07 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('quotes a euro-per-yen rate at enough places to reach the figure it converted', function (): void {
    $this->db->connection()->table('accounts')->insert([
        'user_id' => $this->user->id,
        'name' => 'Japan Trip Card',
        'slug' => 'yen-rate-line-card',
        'kind' => 'bank',
        'iban' => 'JP00CARD00000001',
        'default_currency' => Currency::Jpy->value,
        'opening_balance_minor' => 112_600,
        'opening_balance_as_of_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
    $this->db->connection()->table('exchange_rates')->updateOrInsert(
        ['base_currency' => 'EUR', 'quote_currency' => Currency::Jpy->value, 'rate_date' => '2026-06-05', 'source' => 'ecb'],
        ['rate' => '159.10', 'created_at' => now(), 'updated_at' => now()],
    );

    Livewire::test(NetWorthCard::class)
        ->call('toggle')
        ->assertSee('1 JPY = 0.00629 EUR')
        ->assertDontSee('1 JPY = 0.0063 EUR');
});
