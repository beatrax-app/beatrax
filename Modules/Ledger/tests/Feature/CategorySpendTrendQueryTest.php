<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Services\CategorySpendTrendQuery;
use Modules\Ledger\Public\Services\PeriodQuery;

function trendCategory(DatabaseManager $db, int $userId, string $name): int
{
    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId, 'name' => $name, 'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function trendTx(DatabaseManager $db, int $userId, int $categoryId, int $minor, string $postedAt): void
{
    static $i = 0;
    $i++;
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN', 'slug' => 'tr-'.bin2hex(random_bytes(4)),
        'kind' => 'asn', 'iban' => 'NL00TRND'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/tr.csv',
        'sha256' => str_pad('tr'.$i, 64, 'a', STR_PAD_LEFT), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'category_id' => $categoryId,
        'fingerprint' => str_pad('tr'.$i, 64, 'c', STR_PAD_LEFT), 'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 00:00:00', 'value_date' => $postedAt,
        'amount_minor' => $minor, 'currency' => 'EUR', 'settled_amount_minor' => $minor, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'trend', 'counterparty_name' => 'TREND', 'normalization_version' => 1,
        'type' => 'expense', 'source_format' => 'asn-csv', 'source_row_index' => $i,
        'fingerprint_version' => 3, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
    $this->user = User::create(['username' => 'trend-fixture', 'password' => 'fixture-password-12chars', 'period_start_day' => 1]);
    $this->actingAs($this->user);
});

it('compares current vs previous period spend and ranks the movers', function (): void {
    $periods = app(PeriodQuery::class);
    $current = $periods->current();
    $previous = $periods->previous($current);

    $groceries = trendCategory($this->db, $this->user->id, 'Groceries');
    $eating = trendCategory($this->db, $this->user->id, 'Eating out');

    // Groceries: €300 last → €400 this (+€100). Eating out: €50 last → €30 this (−€20).
    trendTx($this->db, $this->user->id, $groceries, -40000, $current->start->addDays(2)->toDateString());
    trendTx($this->db, $this->user->id, $groceries, -30000, $previous->start->addDays(2)->toDateString());
    trendTx($this->db, $this->user->id, $eating, -3000, $current->start->addDays(3)->toDateString());
    trendTx($this->db, $this->user->id, $eating, -5000, $previous->start->addDays(3)->toDateString());

    $trend = app(CategorySpendTrendQuery::class)->forUser($this->user);

    expect($trend->currentTotalMinor)->toBe(43000);
    expect($trend->previousTotalMinor)->toBe(35000);
    expect($trend->totalDeltaMinor)->toBe(8000);
    // Biggest absolute mover first.
    expect($trend->movers[0]->name)->toBe('Groceries');
    expect($trend->movers[0]->deltaMinor)->toBe(10000);
    expect($trend->movers[0]->direction())->toBe('up');
    expect($trend->movers[1]->name)->toBe('Eating out');
    expect($trend->movers[1]->deltaMinor)->toBe(-2000);
    expect($trend->movers[1]->direction())->toBe('down');
});

it('reports no comparison when there is no spend', function (): void {
    $trend = app(CategorySpendTrendQuery::class)->forUser($this->user);

    expect($trend->hasComparison())->toBeFalse();
    expect($trend->movers)->toBe([]);
});
