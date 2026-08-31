<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

// The fold under test: to-budget(m) = income(m) + poolCarry(m−1) − Σ assigned(m).
beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 10:00:00');

    $this->user = User::create([
        'username' => 'carryover-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN carryover',
        'slug' => 'carryover-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/carryover.xml',
        'sha256' => hash('sha256', 'carryover-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'carry-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);

    // The current period is the activation month, so "before genesis" never
    // enters these tests' fold.
    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->startOfMonth(),
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function carryoverTx(int $userId, int $accountId, int $runId, int $settledMinor, ?int $categoryId, CarbonImmutable $postedAt): void
{
    static $i = 400000;
    $i++;

    Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => $settledMinor < 0 ? 'expense' : 'income',
        'posted_at' => $postedAt->toDateString(),
        'booked_at' => $postedAt->toDateString().' 12:00:00',
        'value_date' => $postedAt->toDateString(),
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "CarryM{$i}",
        'counterparty_normalized' => "carrym{$i}",
        'normalization_version' => 1,
        'category_id' => $categoryId,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i,
        'fingerprint' => str_pad('carry'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('computes to-budget as income plus carryover minus assigned, to the cent, and moves symmetrically on assign/unassign', function (): void {
    $period = app(PeriodQuery::class)->current();

    carryoverTx($this->user->id, $this->account->id, $this->run->id, 100000, null, $period->start);

    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);
    expect($result['toBudgetMinor'])->toBeInt();
    expect($result['toBudgetMinor'])->toBe(100000);

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $period->start, 20000);

    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);
    expect($result['toBudgetMinor'])->toBe(80000);

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $period->start, 30000);
    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);
    expect($result['toBudgetMinor'])->toBe(70000);

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $period->start, 0);
    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);
    expect($result['toBudgetMinor'])->toBe(100000);
});

it('permits assigning more than available, showing a negative to-budget without throwing', function (): void {
    $period = app(PeriodQuery::class)->current();

    // No income this period at all.
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $period->start, 5000);

    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);

    expect($result['toBudgetMinor'])->toBe(-5000);
    expect($result['toBudgetMinor'])->not->toBeFloat();
});

it('carries a positive leftover pool forward into the next period', function (): void {
    $current = app(PeriodQuery::class)->current();
    $next = app(PeriodQuery::class)->next($current);

    carryoverTx($this->user->id, $this->account->id, $this->run->id, 100000, null, $current->start);
    // No assignment this period at all -> full €1000 leftover carries forward.

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $next->start, 20000);

    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $next);

    // income(next)=0 + poolCarry(current)=100000 - assigned(20000) = 80000.
    expect($result['toBudgetMinor'])->toBe(80000);
});

it('starts the genesis period with zero pool carry and zero carried-in', function (): void {
    $period = app(PeriodQuery::class)->current();

    // Negative on purpose: SpendByCategoryQuery counts spend by amount sign,
    // not type, so a positive value would land as untracked income and never
    // contribute to `spent`.
    carryoverTx($this->user->id, $this->account->id, $this->run->id, -50000, $this->groceries->id, $period->start);

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $period->start, 60000);

    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);
    $row = $result['rows'][$this->groceries->id];

    // available = assigned + carriedIn(0, genesis) + netMoved(0) - spent
    expect($row->carriedInMinor)->toBe(0);
    expect($row->availableMinor)->toBe(60000 - 50000);
});

it('surfaces settled spend it has no rate for via unconvertedSpentMinor without altering availableMinor or overspentCount', function (): void {
    $period = app(PeriodQuery::class)->current();

    // A currency the rate table cannot reach: the bundled snapshot ships no
    // rate for it, so it stays out of the fold instead of being counted at one
    // to one, and is surfaced beside the row in its own minor units.
    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => $period->start->toDateString(),
        'booked_at' => $period->start->toDateString().' 12:00:00',
        'value_date' => $period->start->toDateString(),
        'amount_minor' => -999,
        'currency' => 'XPF',
        'settled_amount_minor' => -999,
        'settled_currency' => 'XPF',
        'counterparty_name' => 'Google Play XPF',
        'counterparty_normalized' => 'google play xpf',
        'normalization_version' => 1,
        'category_id' => $this->groceries->id,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 987654,
        'fingerprint' => str_pad('nonEur', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    // EUR 200 assigned and no reachable spend at all, so the envelope is
    // fully funded and the unreachable charge changes none of that.
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $period->start, 20000);

    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);
    $row = $result['rows'][$this->groceries->id];

    expect($row->spentMinor)->toBe(0);
    expect($row->availableMinor)->toBe(20000);
    expect($result['overspentCount'])->toBe(0);

    // The dropped spend stays observable, as a positive magnitude in its own
    // currency's minor units.
    expect($row->unconvertedSpentMinor)->toBe(999);
});

it('shows income zero for a future period unless real income transactions exist there', function (): void {
    $current = app(PeriodQuery::class)->current();
    $future = $current;
    for ($i = 0; $i < 3; $i++) {
        $future = app(PeriodQuery::class)->next($future);
    }

    $withoutIncome = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $future);
    $baselineToBudget = $withoutIncome['toBudgetMinor'];

    carryoverTx($this->user->id, $this->account->id, $this->run->id, 25000, null, $future->start->addDays(2));

    $withIncome = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $future);

    expect($withIncome['toBudgetMinor'])->toBe($baselineToBudget + 25000);
});
