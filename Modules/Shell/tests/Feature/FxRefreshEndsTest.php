<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
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

it('stops saying it is refreshing once the rate table takes a write', function (): void {
    writeRate('1.1000', '2026-08-20 09:00:00');

    $component = Livewire::test(SettingsPage::class)->call('refreshFxRates');

    expect($component->get('fxRefreshing'))->toBeTrue();

    // A weekend feed repeats the previous business day, so the rate DATE does
    // not move on a successful fetch. The write timestamp does.
    writeRate('1.1100', '2026-08-21 09:00:00');

    $component->call('pollFxRefresh');

    expect($component->get('fxRefreshing'))->toBeFalse()
        ->and($component->get('fxRefreshGaveUp'))->toBeFalse();
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
