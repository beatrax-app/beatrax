<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Models\User;
use Modules\FX\Internal\Jobs\FetchFxRatesJob;
use Modules\Ledger\Models\Currency;

beforeEach(function (): void {
    Currency::query()->updateOrInsert(['code' => 'EUR'], ['name' => 'Euro', 'minor_unit' => 2]);

    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'fx_online_enabled' => false,
    ]);
    $this->actingAs($this->user);
});

it('mounts with fxOnlineEnabled defaulting to false', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSet('fxOnlineEnabled', false);
})->group('phase-1-fx');

it('mounts with fxOnlineEnabled hydrated from the user row when true', function (): void {
    $this->user->update(['fx_online_enabled' => true]);

    Livewire::test(SettingsPage::class)
        ->assertSet('fxOnlineEnabled', true);
})->group('phase-1-fx');

it('toggleFxOnline flips fxOnlineEnabled to true and instantly persists', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSet('fxOnlineEnabled', false)
        ->call('toggleFxOnline')
        ->assertSet('fxOnlineEnabled', true);

    expect((bool) $this->user->fresh()->fx_online_enabled)->toBeTrue();
})->group('phase-1-fx');

it('toggleFxOnline flips fxOnlineEnabled back to false and instantly persists', function (): void {
    $this->user->update(['fx_online_enabled' => true]);

    Livewire::test(SettingsPage::class)
        ->assertSet('fxOnlineEnabled', true)
        ->call('toggleFxOnline')
        ->assertSet('fxOnlineEnabled', false);

    expect((bool) $this->user->fresh()->fx_online_enabled)->toBeFalse();
})->group('phase-1-fx');

it('toggleFxOnline does not require the batch Save button', function (): void {
    // The toggle is instant-apply: no save() call, yet the row must already differ.
    Livewire::test(SettingsPage::class)
        ->call('toggleFxOnline');

    expect((bool) $this->user->fresh()->fx_online_enabled)->toBeTrue();
})->group('phase-1-fx');

it('refreshFxRates dispatches FetchFxRatesJob for the current user', function (): void {
    Bus::fake();

    Livewire::test(SettingsPage::class)
        ->call('refreshFxRates');

    Bus::assertDispatched(FetchFxRatesJob::class, function (FetchFxRatesJob $job): bool {
        return $job->userId === $this->user->id;
    });
})->group('phase-1-fx');

it('refreshFxRates sets fxRefreshing to true', function (): void {
    Bus::fake();

    Livewire::test(SettingsPage::class)
        ->call('refreshFxRates')
        ->assertSet('fxRefreshing', true);
})->group('phase-1-fx');

it('toggleFxOnline does not touch another user\'s row (V4)', function (): void {
    $other = User::create([
        'username' => 'other',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'fx_online_enabled' => false,
    ]);

    Livewire::test(SettingsPage::class)
        ->call('toggleFxOnline');

    expect((bool) $other->fresh()->fx_online_enabled)->toBeFalse();
})->group('phase-1-fx');
