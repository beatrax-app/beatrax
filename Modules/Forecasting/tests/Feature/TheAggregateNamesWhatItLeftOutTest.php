<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

// "Combined balance across every account" is a claim the total cannot keep for
// a currency the rate table cannot reach: that account is left out silently,
// while its own tab sits two lines above the sentence.

const TANW_HORIZON = 30;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'tanw',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function tanwAccount(DatabaseManager $db, int $userId, string $currency, string $name): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => $name,
        'slug' => 'tanw-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00TANW'.strtoupper($hex),
        'default_currency' => $currency,
        'starting_balance_minor' => 100_000,
        'starting_balance_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function tanwSeedRun(DatabaseManager $db, int $userId, array $accountsById): void
{
    $accounts = [];
    foreach ($accountsById as $accountId => $currency) {
        $accounts[(string) $accountId] = [
            'account_id' => $accountId,
            'account_name' => 'TANW '.$currency,
            'default_currency' => $currency,
            'today_balance_minor' => 100_000,
            'anchor_source' => 'sum_of_transactions',
            'points' => [[
                'date' => CarbonImmutable::now()->toDateString(),
                'low_minor' => 100_000,
                'point_minor' => 100_000,
                'high_minor' => 100_000,
                'currency' => $currency,
            ]],
        ];
    }

    $db->connection()->table('forecast_runs')->insert([
        'user_id' => $userId,
        'scenario_id' => null,
        'horizon_days' => TANW_HORIZON,
        'status' => 'complete',
        'result_json' => json_encode([
            'as_of' => CarbonImmutable::now()->toDateString(),
            'horizon_days' => TANW_HORIZON,
            'accounts' => $accounts,
        ]),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);
}

it('names the currency the combined balance had to leave out', function (): void {
    $euroId = tanwAccount($this->db, $this->user->id, Currency::Eur->value, 'TANW Euro');
    $yenId = tanwAccount($this->db, $this->user->id, Currency::Jpy->value, 'TANW Yen');
    tanwSeedRun($this->db, $this->user->id, [$euroId => Currency::Eur->value, $yenId => Currency::Jpy->value]);

    // The state being reproduced is a pair the rate table cannot reach. The
    // bundled snapshot carries this one, so the fixture takes it away.
    $this->db->connection()->table('exchange_rates')
        ->where('quote_currency', Currency::Jpy->value)
        ->orWhere('base_currency', Currency::Jpy->value)
        ->delete();

    $content = (string) $this->actingAs($this->user)->get('/forecast')->getContent();

    expect($content)->toContain('data-not-converted="true"')
        ->and($content)->toContain(Lang::get('core::money.not_converted', ['list' => Currency::Jpy->value]))
        ->and($content)->toContain('TANW Yen');
});

it('says nothing about conversion when every account reaches the total', function (): void {
    $euroId = tanwAccount($this->db, $this->user->id, Currency::Eur->value, 'TANW Euro');
    tanwSeedRun($this->db, $this->user->id, [$euroId => Currency::Eur->value]);

    $content = (string) $this->actingAs($this->user)->get('/forecast')->getContent();

    expect($content)->not->toContain('data-not-converted="true"');
});
