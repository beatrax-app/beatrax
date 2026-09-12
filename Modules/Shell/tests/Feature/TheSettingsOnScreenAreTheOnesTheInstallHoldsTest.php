<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DriftThresholdOptions;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

// Every select on this page carries a value the page already knows, and a
// select that marks no option draws the first of its list instead. The drift
// threshold listed one per cent first over an install set to five, and the
// reader could not choose one per cent either, because selecting the option
// already on screen fires no change event.

beforeEach(function (): void {
    $this->reader = User::create([
        'username' => 'settings-on-screen',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->reader);
});

it('opens the drift threshold on the percentage a fresh install alerts at', function (): void {
    $html = Livewire::test(SettingsPage::class)
        ->assertSet('driftAlertThresholdPercent', DriftThresholdOptions::DEFAULT_PERCENT)
        ->html();

    expect($html)->toContain('<option value="5" selected>')
        ->and($html)->toContain('<option value="1">');
});

it('follows a stored drift threshold rather than the first one offered', function (): void {
    DB::table('users')->where('id', $this->reader->id)->update(['drift_alert_threshold_percent' => 25]);

    $html = Livewire::test(SettingsPage::class)->html();

    expect($html)->toContain('<option value="25" selected>')
        ->and($html)->not->toContain('<option value="5" selected>');
});

it('names the stored reporting currency on the picker that sets it', function (): void {
    DB::table('users')->where('id', $this->reader->id)->update(['base_currency' => 'GBP']);

    $html = Livewire::test(SettingsPage::class)
        ->assertSet('baseCurrency', 'GBP')
        ->html();

    expect($html)->toContain('<option value="GBP" selected>')
        ->and($html)->not->toContain('<option value="EUR" selected>');
});

it('opens the currency view on the one the install renders in', function (): void {
    DB::table('users')->where('id', $this->reader->id)->update(['default_currency_view' => 'original']);

    $html = Livewire::test(SettingsPage::class)->html();

    expect($html)->toContain('<option value="original" selected>')
        ->and($html)->not->toContain('<option value="eur_only" selected>');
});
