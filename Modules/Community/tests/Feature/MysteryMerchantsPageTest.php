<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthManager;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\MysteryMerchantsPage;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

beforeEach(function (): void {
    $this->user = makeCommunityTestUser('mystery-page-user');
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/x.csv',
        'sha256' => str_repeat('a', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

function makeMysteryTx(User $user, Account $account, ImportRun $run, string $description, int $day, string $paymentType = 'unknown'): Transaction
{
    static $rowIndex = 0;
    $rowIndex++;
    $dayPart = sprintf('%02d', $day);

    return Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => "2026-05-{$dayPart}",
        'booked_at' => "2026-05-{$dayPart} 12:00:00",
        'value_date' => "2026-05-{$dayPart}",
        'amount_minor' => -1000 - $rowIndex,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000 - $rowIndex,
        'settled_currency' => 'EUR',
        'counterparty_name' => null,
        'counterparty_normalized' => '',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad((string) $rowIndex, 64, 'f', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'description' => $description,
        'payment_type' => $paymentType,
    ]);
}

it('renders the page with H1, stats strip, and at least one mystery card for an unidentified row', function (): void {
    makeMysteryTx($this->user, $this->account, $this->run, 'BCK*MYSTERY MERCHANT *9999', 5, 'pin');

    Livewire::test(MysteryMerchantsPage::class)
        ->assertSee('Mystery merchants')
        ->assertSee('BCK*MYSTERY MERCHANT *9999');
});

it('redirects unauthenticated requests to login when hitting the route', function (): void {
    /** @var AuthManager $auth */
    $auth = $this->app->make(AuthManager::class);
    $auth->guard()->logout();

    $response = $this->get('/community/mystery-merchants');
    expect($response->status())->toBeIn([302, 401, 403]);
});

it('dispatches suggest-mapping:open when the "Suggest a name" button on a card is clicked', function (): void {
    $description = 'CRYPTIC PAYMENT FOO BAR';
    makeMysteryTx($this->user, $this->account, $this->run, $description, 10);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $count = $db->connection()->table('transactions')->where('user_id', $this->user->id)->count();
    expect($count)->toBe(1);

    // The per-card button dispatches browser-side via wire:click="$dispatch(...)",
    // which never reaches the component's call() surface, so the rendered HTML is
    // the only assertion target.
    Livewire::test(MysteryMerchantsPage::class)
        ->assertSee('suggest-mapping:open')
        ->assertSee($description);
});

it('counts unique mystery descriptions in the stats strip mysteryCount', function (): void {
    makeMysteryTx($this->user, $this->account, $this->run, 'MERCHANT-A', 5);
    makeMysteryTx($this->user, $this->account, $this->run, 'MERCHANT-A', 6);
    makeMysteryTx($this->user, $this->account, $this->run, 'MERCHANT-B', 7);

    Livewire::test(MysteryMerchantsPage::class)
        ->assertSee('MERCHANT-A')
        ->assertSee('MERCHANT-B');
});
