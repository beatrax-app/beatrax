<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\FX\Public\Enums\FxRefreshFailureReason;
use Modules\FX\Public\Services\FxRefreshStatus;
use Modules\FX\Public\Support\BundledRates;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

uses(RefreshDatabase::class);

// The fetch runs in a queued job that can fail on every provider in turn, and
// the button reported "Refreshing…" for as long as the page stayed open — four
// minutes and counting on a real phone, online, with nothing fetched.
beforeEach(function (): void {
    // This suite builds its own rate world, so the bundled baseline the install
    // seeds is cleared first: several cases turn on a pair having no rate at
    // all, and one on a hand-dated rate being the newest there is.
    app(DatabaseManager::class)->connection()
        ->table('exchange_rates')
        ->where('source', BundledRates::SOURCE)
        ->delete();
    // The job is not the subject: what matters is that the screen has a way to
    // stop waiting for it. Faking the bus also keeps a real provider call out
    // of the suite.
    Bus::fake();

    /** @var User $user */
    $user = User::query()->create([
        'username' => 'fx-refresh',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'fx_online_enabled' => true,
    ]);

    $this->actingAs($user);
});

function writeRate(string $rate, string $updatedAt): void
{
    DB::table('exchange_rates')->updateOrInsert(
        ['base_currency' => 'EUR', 'quote_currency' => 'USD', 'rate_date' => '2026-08-20', 'source' => 'ecb'],
        ['rate' => $rate, 'created_at' => $updatedAt, 'updated_at' => $updatedAt],
    );
}

it('stops saying it is refreshing once the job records that the fetch landed', function (): void {
    writeRate('1.1000', '2026-08-20 09:00:00');

    $component = Livewire::test(SettingsPage::class)->call('refreshFxRates');

    expect($component->get('fxRefreshing'))->toBeTrue();

    // A weekend feed repeats the previous business day, so the rate DATE does
    // not move on a successful fetch and never was the signal. The job's own
    // record of the upsert is, and it costs the screen no query at all.
    writeRate('1.1100', '2026-08-21 09:00:00');
    app(FxRefreshStatus::class)->recordSuccess((int) auth()->id());

    $component->call('pollFxRefresh');

    expect($component->get('fxRefreshing'))->toBeFalse()
        ->and($component->get('fxRefreshGaveUp'))->toBeFalse();
});

// The poll used to compare max(updated_at) against a baseline it took when the
// refresh started. No index covers updated_at, so every tick read every row of
// a table that grows with every rate fetched — up to sixteen of them inside one
// thirty-second refresh window, on a device with a 128 MB ceiling.
it('asks the rate table nothing at all while it polls', function (): void {
    writeRate('1.1000', '2026-08-20 09:00:00');

    $connection = app(DatabaseManager::class)->connection();
    $component = Livewire::test(SettingsPage::class);

    $rateReads = 0;
    $connection->listen(function (QueryExecuted $query) use (&$rateReads): void {
        if (str_contains($query->sql, 'exchange_rates')) {
            $rateReads++;
        }
    });

    $component->call('refreshFxRates');

    for ($poll = 0; $poll < 15; $poll++) {
        $component->call('pollFxRefresh');
    }

    expect($component->get('fxRefreshing'))->toBeFalse()
        ->and($rateReads)->toBe(
            0,
            'The refresh and its fifteen polls read exchange_rates '.$rateReads.' times. Each one is a full '
            .'scan: no index covers updated_at, and the newest rate date is only reached through the index '
            .'when it is asked for as a max().',
        );
});

// The one read left on this screen. Without the index it walks the whole table
// through a covering index to find its last row — 21,930 rows on two years of
// daily fetches over the thirty currencies the bundled snapshot carries.
it('reaches the newest rate date through an index rather than walking the table', function (): void {
    writeRate('1.1000', '2026-08-20 09:00:00');

    $plan = app(DatabaseManager::class)->connection()
        ->select('explain query plan select max("rate_date") from "exchange_rates"');

    $detail = implode(' ', array_map(static fn (object $row): string => (string) $row->detail, $plan));

    expect($detail)->toContain('exchange_rates_newest_date');
    expect($detail)->not->toContain('USE TEMP B-TREE');
});

it('gives up and says so when nothing ever arrives', function (): void {
    writeRate('1.1000', '2026-08-20 09:00:00');

    $component = Livewire::test(SettingsPage::class)->call('refreshFxRates');

    for ($poll = 0; $poll < 15; $poll++) {
        $component->call('pollFxRefresh');
    }

    expect($component->get('fxRefreshing'))->toBeFalse()
        ->and($component->get('fxRefreshGaveUp'))->toBeTrue();

    // The rates already on the device stay in use, and the line says that
    // rather than leaving the reader to guess what a stalled spinner meant.
    $component->assertSee(__('core::settings.exchange_rates.refresh_gave_up'));
});

it('says the refresh gave up as soon as the job records why, not fifteen polls later', function (): void {
    $component = Livewire::test(SettingsPage::class)->call('refreshFxRates');
    expect($component->get('fxRefreshing'))->toBeTrue();

    // The job's own record of the failure. Without reading it the only signal
    // was silence, so the reader was told the refresh had stopped and never why.
    app(FxRefreshStatus::class)->recordFailure((int) auth()->id(), FxRefreshFailureReason::AllProvidersFailed);

    $component->call('pollFxRefresh')
        ->assertSet('fxRefreshing', false)
        ->assertSet('fxRefreshGaveUp', true);
});
