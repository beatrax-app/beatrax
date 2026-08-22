<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Core\Models\User;

it('renders the change-password heading, subhead and button', function (): void {
    $user = User::query()->create([
        'username' => 'partner',
        'password' => 'whatever-password',
        'period_start_day' => 1,
        'force_password_change_at_next_login' => true,
    ]);

    $this->actingAs($user)->get('/change-password')
        ->assertOk()
        ->assertSeeText('Set a new password')
        ->assertSeeText('Beatrax needs you to set your own password before you continue.')
        ->assertSeeText('Save new password');
});

it('updates the password and clears the force flag on a valid submit', function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $user = User::query()->create([
        'username' => 'partner',
        'password' => $hasher->make('initial-password-12'),
        'period_start_day' => 1,
        'force_password_change_at_next_login' => true,
    ]);

    Livewire::actingAs($user)->test(ChangePasswordPage::class)
        ->set('currentPassword', 'initial-password-12')
        ->set('newPassword', 'a-brand-new-password')
        ->set('newPasswordConfirmation', 'a-brand-new-password')
        ->call('submit')
        ->assertRedirect(route('dashboard'));

    $fresh = $user->fresh();
    expect($fresh->force_password_change_at_next_login)->toBeFalse();
    expect($hasher->check('a-brand-new-password', $fresh->password))->toBeTrue();
});

it('flashes an error and leaves the password untouched on a wrong current password', function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $user = User::query()->create([
        'username' => 'partner',
        'password' => $hasher->make('initial-password-12'),
        'period_start_day' => 1,
        'force_password_change_at_next_login' => true,
    ]);

    Livewire::actingAs($user)->test(ChangePasswordPage::class)
        ->set('currentPassword', 'wrong-password')
        ->set('newPassword', 'a-brand-new-password')
        ->set('newPasswordConfirmation', 'a-brand-new-password')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSet('flashMessage', 'Current password is incorrect.');

    $fresh = $user->fresh();
    expect($fresh->force_password_change_at_next_login)->toBeTrue();
    expect($hasher->check('initial-password-12', $fresh->password))->toBeTrue();
});

it('flashes a mismatch error when the new passwords differ', function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $user = User::query()->create([
        'username' => 'partner',
        'password' => $hasher->make('initial-password-12'),
        'period_start_day' => 1,
        'force_password_change_at_next_login' => true,
    ]);

    Livewire::actingAs($user)->test(ChangePasswordPage::class)
        ->set('currentPassword', 'initial-password-12')
        ->set('newPassword', 'a-brand-new-password')
        ->set('newPasswordConfirmation', 'a-different-password')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSet('flashMessage', 'Passwords do not match.');
});

it('flashes a length error when the new password is shorter than twelve characters', function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $user = User::query()->create([
        'username' => 'partner',
        'password' => $hasher->make('initial-password-12'),
        'period_start_day' => 1,
        'force_password_change_at_next_login' => true,
    ]);

    Livewire::actingAs($user)->test(ChangePasswordPage::class)
        ->set('currentPassword', 'initial-password-12')
        ->set('newPassword', 'short')
        ->set('newPasswordConfirmation', 'short')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSet('flashMessage', 'Use at least 12 characters.');
});

// A box left empty and a box filled in wrongly are different states, and the
// page called both incorrect — which sends a reader who typed nothing off to
// look up a password rather than back to the field they skipped.
it('names the empty current-password box rather than calling it incorrect', function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $user = User::query()->create([
        'username' => 'partner',
        'password' => $hasher->make('initial-password-12'),
        'period_start_day' => 1,
        'force_password_change_at_next_login' => true,
    ]);

    Livewire::actingAs($user)->test(ChangePasswordPage::class)
        ->set('currentPassword', '')
        ->set('newPassword', 'a-brand-new-password')
        ->set('newPasswordConfirmation', 'a-brand-new-password')
        ->call('submit')
        ->assertSet('flashMessage', 'Enter your current password.')
        ->assertNoRedirect();

    expect($hasher->check('initial-password-12', (string) $user->fresh()?->password))->toBeTrue();
});
