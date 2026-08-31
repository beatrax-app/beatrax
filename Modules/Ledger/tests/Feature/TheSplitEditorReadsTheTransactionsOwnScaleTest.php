<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

function splitScaleFixture(string $currency, int $settledMinor): array
{
    $user = User::create([
        'username' => 'split-scale-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => $currency,
    ]);

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'Tokyo',
        'slug' => 'tokyo-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'JP'.bin2hex(random_bytes(6)),
        'default_currency' => $currency,
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/x.xml',
        'sha256' => str_repeat('b', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $a = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'groceries-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 1]);
    $b = Category::create(['user_id' => null, 'name' => 'Household', 'slug' => 'household-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 2]);

    $today = CarbonImmutable::now()->toDateString();
    $tx = Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => $today,
        'booked_at' => $today.' 12:00:00',
        'value_date' => $today,
        'amount_minor' => $settledMinor,
        'currency' => $currency,
        'settled_amount_minor' => $settledMinor,
        'settled_currency' => $currency,
        'counterparty_name' => 'Kiosk',
        'counterparty_normalized' => 'kiosk',
        'normalization_version' => 1,
        'category_id' => $a->id,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => random_int(1, 999999),
        'fingerprint' => str_pad(bin2hex(random_bytes(8)), 64, '0'),
        'fingerprint_version' => 3,
        'status' => 'cleared',
    ]);

    return [$user, $tx, $a, $b];
}

// The transaction's own scale, not the repo-wide hundredth: a Y1,000 charge
// prefills the editor at the figure the detail page prints one line above it.
it('prefills a yen split leg at the yen figure the page prints', function (): void {
    [$user, $tx] = splitScaleFixture('JPY', -1000);

    $component = Livewire::actingAs($user)
        ->test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor');

    expect($component->get('legs')[0]['amount'])->toBe('1,000');
});

it('splits a yen charge into the two yen figures that were typed', function (): void {
    [$user, $tx, $a, $b] = splitScaleFixture('JPY', -1000);

    $component = Livewire::actingAs($user)
        ->test(TransactionDetail::class, ['transactionId' => $tx->id])
        ->call('openSplitEditor')
        ->set('legs.0.categoryId', (string) $a->id)
        ->set('legs.0.amount', '600')
        ->set('legs.1.categoryId', (string) $b->id)
        ->set('legs.1.amount', '400')
        ->call('saveSplit');

    expect($component->get('splitError'))->toBeNull();

    $legs = DB::table('transaction_splits')
        ->where('transaction_id', $tx->id)
        ->orderBy('sort_order')
        ->pluck('settled_amount_minor')
        ->all();

    expect($legs)->toBe([-600, -400]);
});
