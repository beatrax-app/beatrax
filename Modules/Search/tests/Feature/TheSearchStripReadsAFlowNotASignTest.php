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

// The strip is the only money figure in the app that bucketed by the amount's
// sign. Both legs of one internal move therefore landed in it, one on each
// side, so moving EUR 500.00 between two of the reader's own accounts read as
// EUR 500.00 out AND EUR 500.00 in — and a return read as money coming in
// rather than as spend given back.

function flowStripRow(DatabaseManager $db, int $userId, int $accountId, int $runId, int $minor, string $type, int $index, string $paymentType = 'unknown'): void
{
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'flowstrip-'.$index), 'posted_at' => '2026-08-0'.$index,
        'booked_at' => '2026-08-0'.$index.' 12:00:00', 'value_date' => '2026-08-0'.$index,
        'amount_minor' => $minor, 'currency' => Currency::Eur->value,
        'settled_amount_minor' => $minor, 'settled_currency' => Currency::Eur->value,
        'counterparty_name' => 'ACME', 'counterparty_normalized' => 'acme',
        'normalization_version' => 3, 'description' => 'ACME '.$index,
        'type' => $type, 'payment_type' => $paymentType, 'source_format' => 'asn-csv',
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

    $this->user = User::create([
        'username' => 'flow-strip',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);

    $this->accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id, 'name' => 'ASN', 'slug' => 'flow-asn', 'kind' => 'bank',
        'iban' => 'NL00FLOWSTRIP', 'default_currency' => Currency::Eur->value,
        'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
    ]);
    $this->runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $this->user->id, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/flow.csv',
        'sha256' => str_repeat('4', 64), 'uploaded_at' => '2026-08-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('counts neither leg of a move between the reader own accounts', function (): void {
    flowStripRow($this->db, $this->user->id, $this->accountId, $this->runId, -50000, 'transfer_out', 1, 'transfer');
    flowStripRow($this->db, $this->user->id, $this->accountId, $this->runId, 50000, 'transfer_in', 2, 'transfer');

    $page = app(SearchQuery::class)->search($this->user, '', new SearchFilters(after: '2026-08-01'));

    expect($page->totalCount)->toBe(2)
        ->and($page->totalOutMinor)->toBe(0)
        ->and($page->totalInMinor)->toBe(0);
});

it('reads a return as spend given back rather than as money in', function (): void {
    flowStripRow($this->db, $this->user->id, $this->accountId, $this->runId, -10000, 'expense', 1);
    flowStripRow($this->db, $this->user->id, $this->accountId, $this->runId, 3000, 'income', 2, 'refund');

    $page = app(SearchQuery::class)->search($this->user, '', new SearchFilters(after: '2026-08-01'));

    expect($page->totalOutMinor)->toBe(-7000)
        ->and($page->totalInMinor)->toBe(0);
});
