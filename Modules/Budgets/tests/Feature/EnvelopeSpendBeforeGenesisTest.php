<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Budgets\Public\Services\CarryoverQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

// Before the first assignment, `envelope_activated_at` is null and the fold has
// no periods to walk. It used to answer that with a row of zeros — including
// SPENT — so a reader looking at a month of real transactions was told they had
// spent nothing, on a page whose own dashboard showed the true figures.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'pregenesis-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN pregenesis',
        'slug' => 'pregenesis-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/pregenesis.xml',
        'sha256' => hash('sha256', 'pregenesis-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'pregenesis-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $this->period = app(PeriodQuery::class)->current();

    // Deliberately NOT stamped: this is the state before the first assignment,
    // which is the whole point of the case.
    expect(DB::table('users')->where('id', $this->user->id)->value('envelope_activated_at'))->toBeNull();

    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => $this->period->start->toDateString(),
        'booked_at' => $this->period->start->toDateString().' 12:00:00',
        'value_date' => $this->period->start->toDateString(),
        'amount_minor' => -25441,
        'currency' => 'EUR',
        'settled_amount_minor' => -25441,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Albert Heijn',
        'counterparty_normalized' => 'albert heijn',
        'normalization_version' => 1,
        'category_id' => $this->groceries->id,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('pregenesis1', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
});

it('reports what was actually spent before anything has been assigned', function (): void {
    $fold = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);
    $row = $fold['rows'][$this->groceries->id];

    expect($row->spentMinor)->toBe(25441);
    expect($row->assignedMinor)->toBe(0);
});

// Assigned, carried and moved are all genuinely nought here, so the fold's own
// arithmetic — assigned + carried + moved - spent — leaves available as the
// negative of spend. Anything else would have this branch disagreeing with the
// one that runs the moment a first assignment exists.
it('leaves available as the negative of that spend, not zero', function (): void {
    $fold = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);

    expect($fold['rows'][$this->groceries->id]->availableMinor)->toBe(-25441);
});

it('counts a category spent against with nothing assigned as overspent', function (): void {
    $fold = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period);

    expect($fold['overspentCount'])->toBe(1);
});

// The started branch converts each currency's spend before adding it; this
// branch reads the same buckets and had no case proving it does the same. A CHF
// row landing in availableMinor unconverted would put two currencies in one
// number — CHF 99.00 is EUR 105.93 at the bundled rate of 0.93455.
it('converts spend in another currency before it reaches the figures it reports', function (): void {
    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => $this->period->start->toDateString(),
        'booked_at' => $this->period->start->toDateString().' 13:00:00',
        'value_date' => $this->period->start->toDateString(),
        'amount_minor' => -9900,
        'currency' => 'CHF',
        'settled_amount_minor' => -9900,
        'settled_currency' => 'CHF',
        'counterparty_name' => 'Migros',
        'counterparty_normalized' => 'migros',
        'normalization_version' => 1,
        'category_id' => $this->groceries->id,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 2,
        'fingerprint' => str_pad('pregenesis2', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $row = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->period)['rows'][$this->groceries->id];

    expect($row->spentMinor)->toBe(25441 + 10593)
        ->and($row->availableMinor)->toBe(-(25441 + 10593))
        ->and($row->unconvertedSpentMinor)->toBe(0);
});
