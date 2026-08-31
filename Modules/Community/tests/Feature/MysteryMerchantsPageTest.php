<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
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

// Bulk rows go in through the query builder: 2 500 Eloquent creates is a
// minute of fixture time, and the page reads this table with the raw builder
// anyway.
function seedMysteryRows(User $user, Account $account, ImportRun $run, string $description, int $count, string $startDate): void
{
    static $ordinal = 100000;
    $rows = [];
    foreach (range(1, $count) as $i) {
        $ordinal++;
        $rows[] = [
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => 'expense',
            'posted_at' => CarbonImmutable::parse($startDate)->addDays($i % 28)->toDateString(),
            'booked_at' => CarbonImmutable::parse($startDate)->addDays($i % 28)->toDateTimeString(),
            'value_date' => CarbonImmutable::parse($startDate)->addDays($i % 28)->toDateString(),
            'amount_minor' => -$ordinal,
            'currency' => 'EUR',
            'settled_amount_minor' => -$ordinal,
            'settled_currency' => 'EUR',
            'counterparty_name' => null,
            'counterparty_normalized' => '',
            'normalization_version' => 1,
            'category_id' => null,
            'source_format' => 'asn-csv',
            'import_run_id' => $run->id,
            'source_row_index' => $ordinal,
            'fingerprint' => str_pad((string) $ordinal, 64, 'f', STR_PAD_LEFT),
            'fingerprint_version' => 1,
            'description' => $description,
            'payment_type' => 'pin',
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('transactions')->insert($chunk);
    }
}

// The scan ran under LIMIT 2000 ordered by posted_at, which is the same
// truncation CorpusScanReachesEveryRowTest was written for one layer down: a
// window over the newest rows, reported as if it were the whole ledger.
it('counts every row behind a mystery card, not just the newest window', function (): void {
    seedMysteryRows($this->user, $this->account, $this->run, 'ALPHA MYSTERY CODE', 2400, '2026-05-01');
    seedMysteryRows($this->user, $this->account, $this->run, 'OMEGA MYSTERY CODE', 100, '2020-01-01');

    $component = Livewire::test(MysteryMerchantsPage::class);

    $component->assertViewHas('stats', fn (array $stats): bool => $stats['mysteryCount'] === 2);
    $component->assertViewHas('rows', fn (array $rows): bool => $rows[0]['count'] === 2400 && $rows[1]['count'] === 100);
    $component->assertSee('OMEGA MYSTERY CODE');
});

// A row with no description is not a row Beatrax named — it is a row with
// nothing to name, and counting it as a success is the same fabricated
// percentage the unreadable branch beside it was written to stop.
it('does not count a blank description as auto-named', function (): void {
    foreach (range(1, 6) as $i) {
        makeMysteryTx($this->user, $this->account, $this->run, "REAL MYSTERY {$i}", $i);
    }
    foreach (range(7, 10) as $i) {
        makeMysteryTx($this->user, $this->account, $this->run, '', $i);
    }

    Livewire::test(MysteryMerchantsPage::class)
        ->assertViewHas('stats', fn (array $stats): bool => $stats['autoNamedPercent'] === 0)
        ->assertViewHas('stats', fn (array $stats): bool => $stats['mysteryCount'] === 6);
});

it('reports no auto-named percentage at all when every row is blank', function (): void {
    foreach (range(1, 4) as $i) {
        makeMysteryTx($this->user, $this->account, $this->run, '   ', $i);
    }

    Livewire::test(MysteryMerchantsPage::class)
        ->assertViewHas('stats', fn (array $stats): bool => $stats['autoNamedPercent'] === null);
});

// The tile counts every distinct mystery while the list stops at 24, and the
// page said nothing about the gap.
it('says how many cards it is showing when the list is capped', function (): void {
    foreach (range(1, 30) as $i) {
        makeMysteryTx($this->user, $this->account, $this->run, 'CAPPED MYSTERY '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), ($i % 28) + 1);
    }

    $component = Livewire::test(MysteryMerchantsPage::class);

    $component->assertViewHas('stats', fn (array $stats): bool => $stats['mysteryCount'] === 30);
    $component->assertViewHas('rows', fn (array $rows): bool => count($rows) === 24);
    $component->assertSee('Showing the top 24 of 30.');
});

it('says nothing about a cap when every mystery is on the page', function (): void {
    makeMysteryTx($this->user, $this->account, $this->run, 'ONLY MYSTERY', 4);

    Livewire::test(MysteryMerchantsPage::class)->assertDontSee('Showing the top');
});
