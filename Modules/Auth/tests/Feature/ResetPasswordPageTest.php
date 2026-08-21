<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\ResetPasswordPage;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Auth\Public\Actions\LogoutAction;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Models\User;

/**
 * @return array{user: User, codes: list<string>}
 */
function resetPageOwner(): array
{
    /** @var SignupAction $signup */
    $signup = app(SignupAction::class);

    $result = $signup('owner', 'owner-password-123');

    return ['user' => $result['user'], 'codes' => $result['codesPlain']];
}

it('renders the reset-password heading, subhead, inline help and button', function (): void {
    // A zero-user state is funnelled through the welcome screen, so an owner
    // has to exist first — and signup logs them in, while this route is guest.
    resetPageOwner();
    /** @var LogoutAction $logout */
    $logout = $this->app->make(LogoutAction::class);
    $logout();

    $this->get('/reset-password')
        ->assertOk()
        ->assertSeeText('Reset your password')
        ->assertSeeText('Type your username, one of your recovery codes, and a new password. The code will be marked used.')
        ->assertSeeText('5 groups of 4 characters, like A2BJ-XK9M-PQ7N-RX4F-V8HD.')
        ->assertSeeText('Save new password');
});

it('registers the reset-password route under the auth.reset-password name', function (): void {
    expect(route('auth.reset-password'))->toEndWith('/reset-password');
});

it('redeems a valid code and redirects to login with the success flash', function (): void {
    ['codes' => $codes] = resetPageOwner();

    Livewire::test(ResetPasswordPage::class)
        ->set('username', 'owner')
        ->set('recoveryCode', $codes[0])
        ->set('newPassword', 'a-brand-new-password')
        ->set('newPasswordConfirmation', 'a-brand-new-password')
        ->call('submit')
        ->assertRedirect(route('login'));

    /** @var Hasher $hasher */
    $hasher = app(Hasher::class);
    $fresh = User::query()->where('username', 'owner')->first();
    expect($hasher->check('a-brand-new-password', $fresh->password))->toBeTrue();
});

it('flashes a session message after a successful redemption', function (): void {
    ['codes' => $codes] = resetPageOwner();

    Livewire::test(ResetPasswordPage::class)
        ->set('username', 'owner')
        ->set('recoveryCode', $codes[0])
        ->set('newPassword', 'a-brand-new-password')
        ->set('newPasswordConfirmation', 'a-brand-new-password')
        ->call('submit');

    expect(session('flash.reset_password'))
        ->toBe('Password updated. Sign in with your new password.');
});

it('shows the verbatim wrong-code error and leaves the password untouched', function (): void {
    /** @var Hasher $hasher */
    $hasher = app(Hasher::class);

    resetPageOwner();
    $before = User::query()->where('username', 'owner')->first()->password;

    Livewire::test(ResetPasswordPage::class)
        ->set('username', 'owner')
        ->set('recoveryCode', 'AAAA-BBBB-CCCC-DDDD-EEEE')
        ->set('newPassword', 'a-brand-new-password')
        ->set('newPasswordConfirmation', 'a-brand-new-password')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSet('flashMessage', 'That username and recovery code do not match. Check the code carefully — uppercase, no zero, no oh, no one, no L.');

    expect(User::query()->where('username', 'owner')->first()->password)->toBe($before);
});

it('shows a mismatch error when the two new passwords differ', function (): void {
    ['codes' => $codes] = resetPageOwner();

    Livewire::test(ResetPasswordPage::class)
        ->set('username', 'owner')
        ->set('recoveryCode', $codes[0])
        ->set('newPassword', 'a-brand-new-password')
        ->set('newPasswordConfirmation', 'a-different-password')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSet('flashMessage', 'Passwords do not match.');

    // Nothing is consumed when the form fails before verification.
    $unused = UserRecoveryCode::query()->whereNull('used_at')->count();
    expect($unused)->toBe(10);
});
