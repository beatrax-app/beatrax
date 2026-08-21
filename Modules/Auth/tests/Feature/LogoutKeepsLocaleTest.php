<?php

declare(strict_types=1);

use Modules\Auth\Public\Actions\LogoutAction;
use Modules\Core\Models\User;

// A guest's language is negotiated from the session, and logout invalidates
// it — so a user who had set the app to Dutch was handed an English login form
// as the gate back into their own data.

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
    // null is the stored value for "auto"; a concrete locale here would pin the
    // guest to whatever the browser negotiated.
    $user = logoutLocaleUser(null);
    $this->actingAs($user);

    ($this->app->make(LogoutAction::class))();

    expect(session('locale'))->toBeNull();
    $this->assertGuest();
});
