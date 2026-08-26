<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Dto\EnvelopeRow;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;

// envelope_assignments carries the currency each row was assigned in, and the
// grid printed the stored integer under whatever sign the reader had picked
// since: a EUR 1,200.00 rent envelope read GBP 1,200.00. The spend beside it
// read zero, because only the buckets already in the reader's currency counted.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 09:00:00'));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $db->connection()->table('exchange_rates')->where('source', BundledRates::SOURCE)->delete();
    $db->connection()->table('exchange_rates')->insert([
        'base_currency' => Currency::Eur->value,
        'quote_currency' => Currency::Gbp->value,
        'rate_date' => '2026-08-24',
        'rate' => '0.80',
        'source' => 'ecb',
        'created_at' => '2026-08-24 00:00:00',
        'updated_at' => '2026-08-24 00:00:00',
    ]);

    $this->user = User::create([
        'username' => 'envelope-multi-ccy',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Gbp->value,
    ]);
    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => '2026-08-01 00:00:00',
    ]);
    $this->user = $this->user->fresh();
    $this->actingAs($this->user);

    $this->category = Category::create([
        'user_id' => null,
        'name' => 'Rent / Mortgage',
        'slug' => 'egc-rent-'.bin2hex(random_bytes(4)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function egcAssign(DatabaseManager $db, int $userId, int $categoryId, int $minor, string $currency): void
{
    $db->connection()->table('envelope_assignments')->insert([
        'user_id' => $userId, 'category_id' => $categoryId, 'period_start' => '2026-08-01',
        'assigned_minor' => $minor, 'currency' => $currency,
        'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
    ]);
}

function egcSpend(DatabaseManager $db, int $userId, int $categoryId, int $minor, string $currency): void
{
    $hex = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN '.$hex, 'slug' => 'egc-'.$hex, 'kind' => 'bank',
        'iban' => 'NL00EGC'.strtoupper($hex), 'default_currency' => $currency,
        'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/egc-'.$hex.'.csv',
        'sha256' => hash('sha256', 'egc-'.$hex), 'uploaded_at' => '2026-08-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'category_id' => $categoryId, 'fingerprint' => hash('sha256', 'egc-tx-'.$hex),
        'posted_at' => '2026-08-05', 'booked_at' => '2026-08-05 12:00:00', 'value_date' => '2026-08-05',
        'amount_minor' => -$minor, 'currency' => $currency,
        'settled_amount_minor' => -$minor, 'settled_currency' => $currency,
        'counterparty_name' => 'Vesteda', 'counterparty_normalized' => 'vesteda', 'normalization_version' => 3,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => 1, 'fingerprint_version' => 3,
        'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
    ]);
}

it('converts an envelope assigned in another currency rather than relabelling it', function (): void {
    egcAssign($this->db, $this->user->id, $this->category->id, 120000, Currency::Eur->value);

    $period = app(PeriodQuery::class)->current();
    $fold = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);

    /** @var EnvelopeRow $row */
    $row = $fold['rows'][$this->category->id];

    expect($row->currency)->toBe(Currency::Gbp->value)
        ->and($row->assignedMinor)->toBe(96000);
});

it('counts spend the reporting currency does not match, converted', function (): void {
    egcAssign($this->db, $this->user->id, $this->category->id, 120000, Currency::Eur->value);
    egcSpend($this->db, $this->user->id, $this->category->id, 125000, Currency::Eur->value);

    $period = app(PeriodQuery::class)->current();
    $fold = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);

    /** @var EnvelopeRow $row */
    $row = $fold['rows'][$this->category->id];

    expect($row->spentMinor)->toBe(100000)
        ->and($row->availableMinor)->toBe(-4000)
        ->and($row->unconvertedSpentMinor)->toBe(0);
});

it('leaves spend it has no rate for out of the fold and surfaces it beside', function (): void {
    egcAssign($this->db, $this->user->id, $this->category->id, 120000, Currency::Eur->value);
    egcSpend($this->db, $this->user->id, $this->category->id, 125000, Currency::Eur->value);
    egcSpend($this->db, $this->user->id, $this->category->id, 99900, 'ZAR');

    $period = app(PeriodQuery::class)->current();
    $fold = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);

    /** @var EnvelopeRow $row */
    $row = $fold['rows'][$this->category->id];

    expect($row->spentMinor)->toBe(100000)
        ->and($row->unconvertedSpentMinor)->toBe(99900);
});
