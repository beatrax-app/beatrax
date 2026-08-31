<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Models\EnvelopeSetting;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 10:00:00');

    $this->user = User::create([
        'username' => 'overspend-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN overspend',
        'slug' => 'overspend-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/overspend.xml',
        'sha256' => hash('sha256', 'overspend-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'overspend-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);

    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->startOfMonth(),
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function overspendTx(int $userId, int $accountId, int $runId, int $settledMinor, int $categoryId, CarbonImmutable $postedAt): void
{
    static $i = 500000;
    $i++;

    Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => $postedAt->toDateString(),
        'booked_at' => $postedAt->toDateString().' 12:00:00',
        'value_date' => $postedAt->toDateString(),
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "OverspendM{$i}",
        'counterparty_normalized' => "overspendm{$i}",
        'normalization_version' => 1,
        'category_id' => $categoryId,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i,
        'fingerprint' => str_pad('overspend'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('default reduce_to_budget resets carried_in to zero next period and debits the pool once', function (): void {
    $current = app(PeriodQuery::class)->current();
    $next = app(PeriodQuery::class)->next($current);

    // No EnvelopeSetting row at all: the implicit default is reduce_to_budget.
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $current->start, 10000);
    overspendTx($this->user->id, $this->account->id, $this->run->id, -13000, $this->groceries->id, $current->start);
    // available = 10000 - 13000 = -3000 (overspent €30).

    $before = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $current);
    $nextResult = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $next);

    $nextRow = $nextResult['rows'][$this->groceries->id];
    expect($nextRow->carriedInMinor)->toBe(0);
    expect($nextResult['toBudgetMinor'])->toBe($before['toBudgetMinor'] - 3000);
});

it('carry_negative keeps the negative in the envelope and leaves the pool untouched', function (): void {
    $current = app(PeriodQuery::class)->current();
    $next = app(PeriodQuery::class)->next($current);

    EnvelopeSetting::create([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'overspend_mode' => 'carry_negative',
    ]);

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $current->start, 10000);
    overspendTx($this->user->id, $this->account->id, $this->run->id, -13000, $this->groceries->id, $current->start);

    $before = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $current);
    $nextResult = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $next);

    $nextRow = $nextResult['rows'][$this->groceries->id];
    expect($nextRow->carriedInMinor)->toBe(-3000);
    expect($nextResult['toBudgetMinor'])->toBe($before['toBudgetMinor']);
});
