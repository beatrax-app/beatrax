<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\CategorySpendTrendQuery;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;

// The dashboard shows the Out tile and "this month vs last" one card apart.
// The tile converts every currency the period holds; the trend filtered rows
// down to the reader's display currency, so a single yen row made the two
// figures disagree on one screen.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 09:00:00'));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Jpy->value,
        'rate_date' => '2026-08-24',
        'rate' => '159.00',
        'source' => 'ecb',
        'created_at' => '2026-08-24 00:00:00',
        'updated_at' => '2026-08-24 00:00:00',
    ]);

    $this->user = User::create([
        'username' => 'trend-vs-tile',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function tvtCategory(DatabaseManager $db, int $userId, string $name): int
{
    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId, 'name' => $name, 'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function tvtSpend(DatabaseManager $db, int $userId, ?int $categoryId, int $minor, string $currency, string $postedAt): void
{
    $hex = bin2hex(random_bytes(5));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN '.$hex, 'slug' => 'tvt-'.$hex, 'kind' => 'bank',
        'iban' => 'NL00TVT'.strtoupper(substr($hex, 0, 8)), 'default_currency' => $currency,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/tvt-'.$hex.'.csv',
        'sha256' => hash('sha256', 'tvt-'.$hex), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'category_id' => $categoryId,
        'fingerprint' => hash('sha256', 'tvt-tx-'.$hex), 'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00', 'value_date' => $postedAt,
        'amount_minor' => -$minor, 'currency' => $currency,
        'settled_amount_minor' => -$minor, 'settled_currency' => $currency,
        'counterparty_normalized' => 'tvt', 'counterparty_name' => 'TVT', 'normalization_version' => 3,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'fingerprint_version' => 3, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('gives the trend card the same total the Out tile one card away shows', function (): void {
    $period = app(PeriodQuery::class)->current();
    $groceries = tvtCategory($this->db, $this->user->id, 'Groceries');
    $travel = tvtCategory($this->db, $this->user->id, 'Travel');

    tvtSpend($this->db, $this->user->id, $groceries, 160245, Currency::Eur->value, $period->start->addDays(2)->toDateString());
    tvtSpend($this->db, $this->user->id, $travel, 1000, Currency::Jpy->value, $period->start->addDays(3)->toDateString());

    $tile = app(ThisPeriodAtAGlanceQuery::class)->for($this->user, $period);
    $trend = app(CategorySpendTrendQuery::class)->forUser($this->user);

    expect($tile->outflow->toMinor())->toBe(160874)
        ->and($trend->currentTotalMinor)->toBe($tile->outflow->toMinor());
});

it('names a currency it has no rate for instead of quietly leaving it out', function (): void {
    $period = app(PeriodQuery::class)->current();
    $groceries = tvtCategory($this->db, $this->user->id, 'Groceries');

    tvtSpend($this->db, $this->user->id, $groceries, 160245, Currency::Eur->value, $period->start->addDays(2)->toDateString());
    tvtSpend($this->db, $this->user->id, $groceries, 99900, 'ZAR', $period->start->addDays(3)->toDateString());

    $trend = app(CategorySpendTrendQuery::class)->forUser($this->user);

    expect($trend->currentTotalMinor)->toBe(160245)
        ->and($trend->unconvertedCurrencies)->toBe(['ZAR']);
});

it('still shows the card when the only spend is in a currency it cannot price', function (): void {
    $period = app(PeriodQuery::class)->current();
    $previous = app(PeriodQuery::class)->previous($period);
    $groceries = tvtCategory($this->db, $this->user->id, 'Groceries');

    tvtSpend($this->db, $this->user->id, $groceries, 99900, 'ZAR', $period->start->addDays(3)->toDateString());
    tvtSpend($this->db, $this->user->id, $groceries, 12300, 'CHF', $previous->start->addDays(3)->toDateString());

    $trend = app(CategorySpendTrendQuery::class)->forUser($this->user);

    expect($trend->currentTotalMinor)->toBe(0)
        ->and($trend->hasComparison())->toBeTrue()
        ->and($trend->unconvertedCurrencies)->toBe(['CHF', 'ZAR']);
});
