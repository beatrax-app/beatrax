<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\RecoveryCodesDisplay;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Models\User;

/*
 * Feature coverage for the post-signup route to the first-run setup wizard.
 * Signup does not reach the wizard directly: it lands on the recovery-codes
 * ceremony, which hands off to /setup once the user confirms they have saved
 * the codes. The chain matters because those codes are the account's only
 * recovery path, and a redirect that skipped them left the signup form's own
 * promise — no password recovery, only recovery codes — unkept.
 *
 * Also covers that the UserInstalled listener chain seeds exactly the nine
 * wizard_progress rows for the new user (one per step in
 * WizardStepRegistry::STEPS), and that the user is logged in.
 */

it('routes a successful signup to the recovery codes, and on to the setup wizard', function (): void {
    // The wizard's URL is `/setup-wizard` (the `/setup` URL is owned by
    // the Desktop module's migration splash). Assert via the symbolic
    // route name so a future URL move stays test-stable.
    Livewire::test(SignupPage::class)
        ->set('username', 'wizard-user')
        ->set('password', 'a-long-password-12chars')
        ->set('passwordConfirmation', 'a-long-password-12chars')
        ->call('submit')
        ->assertRedirect(route('auth.recovery-codes-display'));

    // The second leg, asserted here rather than only in the Auth module's
    // own test, because it is this chain — not either redirect alone — that
    // gets the user to the wizard.
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
    // Pins the literal URL so a future rename forces a test update +
    // documents the chosen URL for downstream code that copy-pastes
    // links into UI copy.
    expect(route('setup', [], absolute: false))->toBe('/setup-wizard');
});
