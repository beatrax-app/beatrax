<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;

/*
 * Feature coverage for the first-user signup page: the route render and
 * its 404 gate, the Livewire submit happy path, and the inline error
 * copy for mismatched and too-short passwords.
 */

it('renders the signup page on a fresh database', function (): void {
    $this->get('/signup')
        ->assertOk()
        ->assertSeeText('Welcome to beatrax')
        ->assertSeeText('Create the first account on this device. The first account becomes the owner.')
        ->assertSeeText('Create the first account');
});

it('returns 404 when a user already exists', function (): void {
    User::query()->create([
        'username' => 'existing',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    $this->get('/signup')->assertNotFound();
});

it('signs up the first user successfully and redirects to the setup wizard', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', 'alice')
        ->set('password', 'a-long-password-12chars')
        ->set('passwordConfirmation', 'a-long-password-12chars')
        ->call('submit')
        ->assertRedirect(route('setup'));

    $user = User::query()->where('username', 'alice')->first();
    expect($user)->not->toBeNull();
    expect($user->is_developer)->toBeTrue();
    expect(UserRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(10);

    $this->assertAuthenticatedAs($user);
});

it('rejects mismatched passwords', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', 'alice')
        ->set('password', 'a-long-password-12chars')
        ->set('passwordConfirmation', 'a-different-password')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSet('flashMessage', 'Passwords do not match.');

    expect(User::query()->count())->toBe(0);
});

it('rejects passwords shorter than twelve characters', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', 'alice')
        ->set('password', 'short')
        ->set('passwordConfirmation', 'short')
        ->call('submit')
        ->assertNoRedirect()
        ->assertSet('flashMessage', 'Use at least 12 characters.');

    expect(User::query()->count())->toBe(0);
});

it('makes the first signed-up user a developer', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', 'alice')
        ->set('password', 'a-long-password-12chars')
        ->set('passwordConfirmation', 'a-long-password-12chars')
        ->call('submit');

    expect(User::query()->where('username', 'alice')->value('is_developer'))->toBeTrue();
});

it('lowercases the username before storage', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', 'Alice')
        ->set('password', 'a-long-password-12chars')
        ->set('passwordConfirmation', 'a-long-password-12chars')
        ->call('submit');

    expect(User::query()->where('username', 'alice')->exists())->toBeTrue();
});
