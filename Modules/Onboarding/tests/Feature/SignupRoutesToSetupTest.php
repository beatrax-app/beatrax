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
 * chain seeds exactly the six wizard_progress rows for the new user, and
 * the user is logged in.
 */

it('redirects to /setup after a successful signup', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', 'wizard-user')
        ->set('password', 'a-long-password-12chars')
        ->set('passwordConfirmation', 'a-long-password-12chars')
        ->call('submit')
        ->assertRedirect(route('setup'));

    $user = User::query()->where('username', 'wizard-user')->first();
    expect($user)->not->toBeNull();

    expect(DB::table('wizard_progress')->where('user_id', $user->id)->count())->toBe(6);

    $this->assertAuthenticatedAs($user);
});
