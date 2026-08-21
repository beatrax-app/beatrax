<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('maps the user accounts into the forecasting list carrying each account currency', function (): void {
    app(DatabaseManager::class)->connection()->table('accounts')->insert([
        'user_id' => $this->user->id,
        'name' => 'Main',
        'slug' => 'main-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00MAIN0000000000',
        'default_currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(SettingsPage::class)
        ->assertViewHas('forecastingAccounts', fn (array $accts): bool => count($accts) === 1 && $accts[0]['default_currency'] === 'USD');
});

it('renders the Settings page with the user current preferences pre-filled', function (): void {
    $this->user->update([
        'default_currency_view' => 'original',
        'period_start_day' => 25,
    ]);

    Livewire::test(SettingsPage::class)
        ->assertSet('defaultCurrencyView', 'original')
        ->assertSet('periodStartDay', 25)
        ->assertSee('Settings')
        ->assertSee('Save settings');
})->group('phase-3');

it('persists default_currency_view when changed via the toggle', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('defaultCurrencyView', 'original')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    expect($this->user->fresh()->default_currency_view)->toBe('original');
})->group('phase-3');

it('persists period_start_day when changed', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('periodStartDay', 25)
        ->call('save')
        ->assertHasNoErrors();

    expect($this->user->fresh()->period_start_day)->toBe(25);
})->group('phase-3');

it('rejects period_start_day outside 1..28', function (): void {
    $low = Livewire::test(SettingsPage::class)
        ->set('periodStartDay', 0)
        ->call('save')
        ->assertHasErrors(['periodStartDay']);

    expect($low->errors()->first('periodStartDay'))->toBe('Choose a day from 1 to 28.');

    $high = Livewire::test(SettingsPage::class)
        ->set('periodStartDay', 29)
        ->call('save')
        ->assertHasErrors(['periodStartDay']);

    expect($high->errors()->first('periodStartDay'))->toBe('Choose a day from 1 to 28.');

    expect($this->user->fresh()->period_start_day)->toBe(1);
})->group('phase-3');

it('rejects default_currency_view outside {eur_only, original}', function (): void {
    $component = Livewire::test(SettingsPage::class)
        ->set('defaultCurrencyView', 'garbage')
        ->call('save')
        ->assertHasErrors(['defaultCurrencyView']);

    expect($component->errors()->first('defaultCurrencyView'))->toBe('Pick one of the available options.');

    expect($this->user->fresh()->default_currency_view)->toBe('eur_only');
})->group('phase-3');

it('round-trips default_currency_view = original into the user row', function (): void {
    Livewire::test(SettingsPage::class)
        ->set('defaultCurrencyView', 'original')
        ->set('periodStartDay', 25)
        ->call('save')
        ->assertHasNoErrors();

    $this->user->refresh();
    expect($this->user->default_currency_view)->toBe('original');
    expect($this->user->period_start_day)->toBe(25);
})->group('phase-3');

// A `1fr` track takes min-content as its automatic minimum, so a deduction
// category row — a long label plus two ghost buttons, ~367px of min-content —
// pushed its settings section 92px past the container and 35px off a 390px
// screen, clipping the buttons. The track is capped and the row wraps.
it('keeps a settings section inside its container on a phone', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect($css)->toContain('grid-template-columns: minmax(0, 1fr);')
        ->toContain('grid-template-columns: 280px minmax(0, 1fr);')
        ->not->toContain('grid-template-columns: 280px 1fr;');

    $toggleRow = substr($css, (int) strpos($css, '.toggle-row {'), 500);

    expect($toggleRow)->toContain('flex-wrap: wrap;');
});
