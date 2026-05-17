<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SettingsPage;
use Modules\Core\Models\User;

/*
 * Feature tests for the two recurring-related preferences on the
 * /settings page:
 *
 *   - users.recurring_detection_window_months — bounded 3..60 months.
 *   - users.recurring_income_min_amount_minor — bounded 0..100_000_000
 *     in signed BIGINT minor units (€0.00..€1,000,000.00).
 *
 * Mirrors the SettingsPageTest pattern: beforeEach() seeds a user,
 * Livewire::test() drives the page, save() round-trips into the users
 * row, and out-of-range values are rejected with the calm validation
 * message + unchanged-row guarantee.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'email' => 'rec-settings@diederik.test',
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

it('rejects recurring_detection_window_months below 3 months and leaves the row unchanged', function (): void {
    $this->user->update(['recurring_detection_window_months' => 18]);

    $component = Livewire::test(SettingsPage::class)
        ->set('recurringDetectionWindowMonths', 2)
        ->call('save')
        ->assertHasErrors(['recurringDetectionWindowMonths']);

    expect($component->errors()->first('recurringDetectionWindowMonths'))
        ->toBe('Choose between 3 and 60 months.');

    expect((int) $this->user->fresh()->recurring_detection_window_months)->toBe(18);
})->group('phase-8');

it('rejects recurring_detection_window_months above 60 months and leaves the row unchanged', function (): void {
    $this->user->update(['recurring_detection_window_months' => 18]);

    $component = Livewire::test(SettingsPage::class)
        ->set('recurringDetectionWindowMonths', 61)
        ->call('save')
        ->assertHasErrors(['recurringDetectionWindowMonths']);

    expect($component->errors()->first('recurringDetectionWindowMonths'))
        ->toBe('Choose between 3 and 60 months.');

    expect((int) $this->user->fresh()->recurring_detection_window_months)->toBe(18);
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
