<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Models\EnvelopeAssignment;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

/*
 * Wave 0 RED stubs for Req 2, Req 8, Req 9 (13.2-VALIDATION.md).
 *
 * CarryoverQuery (Plan 03) does not exist yet, and the genesis anchor column
 * (users.envelope_activated_at, Plan 02) does not exist on the users table
 * either — every test below is expected to fail with "class not found" /
 * "no such column", never a PHP parse error. This file pins the executable
 * contract CarryoverQuery::forUserAndPeriod() must satisfy:
 *
 *   CarryoverQuery::forUserAndPeriod(User $user, Period $period): array{
 *       toBudgetMinor: int,
 *       overspentCount: int,
 *       rows: array<int, \Modules\Budgets\Public\Dto\EnvelopeRow>,
 *   }
 *
 * D-10 formula: to-budget(m) = income(m) + poolCarry(m-1) - Sum(assigned(m)).
 * Money is asserted as integer minor units ONLY — never a float.
 */

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

    // Genesis anchor (D-12b): the current period is the activation month, so
    // "before genesis" never enters these tests' fold. Plan 02 adds this
    // column; until then every test below fails on the missing column, which
    // is the correct Wave 0 RED cause.
    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->startOfMonth(),
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** Persist an income or expense transaction dated within the given period. */
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

it('computes to-budget as income plus carryover minus assigned, to the cent, and moves symmetrically on assign/unassign (Req 2)', function (): void {
    $period = app(PeriodQuery::class)->current();

    carryoverTx($this->user->id, $this->account->id, $this->run->id, 100000, null, $period->start);

    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);
    expect($result['toBudgetMinor'])->toBeInt();
    expect($result['toBudgetMinor'])->toBe(100000);

    $assignment = EnvelopeAssignment::create([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => $period->start->toDateString(),
        'assigned_minor' => 20000,
        'currency' => 'EUR',
    ]);

    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);
    expect($result['toBudgetMinor'])->toBe(80000);

    // Assigning an additional €100 drops it by exactly that amount (Req 2 symmetry).
    $assignment->assigned_minor = 30000;
    $assignment->save();
    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);
    expect($result['toBudgetMinor'])->toBe(70000);

    // Unassigning (D-06 tombstone-on-zero) restores it symmetrically.
    $assignment->delete();
    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);
    expect($result['toBudgetMinor'])->toBe(100000);
});

it('permits assigning more than available, showing a negative to-budget without throwing (Req 8)', function (): void {
    $period = app(PeriodQuery::class)->current();

    // No income this period at all.
    EnvelopeAssignment::create([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => $period->start->toDateString(),
        'assigned_minor' => 5000,
        'currency' => 'EUR',
    ]);

    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);

    expect($result['toBudgetMinor'])->toBe(-5000);
    expect($result['toBudgetMinor'])->not->toBeFloat();
});

it('carries a positive leftover pool forward into the next period (D-10 sticky money)', function (): void {
    $current = app(PeriodQuery::class)->current();
    $next = app(PeriodQuery::class)->next($current);

    carryoverTx($this->user->id, $this->account->id, $this->run->id, 100000, null, $current->start);
    // No assignment this period at all -> full €1000 leftover carries forward.

    EnvelopeAssignment::create([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => $next->start->toDateString(),
        'assigned_minor' => 20000,
        'currency' => 'EUR',
    ]);

    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $next);

    // income(next)=0 + poolCarry(current)=100000 - assigned(20000) = 80000.
    expect($result['toBudgetMinor'])->toBe(80000);
});

it('starts the genesis period with zero pool carry and zero carried-in (D-12b)', function (): void {
    $period = app(PeriodQuery::class)->current();

    // NEGATIVE settled minor: carryoverTx() infers type from sign ($settledMinor
    // < 0 => 'expense'); SpendByCategoryQuery counts spend by amount sign, not
    // type, so a positive value here would silently land as untracked income
    // and never contribute to `spent` (Rule 1 fixture bug fix — the original
    // Wave 0 fixture passed +50000, which cannot produce the expense this
    // test's own comment/assertion require).
    carryoverTx($this->user->id, $this->account->id, $this->run->id, -50000, $this->groceries->id, $period->start);

    EnvelopeAssignment::create([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => $period->start->toDateString(),
        'assigned_minor' => 60000,
        'currency' => 'EUR',
    ]);

    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);
    $row = $result['rows'][$this->groceries->id];

    // available = assigned + carriedIn(0, genesis) + netMoved(0) - spent
    expect($row->carriedInMinor)->toBe(0);
    expect($row->availableMinor)->toBe(60000 - 50000);
});

it('surfaces non-EUR settled spend via nonEurSpentMinor without altering availableMinor or overspentCount (CR-01)', function (): void {
    $period = app(PeriodQuery::class)->current();

    // A $9.99 Google Play charge settled in USD, categorized to Groceries.
    // SpendByCategoryQuery keys spend by settled_currency, so this lands under
    // "{categoryId}|USD" and must NEVER be read into the EUR envelope's spent.
    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => $period->start->toDateString(),
        'booked_at' => $period->start->toDateString().' 12:00:00',
        'value_date' => $period->start->toDateString(),
        'amount_minor' => -999,
        'currency' => 'USD',
        'settled_amount_minor' => -999,
        'settled_currency' => 'USD',
        'counterparty_name' => 'Google Play USD',
        'counterparty_normalized' => 'google play usd',
        'normalization_version' => 1,
        'category_id' => $this->groceries->id,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 987654,
        'fingerprint' => str_pad('nonEur', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    // €200 assigned, no EUR spend at all -> envelope looks fully funded in EUR.
    EnvelopeAssignment::create([
        'user_id' => $this->user->id,
        'category_id' => $this->groceries->id,
        'period_start' => $period->start->toDateString(),
        'assigned_minor' => 20000,
        'currency' => 'EUR',
    ]);

    $result = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $period);
    $row = $result['rows'][$this->groceries->id];

    // EUR figures are untouched by the USD spend (no currency collapse).
    expect($row->spentMinor)->toBe(0);
    expect($row->availableMinor)->toBe(20000);
    expect($result['overspentCount'])->toBe(0);

    // ...but the dropped spend is now observable, in its own currency's minor.
    expect($row->nonEurSpentMinor)->toBe(999);
});

it('shows income zero for a future period unless real income transactions exist there (Req 7/Req 9 edge)', function (): void {
    $current = app(PeriodQuery::class)->current();
    $future = $current;
    for ($i = 0; $i < 3; $i++) {
        $future = app(PeriodQuery::class)->next($future);
    }

    $withoutIncome = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $future);
    $baselineToBudget = $withoutIncome['toBudgetMinor'];

    // A real income transaction dated inside that future period changes the number.
    carryoverTx($this->user->id, $this->account->id, $this->run->id, 25000, null, $future->start->addDays(2));

    $withIncome = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $future);

    expect($withIncome['toBudgetMinor'])->toBe($baselineToBudget + 25000);
});
