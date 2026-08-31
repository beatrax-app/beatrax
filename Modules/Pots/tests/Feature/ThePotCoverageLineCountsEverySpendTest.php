<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotBalanceQuery;

// The coverage line under a pot reads "<category>: X spent · Y in pot". The
// spend half was filtered to the pot's own currency, so a reader who buys
// groceries on a card denominated elsewhere was shown a figure that had
// silently dropped those rows — beside a balance that had not.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12 09:00:00'));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Usd->value,
        'rate_date' => '2026-08-01',
        'rate' => '2.0',
        'source' => 'ecb',
        'created_at' => '2026-08-01 00:00:00',
        'updated_at' => '2026-08-01 00:00:00',
    ]);

    $this->user = User::create([
        'username' => 'pot-coverage-ccy',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);

    $this->accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id, 'name' => 'ASN', 'slug' => 'pcc-asn', 'kind' => 'bank',
        'iban' => 'NL57PCCB0123456789', 'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $this->runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $this->user->id, 'source_format' => 'camt053', 'raw_file_path' => '/tmp/pcc.xml',
        'sha256' => hash('sha256', 'pcc'), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $this->categoryId = $db->connection()->table('categories')->insertGetId([
        'user_id' => $this->user->id, 'name' => 'Groceries', 'slug' => 'pcc-groceries', 'kind' => 'expense',
        'display_order' => 100, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function pccSpend(DatabaseManager $db, int $userId, int $accountId, int $runId, int $categoryId, int $minor, string $currency): void
{
    $hex = bin2hex(random_bytes(5));
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'category_id' => $categoryId,
        'fingerprint' => hash('sha256', 'pcc-'.$hex), 'fingerprint_version' => 3,
        'posted_at' => '2026-08-05', 'booked_at' => '2026-08-05 09:00:00', 'value_date' => '2026-08-05',
        'amount_minor' => -$minor, 'currency' => $currency,
        'settled_amount_minor' => -$minor, 'settled_currency' => $currency,
        'counterparty_name' => 'Shop', 'counterparty_normalized' => 'shop', 'normalization_version' => 1,
        'type' => 'expense', 'source_format' => 'camt053', 'source_row_index' => random_int(1, 999999),
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('converts the spend that settled elsewhere instead of dropping it', function (): void {
    Pot::factory()->create([
        'user_id' => $this->user->id, 'account_id' => $this->accountId,
        'name' => 'Buffer', 'category_id' => $this->categoryId, 'currency' => Currency::Eur->value,
    ]);

    pccSpend($this->db, $this->user->id, $this->accountId, $this->runId, $this->categoryId, 2500, Currency::Eur->value);
    pccSpend($this->db, $this->user->id, $this->accountId, $this->runId, $this->categoryId, 2000, Currency::Usd->value);

    $row = collect(app(PotBalanceQuery::class)->forUser($this->user))->firstWhere('name', 'Buffer');

    // USD 20.00 at 2.0 to the euro is EUR 10.00, so EUR 25.00 + EUR 10.00.
    expect($row->categorySpentMinor)->toBe(3500)
        ->and($row->categorySpentUnconverted)->toBe([]);
});

it('names the currency the coverage line could not price', function (): void {
    Pot::factory()->create([
        'user_id' => $this->user->id, 'account_id' => $this->accountId,
        'name' => 'Buffer', 'category_id' => $this->categoryId, 'currency' => Currency::Eur->value,
    ]);

    pccSpend($this->db, $this->user->id, $this->accountId, $this->runId, $this->categoryId, 2500, Currency::Eur->value);
    pccSpend($this->db, $this->user->id, $this->accountId, $this->runId, $this->categoryId, 500000, Currency::Jpy->value);

    $row = collect(app(PotBalanceQuery::class)->forUser($this->user))->firstWhere('name', 'Buffer');

    expect($row->categorySpentMinor)->toBe(2500)
        ->and($row->categorySpentUnconverted)->toBe([Currency::Jpy->value]);
});
