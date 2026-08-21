<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

it('renders the signup page on a fresh database', function (): void {
    $this->get('/signup')
        ->assertOk()
        ->assertSeeText('Welcome to Beatrax')
        ->assertSeeText('Create the first account on this device. The first account becomes the owner.')
        ->assertSeeText('Create the first account');
});

it('shows field requirement hints and a live password requirement checklist', function (): void {
    $response = $this->get('/signup')->assertOk();

    $response->assertSeeText('Saved in lowercase. This becomes the owner account.');
    $response->assertSeeText('Use a passphrase you can remember — there is no password reset, only recovery codes.');

    // The Alpine checklist: its labels live in the x-for data, so they are in
    // the markup even though the state is computed client-side.
    $response->assertSee('Password requirements', escape: false);
    $response->assertSee('At least 12 characters', escape: false);
    $response->assertSee('Both passwords match', escape: false);
    $response->assertSee('aria-describedby="password-requirements"', escape: false);
    $response->assertSee('lengthOk', escape: false);
    $response->assertSee('matchOk', escape: false);
});

it('returns 404 when a user already exists', function (): void {
    User::query()->create([
        'username' => 'existing',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    $this->get('/signup')->assertNotFound();
});

it('signs up the first user successfully and redirects to the recovery codes', function (): void {
    // Not to the wizard: the codes come first, and that screen hands off to
    // /setup afterwards.
    Livewire::test(SignupPage::class)
        ->set('username', 'alice')
        ->set('password', 'a-long-password-12chars')
        ->set('passwordConfirmation', 'a-long-password-12chars')
        ->call('submit')
        ->assertRedirect(route('auth.recovery-codes-display'));

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
        ->assertHasErrors(['passwordConfirmation' => 'Passwords do not match.']);

    expect(User::query()->count())->toBe(0);
});

it('rejects passwords shorter than twelve characters', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', 'alice')
        ->set('password', 'short')
        ->set('passwordConfirmation', 'short')
        ->call('submit')
        ->assertNoRedirect()
        ->assertHasErrors(['password' => 'Use at least 12 characters.']);

    expect(User::query()->count())->toBe(0);
});

// Submitting the blank form is the first thing a reader can do on a fresh
// install, and it used to reach an InvalidArgumentException the page does not
// catch — a 500 with a stack trace instead of a message.
it('rejects an empty username with a message rather than an unhandled exception', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', '')
        ->set('password', '')
        ->set('passwordConfirmation', '')
        ->call('submit')
        ->assertNoRedirect()
        ->assertHasErrors(['username' => 'Use up to 32 letters, digits, dots, dashes or underscores.']);

    expect(User::query()->count())->toBe(0);
});

it('rejects a whitespace-only username, which normalises to empty', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', '   ')
        ->set('password', 'a-long-password-12chars')
        ->set('passwordConfirmation', 'a-long-password-12chars')
        ->call('submit')
        ->assertNoRedirect()
        ->assertHasErrors(['username' => 'Use up to 32 letters, digits, dots, dashes or underscores.']);

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

// Onboarding renders for a guest, so it has none of the chrome that carries a
// back affordance — and the iOS WebView's back gesture is off, which is not
// something this repo can turn on from PHP.
it('offers a way back to the screen that led here', function (): void {
    $html = (string) Livewire::test(SignupPage::class)->html();

    expect($html)->toContain(route('desktop.welcome'))
        ->and($html)->toContain(Lang::get('core::components.topbar.back'));
});

// The checklist the browser ticks and the gate SignupAction enforces are one
// number, read from one place. When they were two, the client could wave
// through a passphrase the server then rejected, which is worse than drawing
// no checklist at all.
it('draws the checklist against the same minimum the signup gate enforces', function (): void {
    $minimum = PasswordPolicy::MINIMUM_LENGTH;

    $this->get('/signup')
        ->assertOk()
        ->assertSee("passwordStrength({$minimum}, 'password', 'passwordConfirmation')", escape: false);

    Livewire::test(SignupPage::class)
        ->set('username', 'alice')
        ->set('password', str_repeat('a', $minimum - 1))
        ->set('passwordConfirmation', str_repeat('a', $minimum - 1))
        ->call('submit')
        ->assertNoRedirect()
        ->assertHasErrors(['password']);

    expect(User::query()->count())->toBe(0);

    Livewire::test(SignupPage::class)
        ->set('username', 'alice')
        ->set('password', str_repeat('a', $minimum))
        ->set('passwordConfirmation', str_repeat('a', $minimum))
        ->call('submit')
        ->assertHasNoErrors();

    expect(User::query()->where('username', 'alice')->exists())->toBeTrue();
});
