<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Models\User;

// The older test asserted the recovery-codes screen honours a locale already
// on the row, and passed while the device showed English: it skipped the POST
// that stores the choice and the signup that carries it onto the new user.

it('keeps the welcome-screen language through signup and onto the recovery codes', function (): void {
    expect(User::query()->count())->toBe(0);

    $this->post(route('locale.switch'), ['code' => 'nl'])->assertRedirect();
    expect(session('locale'))->toBe('nl');

    Livewire::test(SignupPage::class)
        ->set('username', 'wessel')
        ->set('password', 'opensesame-long-enough')
        ->set('passwordConfirmation', 'opensesame-long-enough')
        ->call('submit');

    // The choice must land on the user row, or every later request falls back
    // to English the moment the guest session is gone.
    $user = User::query()->firstOrFail();
    expect($user->locale)->toBe('nl');

    $this->get(route('auth.recovery-codes-display'))
        ->assertOk()
        ->assertSee('Bewaar deze herstelcodes')
        ->assertDontSee('Save these recovery codes');
});
