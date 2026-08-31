<?php

declare(strict_types=1);

use Modules\Auth\Public\Actions\LoginAction;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Models\User;

// Username::isValid admits \p{L} in any script, so a name can be stored with a
// non-ASCII capital. strtolower folds ASCII only, while Fortify's own
// CanonicalizeUsername folds multibyte, so the account was written under one
// spelling and looked up under another: "invalid credentials", with nothing on
// the screen that could tell the reader why.

const NON_ASCII_ACCOUNT = 'Ölaf';

const NON_ASCII_PASSWORD = 'a-long-password-12chars';

function nonAsciiOwner(): User
{
    /** @var SignupAction $signup */
    $signup = app(SignupAction::class);

    /** @var User $user */
    $user = $signup(NON_ASCII_ACCOUNT, NON_ASCII_PASSWORD)['user'];

    return $user;
}

it('folds a non-ASCII capital when it stores the account', function (): void {
    expect(nonAsciiOwner()->username)->toBe('ölaf');
});

it('signs the account in from the lowercase spelling the reader types', function (): void {
    nonAsciiOwner();

    /** @var LoginAction $login */
    $login = $this->app->make(LoginAction::class);

    expect($login('ölaf', NON_ASCII_PASSWORD, false))->toBeTrue();
});

it('signs the account in through the Fortify post as well', function (): void {
    nonAsciiOwner();

    $this->post('/login', ['username' => NON_ASCII_ACCOUNT, 'password' => NON_ASCII_PASSWORD]);

    $this->assertAuthenticated();
});

it('finds the same account from the console', function (): void {
    nonAsciiOwner();

    $this->artisan('beatrax:grant-dev', ['username' => NON_ASCII_ACCOUNT])->assertSuccessful();

    expect(User::query()->where('username', 'ölaf')->value('is_developer'))->toBeTruthy();
});

it('agrees with the form Fortify canonicalises the typed name to', function (): void {
    // Fortify lowercases the posted username with Str::lower, which is
    // multibyte. Anything the app normalises differently is a name it can
    // store but never look up.
    expect(Username::normalize(NON_ASCII_ACCOUNT))->toBe(mb_strtolower(NON_ASCII_ACCOUNT));
});
