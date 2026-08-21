<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\LocaleNegotiator;
use Modules\Shell\Internal\Http\Livewire\SettingsPage;

// Every reader-facing string reads the translator, and dates do not: Carbon
// keeps a locale of its own and is reached only by the LocaleUpdated event that
// Application::setLocale raises. A switcher that retargets the translator alone
// leaves every translatedFormat / isoFormat date in the language before it.

function dateLanguageUser(?string $locale): User
{
    return User::query()->create([
        'username' => 'date-language-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('date-language-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => $locale,
    ]);
}

function dateLanguageMonthName(): string
{
    return CarbonImmutable::create(2026, 3, 4)->translatedFormat('F');
}

it('moves the dates when the request middleware negotiates a language', function (): void {
    CarbonImmutable::setLocale('en');

    $this->actingAs(dateLanguageUser('nl'))
        ->get(route('settings'))
        ->assertOk();

    expect(dateLanguageMonthName())->toBe('maart');
});

it('moves the dates when the settings switcher stores a language', function (): void {
    $this->actingAs(dateLanguageUser(null));
    CarbonImmutable::setLocale('en');

    Livewire::test(SettingsPage::class)->call('setLocale', 'nl');

    expect(dateLanguageMonthName())->toBe('maart');
});

it('moves the dates when the guest switcher on signup stores a language', function (): void {
    CarbonImmutable::setLocale('en');

    Livewire::test(SignupPage::class)->set('locale', 'nl');

    expect(dateLanguageMonthName())->toBe('maart');
});

it('leaves the dates alone when the guest switcher is handed a code we do not ship', function (): void {
    CarbonImmutable::setLocale('en');

    Livewire::test(SignupPage::class)->set('locale', 'ja');

    expect(dateLanguageMonthName())->toBe('March');
});

// Choosing System clears the stored code rather than applying one, so the dates
// stay where they were until the next full page load re-negotiates them.
it('stops applying a language once the reader hands the choice back to System', function (): void {
    CarbonImmutable::setLocale('en');

    Livewire::test(SignupPage::class)
        ->set('locale', 'nl')
        ->set('locale', LocaleNegotiator::SYSTEM);

    expect(session()->has('locale'))->toBeFalse()
        ->and(dateLanguageMonthName())->toBe('maart');
});
