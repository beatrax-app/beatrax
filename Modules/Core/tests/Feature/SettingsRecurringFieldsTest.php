<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Models\User;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'rec-settings',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('renders the Settings page with the user current recurring preferences pre-filled', function (): void {
    $this->user->update([
        'recurring_detection_window_months' => 24,
        'recurring_income_min_amount_minor' => 150000,
    ]);

    Livewire::test(SettingsPage::class)
        ->assertSet('recurringDetectionWindowMonths', 24)
        ->assertSet('recurringIncomeMinAmountMinor', 150000)
        ->assertSee('Recurring detection');
})->group('phase-8');

it('persists recurring_detection_window_months when changed', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('recurringDetectionWindowMonths', 12)
        ->call('save')
        ->assertHasNoErrors();

    expect((int) $this->user->fresh()->recurring_detection_window_months)->toBe(12);
})->group('phase-8');

it('persists recurring_income_min_amount_minor when changed', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('recurringIncomeMinAmountMinor', 150000)
        ->call('save')
        ->assertHasNoErrors();

    expect((int) $this->user->fresh()->recurring_income_min_amount_minor)->toBe(150000);
})->group('phase-8');

it('rejects recurring_detection_window_months below 2 months and leaves the row unchanged', function (): void {
    $this->user->update(['recurring_detection_window_months' => 6]);

    $component = Livewire::test(SettingsPage::class)
        ->set('recurringDetectionWindowMonths', 1)
        ->call('save')
        ->assertHasErrors(['recurringDetectionWindowMonths']);

    expect($component->errors()->first('recurringDetectionWindowMonths'))
        ->toBe('Choose between 2 and 60 months.');

    expect((int) $this->user->fresh()->recurring_detection_window_months)->toBe(6);
})->group('phase-8');

it('rejects recurring_detection_window_months above 60 months and leaves the row unchanged', function (): void {
    $this->user->update(['recurring_detection_window_months' => 6]);

    $component = Livewire::test(SettingsPage::class)
        ->set('recurringDetectionWindowMonths', 61)
        ->call('save')
        ->assertHasErrors(['recurringDetectionWindowMonths']);

    expect($component->errors()->first('recurringDetectionWindowMonths'))
        ->toBe('Choose between 2 and 60 months.');

    expect((int) $this->user->fresh()->recurring_detection_window_months)->toBe(6);
})->group('phase-8');

it('rejects a negative recurring_income_min_amount_minor and leaves the row unchanged', function (): void {
    $this->user->update(['recurring_income_min_amount_minor' => 200000]);

    $component = Livewire::test(SettingsPage::class)
        ->set('recurringIncomeMinAmountMinor', -1)
        ->call('save')
        ->assertHasErrors(['recurringIncomeMinAmountMinor']);

    expect($component->errors()->first('recurringIncomeMinAmountMinor'))
        ->toBe('Enter an amount from €0 upward.');

    expect((int) $this->user->fresh()->recurring_income_min_amount_minor)->toBe(200000);
})->group('phase-8');

it('round-trips both new fields into the users row in a single save', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('recurringDetectionWindowMonths', 36)
        ->set('recurringIncomeMinAmountMinor', 250000)
        ->call('save')
        ->assertHasNoErrors();

    $this->user->refresh();
    expect((int) $this->user->recurring_detection_window_months)->toBe(36);
    expect((int) $this->user->recurring_income_min_amount_minor)->toBe(250000);
})->group('phase-8');
