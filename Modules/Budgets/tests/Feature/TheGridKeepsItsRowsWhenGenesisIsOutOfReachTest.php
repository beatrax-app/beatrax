<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
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
        'username' => 'walkcap-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN walk cap',
        'slug' => 'walkcap-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/walkcap.xml',
        'sha256' => hash('sha256', 'walkcap-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'walkcap-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);

    $current = app(PeriodQuery::class)->current();
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $current->start, 10000);

    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'expense',
        'posted_at' => $current->start->toDateString(),
        'booked_at' => $current->start->toDateString().' 12:00:00',
        'value_date' => $current->start->toDateString(),
        'amount_minor' => -4000,
        'currency' => 'EUR',
        'settled_amount_minor' => -4000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'WalkCap',
        'counterparty_normalized' => 'walkcap',
        'normalization_version' => 1,
        'category_id' => $this->groceries->id,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('walkcap', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    // Further back than the fold's walk budget reaches: a clock-skewed device
    // and a sync of the column are both enough to write one.
    DB::table('users')->where('id', $this->user->id)
        ->update(['envelope_activated_at' => '1900-01-01 00:00:00']);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('answers for the month asked for even when genesis is further back than it can walk', function (): void {
    $current = app(PeriodQuery::class)->current();

    $fold = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $current);

    expect($fold['rows'])->toHaveKey($this->groceries->id);

    $row = $fold['rows'][$this->groceries->id];
    expect($row->assignedMinor)->toBe(10000)
        ->and($row->spentMinor)->toBe(4000)
        ->and($row->availableMinor)->toBe(6000);
});

// rows => [] renders the "no expense categories" empty state, which is the one
// thing the reader can act on and the one thing that is not true here.
it('still draws the envelope grid rather than the no-categories empty state', function (): void {
    $component = Livewire::actingAs($this->user)->test(BudgetsPage::class);

    expect($component->viewData('rows'))->not->toBeEmpty();
    $component->assertDontSee(__('budgets::messages.no_categories.heading'));
});
