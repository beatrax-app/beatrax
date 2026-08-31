<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Public\Enums\BudgetProgressStatus;
use Modules\Budgets\Public\Services\EnvelopeProgressQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Models\TransactionSplit;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'envelope-progress',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    // PeriodQuery::current() reads the period-start-day from the authenticated
    // CurrentUser, so the query under test needs a bound guard.
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'envelope-progress-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/envelope-progress.xml',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'envelope-progress-groceries',
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->period = app(PeriodQuery::class)->current();
});

function envelopeProgressTx(int $userId, int $accountId, int $runId, int $settledMinor, ?int $categoryId): void
{
    static $i = 0;
    $i++;
    $today = CarbonImmutable::now()->toDateString();

    Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => $settledMinor < 0 ? 'expense' : 'income',
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "EP{$i}",
        'counterparty_normalized' => "ep{$i}",
        'normalization_version' => 1,
        'category_id' => $categoryId,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i,
        'fingerprint' => str_pad((string) $i, 64, 'e', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('computes spent against the assigned envelope and buckets the status as under', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 40000);
    envelopeProgressTx($this->user->id, $this->account->id, $this->run->id, -12000, $this->groceries->id);
    envelopeProgressTx($this->user->id, $this->account->id, $this->run->id, -8000, $this->groceries->id);

    $rows = app(EnvelopeProgressQuery::class)->forPeriod($this->user, $this->period);

    expect($rows)->toHaveCount(1);
    expect($rows[0]->budgetMinor)->toBe(40000);
    expect($rows[0]->spentMinor)->toBe(20000);
    expect($rows[0]->fractionUsed)->toBe(0.5);
    expect($rows[0]->status)->toBe(BudgetProgressStatus::Under);
    expect($rows[0]->remainingMinor())->toBe(20000);
});

it('flags near at 80% and over once the envelope goes negative', function (): void {
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 10000);
    envelopeProgressTx($this->user->id, $this->account->id, $this->run->id, -9000, $this->groceries->id);

    $near = app(EnvelopeProgressQuery::class)->forPeriod($this->user, $this->period);
    expect($near[0]->status)->toBe(BudgetProgressStatus::Near);

    envelopeProgressTx($this->user->id, $this->account->id, $this->run->id, -5000, $this->groceries->id);
    $over = app(EnvelopeProgressQuery::class)->forPeriod($this->user, $this->period);
    expect($over[0]->status)->toBe(BudgetProgressStatus::Over);
    expect($over[0]->remainingMinor())->toBe(-4000);
});

it('ignores income and another category\'s spend when summing an envelope', function (): void {
    $fuel = Category::create(['user_id' => null, 'name' => 'Fuel', 'slug' => 'envelope-progress-fuel', 'kind' => 'expense', 'display_order' => 2]);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 40000);

    envelopeProgressTx($this->user->id, $this->account->id, $this->run->id, -10000, $this->groceries->id);
    envelopeProgressTx($this->user->id, $this->account->id, $this->run->id, 5000, $this->groceries->id);
    envelopeProgressTx($this->user->id, $this->account->id, $this->run->id, -7000, $fuel->id);

    $byId = [];
    foreach (app(EnvelopeProgressQuery::class)->forPeriod($this->user, $this->period) as $row) {
        $byId[$row->categoryId] = $row;
    }

    expect($byId[$this->groceries->id]->spentMinor)->toBe(10000);
    expect($byId[$fuel->id]->spentMinor)->toBe(7000);
});

it('returns an empty list when nothing is assigned, carried, moved or spent', function (): void {
    expect(app(EnvelopeProgressQuery::class)->forPeriod($this->user, $this->period))->toBe([]);
});

it('counts a split transaction\'s legs against their own envelopes, never the parent', function (): void {
    $household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'envelope-progress-household', 'kind' => 'expense', 'display_order' => 2]);

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 40000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $household->id, $this->period->start, 10000);

    // €80 split €60 Groceries / €20 Household. The parent keeps a vestigial
    // category_id (Groceries) — it must NOT contribute on top of its legs.
    $today = CarbonImmutable::now()->toDateString();
    $tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => -8000,
        'currency' => 'EUR',
        'settled_amount_minor' => -8000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Split Merchant',
        'counterparty_normalized' => 'split merchant',
        'normalization_version' => 1,
        'category_id' => $this->groceries->id,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 999,
        'fingerprint' => str_pad('split', 64, 'e', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
    TransactionSplit::create(['user_id' => $this->user->id, 'transaction_id' => $tx->id, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -6000, 'settled_currency' => 'EUR', 'note' => null, 'sort_order' => 0]);
    TransactionSplit::create(['user_id' => $this->user->id, 'transaction_id' => $tx->id, 'category_id' => $household->id, 'settled_amount_minor' => -2000, 'settled_currency' => 'EUR', 'note' => null, 'sort_order' => 1]);

    $byId = [];
    foreach (app(EnvelopeProgressQuery::class)->forPeriod($this->user, $this->period) as $row) {
        $byId[$row->categoryId] = $row;
    }

    expect($byId[$this->groceries->id]->spentMinor)->toBe(6000);
    expect($byId[$household->id]->spentMinor)->toBe(2000);
    expect($byId[$this->groceries->id]->spentMinor + $byId[$household->id]->spentMinor)->toBe(8000);
});
