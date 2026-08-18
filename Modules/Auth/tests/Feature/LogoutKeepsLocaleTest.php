<?php

declare(strict_types=1);

use Modules\Auth\Public\Actions\LogoutAction;
use Modules\Core\Models\User;

/*
 * Feature coverage for the display language surviving a logout.
 *
 * SetLocale negotiates a guest's language from the session and then
 * Accept-Language, and LogoutAction invalidates the session — so a user who
 * had set the whole app to Dutch was handed an English login form, with an
 * English language picker on it, as the gate back into their own data. The
 * stored preference is carried into the fresh guest session instead.
 */

function logoutLocaleUser(?string $locale): User
{
    return User::query()->create([
        'username' => 'locale-user',
        'password' => 'a-long-password-12chars',
        'period_start_day' => 1,
        'locale' => $locale,
    ]);
}

it('carries the stored display language into the guest session', function (): void {
    $user = logoutLocaleUser('nl');
    $this->actingAs($user);

    ($this->app->make(LogoutAction::class))();

    expect(session('locale'))->toBe('nl');
    $this->assertGuest();
});

it('carries nothing when the user is on auto, so negotiation resumes', function (): void {
    // null is a real stored value meaning "auto". Writing a concrete locale
    // here would pin the guest to whatever the browser happened to negotiate
    // during the session, which is not a choice the user ever made.
    $user = logoutLocaleUser(null);
    $this->actingAs($user);

    ($this->app->make(LogoutAction::class))();

    expect(session('locale'))->toBeNull();
    $this->assertGuest();
});
