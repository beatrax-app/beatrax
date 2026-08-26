<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Search\Public\Dto\SearchFilters;
use Modules\Search\Public\Services\SearchQuery;

uses(RefreshDatabase::class);

// The strip above the results counts the rows it just listed. It counted only
// the ones already settled in the reader's reporting currency, so a pound
// reader over a euro ledger read "2 transactions, 0.00 out, 0.00 in" above two
// rows that plainly were not nothing.

function stripRow(DatabaseManager $db, int $userId, int $accountId, int $runId, int $minor, string $currency, int $index): void
{
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'strip-'.$index), 'posted_at' => '2026-08-0'.$index,
        'booked_at' => '2026-08-0'.$index.' 12:00:00', 'value_date' => '2026-08-0'.$index,
        'amount_minor' => $minor, 'currency' => $currency,
        'settled_amount_minor' => $minor, 'settled_currency' => $currency,
        'counterparty_name' => 'ACME INVOICE', 'counterparty_normalized' => 'acme invoice',
        'normalization_version' => 3, 'description' => 'ACME INVOICE '.$index,
        'type' => $minor < 0 ? 'expense' : 'income', 'source_format' => 'asn-csv',
        'source_row_index' => $index, 'fingerprint_version' => 3,
        'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-24 09:00:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value, 'quote_currency' => Currency::Gbp->value,
        'rate_date' => '2026-08-24', 'rate' => '0.80', 'source' => 'ecb',
        'created_at' => '2026-08-24 00:00:00', 'updated_at' => '2026-08-24 00:00:00',
    ]);

    $this->user = User::create([
        'username' => 'search-strip',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Gbp->value,
    ]);
    $this->actingAs($this->user);

    $this->accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id, 'name' => 'ASN', 'slug' => 'strip-asn', 'kind' => 'bank',
        'iban' => 'NL00STRIP', 'default_currency' => Currency::Eur->value,
        'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
    ]);
    $this->runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $this->user->id, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/strip.csv',
        'sha256' => str_repeat('7', 64), 'uploaded_at' => '2026-08-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('converts the rows it listed into the currency the strip is labelled in', function (): void {
    stripRow($this->db, $this->user->id, $this->accountId, $this->runId, -2222, Currency::Eur->value, 1);
    stripRow($this->db, $this->user->id, $this->accountId, $this->runId, 5000, Currency::Eur->value, 2);

    $page = app(SearchQuery::class)->search($this->user, '', new SearchFilters(after: '2026-08-01'));

    expect($page->totalCount)->toBe(2)
        ->and($page->totalOutMinor)->toBe(-1778)
        ->and($page->totalInMinor)->toBe(4000);
});

it('leaves out a currency it has no rate for rather than counting it at one to one', function (): void {
    stripRow($this->db, $this->user->id, $this->accountId, $this->runId, -2222, Currency::Eur->value, 1);
    stripRow($this->db, $this->user->id, $this->accountId, $this->runId, -9900, 'ZAR', 2);

    $page = app(SearchQuery::class)->search($this->user, '', new SearchFilters(after: '2026-08-01'));

    expect($page->totalCount)->toBe(2)
        ->and($page->totalOutMinor)->toBe(-1778);
});
