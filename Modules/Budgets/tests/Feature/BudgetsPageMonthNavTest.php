<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Services\PeriodQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'monthnav-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN monthnav',
        'slug' => 'monthnav-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/monthnav.xml',
        'sha256' => hash('sha256', 'monthnav-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'monthnav-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);

    // CarryoverQuery returns all zeros for a null `envelope_activated_at`,
    // and the stamp is three periods back rather than this month so the
    // previous-period walk below is never clamped to genesis.
    DB::table('users')->where('id', $this->user->id)->update([
        'envelope_activated_at' => CarbonImmutable::now()->subMonths(3)->startOfMonth(),
    ]);
});

it('shows the previous periods own assignment after navigating back, distinct from the current period (Req 7)', function (): void {
    $current = app(PeriodQuery::class)->current();
    $previous = app(PeriodQuery::class)->previous($current);

    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $current->start, 40000);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $previous->start, 15000);

    Livewire::test(BudgetsPage::class)
        ->assertViewHas('toBudgetMinor')
        ->call('prevPeriod')
        ->assertSee('150,00')
        ->assertDontSee('400,00');
});

it('shows income zero for a future period unless a real income transaction exists there (Req 7)', function (): void {
    $current = app(PeriodQuery::class)->current();
    $future = $current;
    for ($i = 0; $i < 2; $i++) {
        $future = app(PeriodQuery::class)->next($future);
    }

    $component = Livewire::test(BudgetsPage::class)
        ->call('nextPeriod')
        ->call('nextPeriod');

    $baselineToBudget = $component->viewData('toBudgetMinor');

    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'income',
        'posted_at' => $future->start->addDays(3)->toDateString(),
        'booked_at' => $future->start->addDays(3)->toDateString().' 12:00:00',
        'value_date' => $future->start->addDays(3)->toDateString(),
        'amount_minor' => 50000,
        'currency' => 'EUR',
        'settled_amount_minor' => 50000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Future Salary',
        'counterparty_normalized' => 'future salary',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $this->run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('futureincome', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $componentAfter = Livewire::test(BudgetsPage::class)
        ->call('nextPeriod')
        ->call('nextPeriod');

    expect($componentAfter->viewData('toBudgetMinor'))->toBe($baselineToBudget + 50000);
});

it('disables navigating past the genesis period and past current+12', function (): void {
    Livewire::test(BudgetsPage::class)
        ->assertViewHas('canGoPrevious')
        ->assertViewHas('canGoNext');
});
