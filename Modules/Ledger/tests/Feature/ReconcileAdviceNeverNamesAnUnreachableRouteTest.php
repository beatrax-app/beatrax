<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;

// The advice used to be one sentence shown for every non-zero difference:
// toggle cleared rows until it reaches zero. On an account with no baseline
// every toggle moved the number the wrong way and zero was never in range.
beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'reconcile-advice',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Advice card',
        'slug' => 'reconcile-advice-card',
        'kind' => 'ics_card',
        'iban' => 'ADVICE-CARD',
        'default_currency' => 'EUR',
    ]);

    $this->db->connection()->table('import_runs')->insert([
        'id' => 9101,
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/reconcile-advice.pdf',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => '2026-04-20 00:00:00',
        'status' => 'confirmed',
    ]);
});

function adviceRow(int $minor, string $status, string $fingerprintSeed): void
{
    /** @var DatabaseManager $db */
    $db = test()->db;

    $db->connection()->table('transactions')->insert([
        'user_id' => test()->user->id,
        'account_id' => test()->account->id,
        'import_run_id' => 9101,
        'type' => $minor < 0 ? 'expense' : 'income',
        'status' => $status,
        'posted_at' => '2026-04-10',
        'booked_at' => '2026-04-10 12:00:00',
        'value_date' => '2026-04-10',
        'amount_minor' => $minor,
        'currency' => 'EUR',
        'settled_amount_minor' => $minor,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'advice merchant',
        'normalization_version' => 1,
        'source_format' => 'ics-pdf',
        'source_row_index' => 0,
        'fingerprint' => str_repeat($fingerprintSeed, 64),
        'fingerprint_version' => 3,
    ]);
}

function advicePage(string $balance): Testable
{
    return Livewire::test(ReconcilePage::class, ['accountId' => test()->account->id])
        ->set('statementDate', '2026-04-30')
        ->set('statementBalance', $balance);
}

it('offers the toggle route when a subset of rows really can reach the target', function (): void {
    adviceRow(-1000, 'cleared', 'a');
    adviceRow(-2500, 'cleared', 'b');

    // Un-clearing the -2500 row leaves exactly -1000, so a subset really
    // does reach the target and the toggle advice is honest here.
    $page = advicePage('-10.00');

    expect($page->viewData('isReachable'))->toBeTrue();
    $page->assertSee('Toggle cleared rows', false);
});

// The target is more negative than every row on the account added together,
// so no combination of them reaches it and the old advice was a loop.
it('refuses to offer the toggle route when the target is out of reach', function (): void {
    adviceRow(-1000, 'cleared', 'a');
    adviceRow(-2500, 'cleared', 'b');

    $page = advicePage('-999.00');

    expect($page->viewData('isReachable'))->toBeFalse();
    $page->assertDontSee('Toggle cleared rows', false);
});

it('names the missing opening balance as the cause when the account has no baseline', function (): void {
    adviceRow(-1000, 'cleared', 'a');
    adviceRow(-2500, 'cleared', 'b');

    $page = advicePage('-999.00');

    expect($page->viewData('hasBaseline'))->toBeFalse();
    $page->assertSee('no opening balance recorded', false);
});

it('stops blaming the missing opening balance once the account has one', function (): void {
    adviceRow(-1000, 'cleared', 'a');
    adviceRow(-2500, 'cleared', 'b');

    $this->db->connection()->table('accounts')
        ->where('id', $this->account->id)
        ->update(['starting_balance_minor' => -5000, 'starting_balance_date' => '2026-04-01']);

    $page = advicePage('-999.00');

    expect($page->viewData('hasBaseline'))->toBeTrue();
    expect($page->viewData('isReachable'))->toBeFalse();
    $page->assertDontSee('no opening balance recorded', false)
        ->assertSee('outside the range of every row', false);
});

it('says nothing at all once the difference is zero', function (): void {
    adviceRow(-1000, 'cleared', 'a');

    $page = advicePage('-10.00');

    expect($page->viewData('isMatched'))->toBeTrue();
    $page->assertDontSee('reconcile-advice', false);
});
