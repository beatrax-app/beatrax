<?php

declare(strict_types=1);

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

    // Through the model the guard is holding, not a raw UPDATE: mount() reads
    // these off the authenticated instance, which a write straight to the row
    // leaves as it found it.
    $this->store = function (array $attributes): void {
        $this->reader->forceFill($attributes)->save();
    };
});

it('opens the drift threshold on the percentage a fresh install alerts at', function (): void {
    $html = Livewire::test(SettingsPage::class)
        ->assertSet('driftAlertThresholdPercent', DriftThresholdOptions::DEFAULT_PERCENT)
        ->html();

    expect($html)->toContain('<option value="5" selected>')
        ->and($html)->not->toContain('<option value="1" selected>');
});

it('follows a stored drift threshold rather than the first one offered', function (): void {
    ($this->store)(['drift_alert_threshold_percent' => 25]);

    $html = Livewire::test(SettingsPage::class)->html();

    expect($html)->toContain('<option value="25" selected>')
        ->and($html)->not->toContain('<option value="5" selected>');
});

it('names the stored reporting currency on the picker that sets it', function (): void {
    ($this->store)(['base_currency' => 'GBP']);

    $html = Livewire::test(SettingsPage::class)
        ->assertSet('baseCurrency', 'GBP')
        ->html();

    expect($html)->toContain('<option value="GBP" selected>')
        ->and($html)->not->toContain('<option value="EUR" selected>');
});

it('opens the currency view on the one the install renders in', function (): void {
    ($this->store)(['default_currency_view' => 'original']);

    $html = Livewire::test(SettingsPage::class)->html();

    expect($html)->toContain('<option value="original" selected>')
        ->and($html)->not->toContain('<option value="eur_only" selected>');
});
