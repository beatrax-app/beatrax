<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Models\User;

// Signup reaches the wizard only via the recovery-codes ceremony. Those
// codes are the account's only recovery path, so a redirect that skipped
// them would break the promise the signup form makes.

it('routes a successful signup to the recovery codes, and on to the setup wizard', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', 'wizard-user')
        ->set('password', 'a-long-password-12chars')
        ->set('passwordConfirmation', 'a-long-password-12chars')
        ->call('submit')
        ->assertRedirect(route('auth.recovery-codes-display'));

    // Asserted here as well as in Auth's own test: it is the chain, not
    // either redirect alone, that gets the user to the wizard.
    Livewire::test(RecoveryCodesDisplay::class)
        ->set('confirmed', true)
        ->call('continueAfterSave')
        ->assertRedirect(route('setup'));

    $user = User::query()->where('username', 'wizard-user')->first();
    expect($user)->not->toBeNull();

    expect(DB::table('wizard_progress')->where('user_id', $user->id)->count())->toBe(9);

    $this->assertAuthenticatedAs($user);
});

it('exposes the setup-wizard URL as exactly /setup-wizard', function (): void {
    // Pinned literally, because UI copy elsewhere hard-codes this URL.
    expect(route('setup', [], absolute: false))->toBe('/setup-wizard');
});
