<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Services\LocaleNegotiator;

// SetLocale already consulted session('locale') for guests, but nothing could
// write it before sign-in, so welcome, signup and login rendered in English with
// the only switcher sitting behind the login the reader could not read.

// Both roots' first-launch gates redirect a 0-user install to their own
// welcome screen, which would mask what these tests are actually asserting.
function guestLocaleUser(?string $locale = null): User
{
    return User::query()->create([
        'username' => 'guest-locale-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('guest-locale-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'locale' => $locale,
    ]);
}

it('puts a supported locale into the session and returns the visitor where they were', function (): void {
    guestLocaleUser();

    $this->from('/login')
        ->post(route('locale.switch'), ['code' => 'nl'])
        ->assertRedirect('/login');

    expect(session('locale'))->toBe('nl');
});

it('ignores an unsupported code rather than storing it', function (): void {
    guestLocaleUser();

    $this->post(route('locale.switch'), ['code' => 'ja']);

    expect(session('locale'))->toBeNull();
});

it('renders the guest surfaces in the chosen language', function (): void {
    guestLocaleUser();

    $this->post(route('locale.switch'), ['code' => 'nl']);

    $this->get(route('login'))->assertSee('Inloggen', false);
});

it('lets a signed-in user preference outrank the guest session choice', function (): void {
    $user = guestLocaleUser('en');

    $this->post(route('locale.switch'), ['code' => 'nl']);

    // The stored override is the top precedence rule in LocaleNegotiator;
    // a leftover session choice from before sign-in must not beat it.
    $this->actingAs($user)->get(route('settings'))->assertOk();

    expect(app('translator')->getLocale())->toBe('en');
});

// core::settings.language.help ends "System follows your browser or operating
// system language, defaulting to English." — copy shared with Settings, where
// the option exists. The pre-auth switcher listed Locale::cases() and nothing
// else, so on the one screen that shows this line to a guest it named a choice
// that was not on the screen, and a guest who switched by accident could not
// get back to their browser's language.
it('offers System on the guest switcher the shared help line describes', function (): void {
    guestLocaleUser();

    $login = $this->get(route('login'))->getContent();

    expect($login)->toBeString()->toContain('value="'.LocaleNegotiator::SYSTEM.'"');
});

it('clears the session override when System is chosen, rather than storing it as a locale', function (): void {
    guestLocaleUser();

    $this->post(route('locale.switch'), ['code' => 'nl']);
    expect(session('locale'))->toBe('nl');

    $this->post(route('locale.switch'), ['code' => LocaleNegotiator::SYSTEM]);

    expect(session('locale'))->toBeNull();
    $this->get(route('login'))->assertSee('Sign in', false);
});

// Which option the select opens on. The translator always reports a concrete
// locale, so "en chosen" and "nothing chosen" both read as en; only the
// session key tells them apart, and without that the System option would never
// be the one showing.
function guestLocaleSystemIsSelected(string $html): bool
{
    return preg_match(
        '/<option\s+value="'.preg_quote(LocaleNegotiator::SYSTEM, '/').'"\s+selected/',
        $html,
    ) === 1;
}

it('marks System as the selected option while no override is held', function (): void {
    guestLocaleUser();

    $before = $this->get(route('login'))->getContent();
    expect($before)->toBeString();
    expect(guestLocaleSystemIsSelected($before))->toBeTrue();

    $this->post(route('locale.switch'), ['code' => 'nl']);

    $after = $this->get(route('login'))->getContent();
    expect($after)->toBeString();
    expect(guestLocaleSystemIsSelected($after))->toBeFalse();
    expect($after)->toContain('value="nl"');
});
