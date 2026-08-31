<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Auth\Public\Actions\AddUserAction;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Models\User;

// A partner used to be issued ten recovery codes at the moment they were
// created: the owner is never shown them and the partner is not there, so ten
// working credentials existed that no human held, while the reset-password
// screen asks that partner for a code they were never given.

function householdOwnerAccount(): User
{
    /** @var SignupAction $signup */
    $signup = app(SignupAction::class);

    /** @var User $owner */
    $owner = $signup('owner', 'a-long-password-12chars')['user'];

    return $owner;
}

function addedPartnerAccount(): User
{
    /** @var AddUserAction $addUser */
    $addUser = app(AddUserAction::class);

    return $addUser(householdOwnerAccount(), 'partner', 'partner-initial-pw-12');
}

it('mints no recovery codes at the moment nobody is there to take them', function (): void {
    $partner = addedPartnerAccount();

    expect(DB::connection()->table('user_recovery_codes')->where('user_id', $partner->id)->count())->toBe(0);
});

it('hands the partner their sheet at the forced password change their first sign-in lands on', function (): void {
    $partner = addedPartnerAccount();

    Livewire::actingAs($partner)->test(ChangePasswordPage::class)
        ->set('currentPassword', 'partner-initial-pw-12')
        ->set('newPassword', 'a-password-of-their-own')
        ->set('newPasswordConfirmation', 'a-password-of-their-own')
        ->call('submit')
        ->assertRedirect(route('auth.recovery-codes-display'));

    /** @var list<string> $codes */
    $codes = session(RecoveryCodesDisplay::SESSION_KEY);

    expect($codes)->toHaveCount(10)
        ->and(DB::connection()->table('user_recovery_codes')->where('user_id', $partner->id)->count())->toBe(10);
});

it('gives the partner codes that actually open their account', function (): void {
    $partner = addedPartnerAccount();

    Livewire::actingAs($partner)->test(ChangePasswordPage::class)
        ->set('currentPassword', 'partner-initial-pw-12')
        ->set('newPassword', 'a-password-of-their-own')
        ->set('newPasswordConfirmation', 'a-password-of-their-own')
        ->call('submit');

    /** @var list<string> $codes */
    $codes = session(RecoveryCodesDisplay::SESSION_KEY);

    /** @var RecoveryCodeAuthenticator $authenticator */
    $authenticator = $this->app->make(RecoveryCodeAuthenticator::class);

    expect($authenticator->verify('partner', $codes[0])?->id)->toBe($partner->id);
});

it('sends the partner on to the dashboard once the sheet is saved, not into the setup wizard', function (): void {
    $partner = addedPartnerAccount();

    Livewire::actingAs($partner)->test(ChangePasswordPage::class)
        ->set('currentPassword', 'partner-initial-pw-12')
        ->set('newPassword', 'a-password-of-their-own')
        ->set('newPasswordConfirmation', 'a-password-of-their-own')
        ->call('submit');

    Livewire::actingAs($partner)->test(RecoveryCodesDisplay::class)
        ->set('confirmed', true)
        ->call('continueAfterSave')
        ->assertRedirect(route('dashboard'));
});

it('leaves an account that already holds a sheet on its ordinary way out', function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $owner = householdOwnerAccount();
    $owner->update([
        'password' => $hasher->make('the-owners-password'),
        'force_password_change_at_next_login' => true,
    ]);

    Livewire::actingAs($owner->fresh())->test(ChangePasswordPage::class)
        ->set('currentPassword', 'the-owners-password')
        ->set('newPassword', 'another-owner-password')
        ->set('newPasswordConfirmation', 'another-owner-password')
        ->call('submit')
        ->assertRedirect(route('dashboard'));

    expect(DB::connection()->table('user_recovery_codes')->where('user_id', $owner->id)->count())->toBe(10);
});
