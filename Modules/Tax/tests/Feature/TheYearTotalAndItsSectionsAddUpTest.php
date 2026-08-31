<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Tax\Internal\Services\TaxYearQuery;

// TaxYearData's own contract is that the sections add up to the headline. The
// year total converted one bucket per currency while each section converted its
// own slice of the same bucket, so the rounding landed differently in the two
// and /tax printed three sections that did not sum to the figure above them.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-23 09:00:00');
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Usd->value,
        'rate_date' => '2026-08-23',
        'rate' => '1.07',
        'source' => 'ecb',
        'created_at' => '2026-08-23 00:00:00',
        'updated_at' => '2026-08-23 00:00:00',
    ]);

    $this->user = User::query()->create([
        'username' => 'tax-sections-add-up',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function tsauCategory(DatabaseManager $db, int $userId, string $name, int $sortOrder): int
{
    return $db->connection()->table('tax_deduction_categories')->insertGetId([
        'user_id' => $userId, 'name' => $name, 'short_name' => $name,
        'sort_order' => $sortOrder, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function tsauTaggedRow(DatabaseManager $db, int $userId, int $minor, string $currency, ?int $categoryId, string $type = 'expense'): void
{
    $hex = bin2hex(random_bytes(5));

    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'Revolut '.$hex, 'slug' => 'tsau-'.$hex, 'kind' => 'bank',
        'iban' => 'GB00TSAU'.strtoupper(substr($hex, 0, 8)), 'default_currency' => Currency::Eur->value,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'revolut-csv', 'raw_file_path' => '/tmp/tsau-'.$hex.'.csv',
        'sha256' => hash('sha256', 'tsau-'.$hex), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'committed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tsau-fp-'.$hex), 'fingerprint_version' => 3,
        'posted_at' => '2026-03-01', 'booked_at' => '2026-03-01 00:00:00', 'value_date' => '2026-03-01',
        'amount_minor' => $minor, 'currency' => $currency,
        'settled_amount_minor' => $minor, 'settled_currency' => $currency,
        'counterparty_normalized' => 'vendor', 'counterparty_name' => 'Vendor',
        'normalization_version' => 1, 'description' => 'fixture', 'type' => $type,
        'source_format' => 'revolut-csv', 'source_row_index' => 1,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId, 'transaction_id' => $txId, 'deduction_category_id' => $categoryId,
        'tax_year_override' => null, 'note' => null,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('adds its own sections up to the deductions headline across a currency', function (): void {
    $office = tsauCategory($this->db, $this->user->id, 'Office', 1);
    $travel = tsauCategory($this->db, $this->user->id, 'Travel', 2);
    $tools = tsauCategory($this->db, $this->user->id, 'Tools', 3);

    // USD 3.33 + 3.33 + 3.34 at 1.07 per euro: each slice rounds down on its
    // own, the whole USD 10.00 bucket rounds up.
    tsauTaggedRow($this->db, $this->user->id, -333, Currency::Usd->value, $office);
    tsauTaggedRow($this->db, $this->user->id, -333, Currency::Usd->value, $travel);
    tsauTaggedRow($this->db, $this->user->id, -334, Currency::Usd->value, $tools);

    $data = app(TaxYearQuery::class)->forUser($this->user->id, 2026);

    $sections = 0;
    foreach ($data->categories as $category) {
        $sections += is_int($category['subtotalMinor']) ? $category['subtotalMinor'] : 0;
    }

    expect($data->deductionsTotalMinor)->toBe(935)
        ->and($sections)->toBe($data->deductionsTotalMinor);
});

it('adds its income sections up to the income headline the same way', function (): void {
    $fees = tsauCategory($this->db, $this->user->id, 'Fees', 1);
    $royalties = tsauCategory($this->db, $this->user->id, 'Royalties', 2);
    $grants = tsauCategory($this->db, $this->user->id, 'Grants', 3);

    tsauTaggedRow($this->db, $this->user->id, 333, Currency::Usd->value, $fees, 'income');
    tsauTaggedRow($this->db, $this->user->id, 333, Currency::Usd->value, $royalties, 'income');
    tsauTaggedRow($this->db, $this->user->id, 334, Currency::Usd->value, $grants, 'income');

    $data = app(TaxYearQuery::class)->forUser($this->user->id, 2026);

    $sections = 0;
    foreach ($data->categories as $category) {
        $sections += is_int($category['incomeSubtotalMinor']) ? $category['incomeSubtotalMinor'] : 0;
    }

    expect($data->incomeTotalMinor)->toBe(935)
        ->and($sections)->toBe($data->incomeTotalMinor);
});
