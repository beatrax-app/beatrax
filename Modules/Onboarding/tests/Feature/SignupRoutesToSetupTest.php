<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Models\User;

/*
 * Feature coverage for the post-signup redirect to the first-run setup
 * wizard: the Livewire signup component submits valid credentials and
 * the resulting redirect lands on /setup, the UserInstalled listener
 * chain seeds exactly the seven wizard_progress rows for the new user
 * (one per step in WizardStepRegistry::STEPS), and the user is logged in.
 */

it('redirects to the setup-wizard route after a successful signup', function (): void {
    // The wizard's URL is `/setup-wizard` (the `/setup` URL is owned by
    // the Desktop module's migration splash). Assert via the symbolic
    // route name so a future URL move stays test-stable.
    Livewire::test(SignupPage::class)
        ->set('username', 'wizard-user')
        ->set('password', 'a-long-password-12chars')
        ->set('passwordConfirmation', 'a-long-password-12chars')
        ->call('submit')
        ->assertRedirect(route('setup'));

    $user = User::query()->where('username', 'wizard-user')->first();
    expect($user)->not->toBeNull();

    expect(DB::table('wizard_progress')->where('user_id', $user->id)->count())->toBe(7);

    $this->assertAuthenticatedAs($user);
});

it('exposes the setup-wizard URL as exactly /setup-wizard', function (): void {
    // Pins the literal URL so a future rename forces a test update +
    // documents the chosen URL for downstream code that copy-pastes
    // links into UI copy.
    expect(route('setup', [], absolute: false))->toBe('/setup-wizard');
});
