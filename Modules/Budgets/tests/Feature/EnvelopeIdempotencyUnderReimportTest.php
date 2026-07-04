<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Models\TransactionSplit;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

/*
 * Wave 0 RED stub for Req 9 (13.2-VALIDATION.md): live-recompute rollover is
 * idempotent under re-import/edit/split -- carryover is never a stored
 * snapshot. CarryoverQuery (Plan 03) does not exist yet -- expected to fail
 * on the missing class, never a parse error.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'idempotent-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN idempotent',
        'slug' => 'idempotent-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'asn',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/idempotent.xml',
        'sha256' => hash('sha256', 'idempotent-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'idempotent-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->household = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'idempotent-household-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);

    $this->period = app(PeriodQuery::class)->current();

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 40000);
});

it('leaves every envelope balance identical when the fold is recomputed over unchanged data (Req 9)', function (): void {
    $this->tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => $this->period->start->toDateString(),
        'booked_at' => $this->period->start->toDateString().' 12:00:00',
        'value_date' => $this->period->start->toDateString(),
        'amount_minor' => -12000,
        'currency' => 'EUR',
        'settled_amount_minor' => -12000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Idempotent Merchant',
        'counterparty_normalized' => 'idempotent merchant',
        'normalization_version' => 1,
        'category_id' => $this->groceries->id,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('idempotent1', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $first = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);
    $second = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);

    expect($second['toBudgetMinor'])->toBe($first['toBudgetMinor']);
    expect($second['rows'][$this->groceries->id]->availableMinor)->toBe($first['rows'][$this->groceries->id]->availableMinor);
});

it('recomputes the affected past period after editing a past transactions category, with no double-count', function (): void {
    $tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => $this->period->start->toDateString(),
        'booked_at' => $this->period->start->toDateString().' 12:00:00',
        'value_date' => $this->period->start->toDateString(),
        'amount_minor' => -12000,
        'currency' => 'EUR',
        'settled_amount_minor' => -12000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Recategorize Merchant',
        'counterparty_normalized' => 'recategorize merchant',
        'normalization_version' => 1,
        'category_id' => $this->groceries->id,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 2,
        'fingerprint' => str_pad('idempotent2', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $before = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);
    expect($before['rows'][$this->groceries->id]->spentMinor)->toBe(12000);
    expect($before['rows'][$this->household->id]->spentMinor)->toBe(0);

    $tx->category_id = $this->household->id;
    $tx->save();

    $after = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);
    expect($after['rows'][$this->groceries->id]->spentMinor)->toBe(0);
    expect($after['rows'][$this->household->id]->spentMinor)->toBe(12000);
});

it('recomputes downstream carried_in after a past-period transaction is split, with no double-count', function (): void {
    $next = app(PeriodQuery::class)->next($this->period);

    $tx = Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => $this->period->start->toDateString(),
        'booked_at' => $this->period->start->toDateString().' 12:00:00',
        'value_date' => $this->period->start->toDateString(),
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
        'source_row_index' => 3,
        'fingerprint' => str_pad('idempotent3', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $beforeNext = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $next);

    TransactionSplit::create(['user_id' => $this->user->id, 'transaction_id' => $tx->id, 'category_id' => $this->groceries->id, 'settled_amount_minor' => -3000, 'settled_currency' => 'EUR', 'note' => null, 'sort_order' => 0]);
    TransactionSplit::create(['user_id' => $this->user->id, 'transaction_id' => $tx->id, 'category_id' => $this->household->id, 'settled_amount_minor' => -5000, 'settled_currency' => 'EUR', 'note' => null, 'sort_order' => 1]);

    $current = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);
    expect($current['rows'][$this->groceries->id]->spentMinor)->toBe(3000);
    expect($current['rows'][$this->household->id]->spentMinor)->toBe(5000);
    expect($current['rows'][$this->groceries->id]->spentMinor + $current['rows'][$this->household->id]->spentMinor)->toBe(8000);

    $afterNext = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $next);
    expect($afterNext['rows'][$this->groceries->id]->carriedInMinor)->not->toBe($beforeNext['rows'][$this->groceries->id]->carriedInMinor);
});
