<?php

declare(strict_types=1);

use Modules\Core\Models\User;

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
