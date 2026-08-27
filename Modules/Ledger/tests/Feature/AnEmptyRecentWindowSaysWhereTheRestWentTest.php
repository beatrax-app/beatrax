<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionsList;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Core\Public\Support\Lang;

uses(RefreshDatabase::class);

// The list opens on the last 90 days. A reader whose ledger is older than that
// sees the same screen as a reader who has never imported anything, and the way
// out is a button in the header with nothing to connect it to the empty state.

function emptyWindowUser(): User
{
    return User::query()->create([
        'username' => 'recent-window-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'base_currency' => 'EUR',
    ]);
}

function transactionPostedOn(User $user, string $postedAt): Transaction
{
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN window',
        'slug' => 'window-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ASNB'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/window.xml',
        'sha256' => hash('sha256', 'window-'.bin2hex(random_bytes(8))),
        'uploaded_at' => now(),
        'status' => 'previewed',
    ]);

    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Window Vendor',
        'counterparty_normalized' => 'window vendor',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('win-'.bin2hex(random_bytes(8)), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('tells a reader whose ledger is older than the window that the rest is still there', function (): void {
    $user = emptyWindowUser();
    $this->actingAs($user);
    transactionPostedOn($user, now()->subDays(200)->toDateString());

    Livewire::test(TransactionsList::class)
        ->assertSee(Lang::get('ledger::list.empty_recent_has_older'))
        ->assertSee(Lang::get('ledger::list.show_full'))
        ->call('toggleFullHistory')
        ->assertSee('Window Vendor');
});

it('says the ledger is empty when it really is', function (): void {
    $user = emptyWindowUser();
    $this->actingAs($user);

    Livewire::test(TransactionsList::class)
        ->assertSee(Lang::get('ledger::list.empty_period'))
        ->assertDontSee(Lang::get('ledger::list.empty_recent_has_older'));
});

it('refines a query from the first row rather than the previous ones cursor', function (): void {
    $user = emptyWindowUser();
    $this->actingAs($user);
    transactionPostedOn($user, now()->subDay()->toDateString());

    $page = Livewire::test(TransactionsList::class)->assertSee('Window Vendor');

    // A stale cursor is what a live-modelled filter used to leave behind: the
    // refined query then started mid-history, so the header counted rows the
    // table was not showing and the phone kept the old query's list.
    $page->set('cursorId', 999_999)
        ->set('filterAmountDir', 'out')
        ->assertSet('cursorId', null)
        ->assertSee('Window Vendor');

    $page->set('cursorId', 999_999)
        ->set('searchQuery', 'Window')
        ->assertSet('cursorId', null);
});

