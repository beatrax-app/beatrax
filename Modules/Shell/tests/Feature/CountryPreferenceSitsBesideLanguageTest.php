<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

// Two pickers on one screen that both list places. The country decides which
// country's rules apply; the language only changes the words. Neither may be
// readable as the other.

// Regional-indicator pairs, matched by codepoint rather than through the enum,
// so this still fails if a flag comes back by another route.
const SETTINGS_CARRIES_NO_FLAG = '/[\x{1F1E6}-\x{1F1FF}]/u';

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'country-pref-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('keeps the country beside the language rather than under Tax', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSee('Country')
        ->assertSee('Your country')
        ->assertSee('Display language')
        ->assertSeeHtml('id="country"')
        ->assertSeeHtml('data-testid="settings-country-select"');
});

it('says what each picker affects, and what it does not', function (): void {
    Livewire::test(SettingsPage::class)
        ->assertSee("Decides which country's tax rules, government bodies and bank fees the app recognises. It does not change the language or how amounts are written.")
        ->assertSee('Changes the words on screen, and how amounts are written. System follows your browser or operating system language, defaulting to English.');
});

// A flag names a country, so on a screen that also asks for a country it is the
// one signal the language picker must not carry.
it('shows no flag anywhere on the settings screen', function (): void {
    $html = Livewire::test(SettingsPage::class)->html();

    expect(PatternScan::matches(SETTINGS_CARRIES_NO_FLAG, $html))->toBeFalse();
});

it('initialises the country from the user row and persists a change', function (): void {
    DB::table('users')->where('id', $this->user->id)->update(['country_code' => 'de']);

    Livewire::test(SettingsPage::class)
        ->assertSet('country', 'de')
        ->call('setCountry', 'nl')
        ->assertSet('country', 'nl');

    expect(DB::table('users')->where('id', $this->user->id)->value('country_code'))->toBe('nl');
});

it('rejects a country outside the allow-list without writing it', function (): void {
    Livewire::test(SettingsPage::class)
        ->call('setCountry', 'xx')
        ->assertHasErrors(['country']);

    expect(DB::table('users')->where('id', $this->user->id)->value('country_code'))->toBeNull();
});

// The rejected value used to be assigned before it was validated, so the
// property held 'xx' with the database untouched: neither the placeholder nor
// any option matched, and the select re-rendered with nothing selected — the
// stored country vanished off the screen behind an error about a value nobody
// had chosen.
it('keeps showing the stored country after a rejected one', function (): void {
    DB::table('users')->where('id', $this->user->id)->update(['country_code' => 'nl']);

    $component = Livewire::test(SettingsPage::class)
        ->assertSet('country', 'nl')
        ->call('setCountry', 'xx')
        ->assertHasErrors(['country'])
        ->assertSet('country', 'nl');

    expect($component->html())->toContain('value="nl" selected');
});

// A placeholder that cannot be re-chosen says so, rather than accepting the
// gesture and silently discarding it.
it('marks the placeholder option as not choosable', function (): void {
    $html = Livewire::test(SettingsPage::class)->html();

    expect($html)->toContain('<option value="" disabled');
});

// Re-picking the placeholder must not be the one gesture in the app that can
// blank the preference.
it('leaves a chosen country alone when the placeholder is re-selected', function (): void {
    DB::table('users')->where('id', $this->user->id)->update(['country_code' => 'nl']);

    Livewire::test(SettingsPage::class)
        ->call('setCountry', '')
        ->assertSet('country', 'nl');

    expect(DB::table('users')->where('id', $this->user->id)->value('country_code'))->toBe('nl');
});
