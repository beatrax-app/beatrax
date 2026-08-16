<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Models\User;

/*
 * The real flow, end to end: a fresh install renders the welcome screen, the
 * user picks a language there, signs up, and lands on the recovery codes.
 *
 * An earlier test asserted the recovery-codes screen honours a locale already
 * stored on the user row or already in the session. It passed, and the screen
 * was still English on a device — because it never exercised the two steps in
 * between: the POST that stores the choice, and the signup that has to carry
 * it onto the new user.
 */

it('keeps the welcome-screen language through signup and onto the recovery codes', function (): void {
    expect(User::query()->count())->toBe(0);

    // 1. Pick Dutch on the welcome screen.
    $this->post(route('locale.switch'), ['code' => 'nl'])->assertRedirect();
    expect(session('locale'))->toBe('nl');

    // 2. Sign up, through the same Livewire component the screen uses.
    Livewire::test(SignupPage::class)
        ->set('username', 'wessel')
        ->set('password', 'opensesame-long-enough')
        ->set('passwordConfirmation', 'opensesame-long-enough')
        ->call('submit');

    // 3. The choice must have landed on the new user, or every later request
    //    falls back to English the moment the guest session is gone.
    $user = User::query()->firstOrFail();
    expect($user->locale)->toBe('nl');

    // 4. And the screen itself renders Dutch.
    $this->get(route('auth.recovery-codes-display'))
        ->assertOk()
        ->assertSee('Bewaar deze herstelcodes')
        ->assertDontSee('Save these recovery codes');
});
