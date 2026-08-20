<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

/*
 * A user who has never assigned anything has no envelope_activated_at, so
 * CarryoverQuery had nothing to fold and returned no rows — and the grid reads
 * "no rows" as "no expense categories".
 *
 * Measured on a synced phone: 24 rows of kind='expense' in the categories
 * table, and the page saying "Nog geen uitgavencategorieën. Voeg een
 * uitgavencategorie toe om er geld aan toe te wijzen." Following that advice
 * cannot help, and the page's other hint — click a cell to assign your first
 * month — had no cell to click. Budgets could not be started at all.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'unstarted-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    // Deliberately NO envelope_activated_at — this is the whole point.
    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'unstarted-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->dining = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'unstarted-dining-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);
});

it('does not claim there are no expense categories when there are', function (): void {
    Livewire::test(BudgetsPage::class)
        ->assertOk()
        ->assertDontSee('No expense categories yet');
});

it('offers every expense category as a row to make the first assignment in', function (): void {
    Livewire::test(BudgetsPage::class)
        ->assertOk()
        ->assertSee('Groceries')
        ->assertSee('Dining');
});

it('starts every category at zero rather than inventing a balance', function (): void {
    $rows = Livewire::test(BudgetsPage::class)->viewData('rows');

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect($row->assignedMinor)->toBe(0)
            ->and($row->availableMinor)->toBe(0)
            ->and($row->carriedInMinor)->toBe(0);
    }
});

it('still shows the empty state when the user genuinely has no expense categories', function (): void {
    // Raw, so no model scope narrows what gets removed.
    DB::table('categories')->where('kind', 'expense')->delete();

    Livewire::test(BudgetsPage::class)
        ->assertOk()
        ->assertSee('No expense categories yet');
});

it('offers the income there is to assign, not zero', function (): void {
    $account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Cash',
        'slug' => 'unstarted-cash-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00UNST'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/unstarted.csv',
        'sha256' => str_pad((string) $this->user->id, 64, 'u', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'completed',
    ]);

    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'income',
        'posted_at' => CarbonImmutable::now()->startOfMonth()->addDays(2),
        'booked_at' => CarbonImmutable::now()->startOfMonth()->addDays(2),
        'value_date' => CarbonImmutable::now()->startOfMonth()->addDays(2),
        'amount_minor' => 80000,
        'currency' => 'EUR',
        'settled_amount_minor' => 80000,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'salary',
        'normalization_version' => 1,
        'source_format' => 'asn_csv',
        'import_run_id' => $run->id,
        'source_row_index' => 0,
        'fingerprint' => 'unstarted-'.bin2hex(random_bytes(8)),
        'fingerprint_version' => 1,
    ]);

    // The fold one branch down is income + carry - assigned, and before the
    // first assignment carry and assigned are both nought — so income is what
    // it would answer. Zero told a reader with a month's pay in the account
    // that they had nothing to assign, on the page titled "assign every euro".
    expect(Livewire::test(BudgetsPage::class)->viewData('toBudgetMinor'))->toBe(80000);
});
