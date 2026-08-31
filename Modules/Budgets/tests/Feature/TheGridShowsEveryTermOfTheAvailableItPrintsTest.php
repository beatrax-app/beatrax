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
use Modules\Ledger\Public\ValueObjects\Money;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-10 09:00:00');

    $this->user = User::create([
        'username' => 'addsup-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN adds up',
        'slug' => 'addsup-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/addsup.xml',
        'sha256' => hash('sha256', 'addsup-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $suffix = bin2hex(random_bytes(3));
    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'addsup-groceries-'.$suffix, 'kind' => 'expense', 'display_order' => 1]);
    $this->fuel = Category::create(['user_id' => null, 'name' => 'Fuel', 'slug' => 'addsup-fuel-'.$suffix, 'kind' => 'expense', 'display_order' => 2]);

    DB::table('users')->where('id', $this->user->id)->update(['envelope_activated_at' => '2026-06-01 00:00:00']);

    $periods = app(PeriodQuery::class);
    $june = $periods->containingDate('2026-06-01');
    $this->july = $periods->containingDate('2026-07-01');

    $writer = app(EnvelopeWriter::class);
    $writer->setAssigned($this->user, $this->groceries->id, $june->start, 30000);
    addsUpSpend($this, -12300, $this->groceries->id, $june->start);

    $writer->setAssigned($this->user, $this->groceries->id, $this->july->start, 20000);
    $writer->setAssigned($this->user, $this->fuel->id, $this->july->start, 900);
    addsUpSpend($this, -5000, $this->groceries->id, $this->july->start);

    $writer->move($this->user, $this->fuel->id, $this->groceries->id, $this->july->start, 4300);
    $writer->move($this->user, $this->groceries->id, $this->fuel->id, $this->july->start, 1200);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function addsUpSpend(object $ctx, int $settledMinor, int $categoryId, CarbonImmutable $postedAt): void
{
    static $i = 700000;
    $i++;

    Transaction::create([
        'user_id' => $ctx->user->id,
        'account_id' => $ctx->account->id,
        'type' => 'expense',
        'posted_at' => $postedAt->toDateString(),
        'booked_at' => $postedAt->toDateString().' 12:00:00',
        'value_date' => $postedAt->toDateString(),
        'amount_minor' => $settledMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "AddsUp{$i}",
        'counterparty_normalized' => "addsup{$i}",
        'normalization_version' => 1,
        'category_id' => $categoryId,
        'source_format' => 'camt053',
        'import_run_id' => $ctx->run->id,
        'source_row_index' => $i,
        'fingerprint' => str_pad('addsup'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

// available = assigned + carried in + net moved - spent. Three of the five sat
// on the grid; a reader looking at €200.00 assigned and €50.00 spent read
// €358.00 available and had nowhere to find the other €208.00.
it('prints the carried-in and net-moved terms it computed the available from', function (): void {
    $fold = app(CarryoverQuery::class)->forUserAndPeriod($this->user, $this->july);
    $row = $fold['rows'][$this->groceries->id];

    expect($row->assignedMinor)->toBe(20000)
        ->and($row->carriedInMinor)->toBe(17700)
        ->and($row->netMovedMinor)->toBe(3100)
        ->and($row->spentMinor)->toBe(5000)
        ->and($row->availableMinor)->toBe(35800);

    Livewire::actingAs($this->user)->test(BudgetsPage::class)
        ->assertSee(Money::ofMinor(17700, 'EUR')->format())
        ->assertSee(Money::ofMinor(3100, 'EUR')->format())
        ->assertSee(Money::ofMinor(35800, 'EUR')->format());
});
