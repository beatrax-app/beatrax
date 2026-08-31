<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Shell\Internal\Http\Livewire\SpendingTrendCard;

// The card totalled only the buckets already in the reader's currency, so a row
// settled elsewhere was neither counted nor mentioned. A total that leaves
// money out has to say so, the way the tiles above it already do.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 09:00:00'));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();

    $this->user = User::create([
        'username' => 'trend-card-unconverted',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function trendCardSpend(DatabaseManager $db, int $userId, int $minor, string $currency, string $postedAt): void
{
    $hex = bin2hex(random_bytes(5));
    $categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId, 'name' => 'Travel', 'slug' => 'tcu-'.$hex,
        'kind' => 'expense', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN '.$hex, 'slug' => 'tcu-'.$hex, 'kind' => 'bank',
        'iban' => 'NL00TCU'.strtoupper(substr($hex, 0, 8)), 'default_currency' => $currency,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/tcu-'.$hex.'.csv',
        'sha256' => hash('sha256', 'tcu-'.$hex), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'category_id' => $categoryId,
        'fingerprint' => hash('sha256', 'tcu-tx-'.$hex), 'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00', 'value_date' => $postedAt,
        'amount_minor' => -$minor, 'currency' => $currency,
        'settled_amount_minor' => -$minor, 'settled_currency' => $currency,
        'counterparty_normalized' => 'tcu', 'counterparty_name' => 'TCU', 'normalization_version' => 3,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'fingerprint_version' => 3, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('names the currency it had no rate for beside the total that leaves it out', function (): void {
    $period = app(PeriodQuery::class)->current();
    trendCardSpend($this->db, $this->user->id, 160245, Currency::Eur->value, $period->start->addDays(2)->toDateString());
    trendCardSpend($this->db, $this->user->id, 99900, 'ZAR', $period->start->addDays(3)->toDateString());

    Livewire::test(SpendingTrendCard::class)
        ->assertSee('data-not-converted', escape: false)
        ->assertSee('ZAR');
});

it('says nothing about conversion when every row was in the reader\'s own currency', function (): void {
    $period = app(PeriodQuery::class)->current();
    trendCardSpend($this->db, $this->user->id, 160245, Currency::Eur->value, $period->start->addDays(2)->toDateString());

    Livewire::test(SpendingTrendCard::class)
        ->assertDontSee('data-not-converted', escape: false);
});
