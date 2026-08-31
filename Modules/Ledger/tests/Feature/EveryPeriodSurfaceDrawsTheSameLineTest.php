<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;
use Modules\Ledger\Public\Services\ThisPeriodAtAGlanceQuery;
use Modules\Ledger\Public\Services\TopCategoriesByPeriodQuery;

// Four reads answer "this period" over the same rows, and the day the period
// opens and the day it closes are where they can stop agreeing. period_start_day
// is 17 throughout: at 1 the financial month and the calendar month coincide and
// a half-open window can be wrong in either direction without showing it.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 09:00:00'));
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->user = User::create([
        'username' => 'eps-bound',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 17,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function epsCategory(DatabaseManager $db, int $userId, string $name): int
{
    return $db->connection()->table('categories')->insertGetId([
        'user_id' => $userId, 'name' => $name, 'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function epsSpend(DatabaseManager $db, int $userId, ?int $categoryId, int $minor, string $postedAt, string $type = 'expense'): void
{
    $hex = bin2hex(random_bytes(5));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId, 'name' => 'ASN '.$hex, 'slug' => 'eps-'.$hex, 'kind' => 'bank',
        'iban' => 'NL00EPS'.strtoupper(substr($hex, 0, 8)), 'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId, 'source_format' => 'asn-csv', 'raw_file_path' => '/tmp/eps-'.$hex.'.csv',
        'sha256' => hash('sha256', 'eps-'.$hex), 'uploaded_at' => '2026-01-01 00:00:00', 'status' => 'previewed',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $signed = $type === 'income' ? $minor : -$minor;
    $db->connection()->table('transactions')->insert([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId, 'category_id' => $categoryId,
        'fingerprint' => hash('sha256', 'eps-tx-'.$hex), 'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00', 'value_date' => $postedAt,
        'amount_minor' => $signed, 'currency' => 'EUR',
        'settled_amount_minor' => $signed, 'settled_currency' => 'EUR',
        'counterparty_normalized' => 'shop', 'counterparty_name' => 'Shop', 'normalization_version' => 3,
        'type' => $type, 'source_format' => 'asn-csv', 'source_row_index' => 1,
        'fingerprint_version' => 3, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('counts the first and last day of the period once, on every surface that reads it', function (): void {
    $periods = app(PeriodQuery::class);
    $period = $periods->current();
    $lastDay = $period->endExclusive->subDay()->toDateString();
    $firstDay = $period->start->toDateString();
    $groceries = epsCategory($this->db, $this->user->id, 'Groceries');

    epsSpend($this->db, $this->user->id, $groceries, 1000, $firstDay);
    epsSpend($this->db, $this->user->id, $groceries, 2000, $lastDay);
    // one day beyond, must not be counted
    epsSpend($this->db, $this->user->id, $groceries, 4000, $period->endExclusive->toDateString());
    // one day before, must not be counted
    epsSpend($this->db, $this->user->id, $groceries, 8000, $period->start->subDay()->toDateString());

    $tile = app(ThisPeriodAtAGlanceQuery::class)->for($this->user, $period);
    $top = app(TopCategoriesByPeriodQuery::class)->for($this->user, $period, displayCurrency: 'EUR', limit: 5)->rows;
    $spend = app(SpendByCategoryQuery::class)->forUserAndPeriodByCurrency($this->user->id, $period, includeUncategorized: true);
    $span = app(SpendByCategoryQuery::class)->forUserAndSpanByCurrencyPerDay($this->user->id, $period);

    $spanTotal = 0;
    foreach ($span as $day => $keys) {
        foreach ($keys as $minor) {
            $spanTotal += $minor;
        }
    }

    expect($period->start->toDateString())->toBe('2026-08-17')
        ->and($tile->outflow->toMinor())->toBe(3000)
        ->and(array_sum(array_map(static fn ($r) => $r->spend->toMinor(), $top)))->toBe(3000)
        ->and(array_sum($spend))->toBe(3000)
        ->and($spanTotal)->toBe(3000);
});

it('splits two adjacent periods at the boundary the reader keeps, not at the month', function (): void {
    $periods = app(PeriodQuery::class);
    $current = $periods->current();
    $previous = $periods->previous($current);
    $groceries = epsCategory($this->db, $this->user->id, 'Groceries');

    epsSpend($this->db, $this->user->id, $groceries, 500, $previous->start->toDateString());
    epsSpend($this->db, $this->user->id, $groceries, 700, $previous->endExclusive->subDay()->toDateString());
    epsSpend($this->db, $this->user->id, $groceries, 900, $current->start->toDateString());
    epsSpend($this->db, $this->user->id, $groceries, 1100, $current->endExclusive->subDay()->toDateString());

    $this->db->connection()->table('users')->where('id', $this->user->id)
        ->update(['envelope_activated_at' => $previous->start->toDateTimeString()]);

    $carry = app(CarryoverQuery::class);
    $prevRows = $carry->forUserAndPeriod($this->user, $previous)['rows'];
    $curRows = $carry->forUserAndPeriod($this->user, $current)['rows'];

    expect($prevRows[$groceries]->spentMinor)->toBe(1200)
        ->and($curRows[$groceries]->spentMinor)->toBe(2000);
});

it('walks a day-28 window through February without drifting off the 28th', function (): void {
    $this->db->connection()->table('users')->where('id', $this->user->id)->update(['period_start_day' => 28]);
    $this->user->refresh();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-05 09:00:00'));
    $periods = app(PeriodQuery::class);
    $period = $periods->current();

    expect($period->start->toDateString())->toBe('2026-01-28')
        ->and($period->endExclusive->toDateString())->toBe('2026-02-28')
        ->and($periods->next($period)->start->toDateString())->toBe('2026-02-28')
        ->and($periods->next($period)->endExclusive->toDateString())->toBe('2026-03-28')
        ->and($periods->previous($period)->start->toDateString())->toBe('2025-12-28');
});
