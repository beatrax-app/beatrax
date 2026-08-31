<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Core\Models\User;

function changePasswordSeedSession(int $userId, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'seeded',
        'payload' => base64_encode(serialize([])),
        'last_activity' => time(),
    ]);
}

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

// submit() has four side effects and this used to assert two of them, so the
// severing it promises in its own comment was never checked.
it('updates the password, clears the force flag and severs every other session on a valid submit', function (): void {
    /** @var Hasher $hasher */
    $hasher = $this->app->make(Hasher::class);

    $user = User::query()->create([
        'username' => 'partner',
        'password' => $hasher->make('initial-password-12'),
        'period_start_day' => 1,
        'force_password_change_at_next_login' => true,
    ]);

    // An account with no recovery sheet is handed one here instead, so this
    // fixture carries the one every signed-up account already has.
    DB::connection()->table('user_recovery_codes')->insert([
        'user_id' => $user->id,
        'code_hash' => $hasher->make('a-recovery-code'),
        'used_at' => null,
        'created_at' => now(),
    ]);

    DB::table('users')->where('id', $user->id)->update(['remember_token' => 'change-stale-token']);
    $liveSessionId = session()->getId();
    changePasswordSeedSession($user->id, $liveSessionId);
    changePasswordSeedSession($user->id, 'change-other-session');

    Livewire::actingAs($user)->test(ChangePasswordPage::class)
        ->set('currentPassword', 'initial-password-12')
        ->set('newPassword', 'a-brand-new-password')
        ->set('newPasswordConfirmation', 'a-brand-new-password')
        ->call('submit')
        ->assertRedirect(route('dashboard'));

    $fresh = $user->fresh();
    expect($fresh->force_password_change_at_next_login)->toBeFalse();
    expect($hasher->check('a-brand-new-password', $fresh->password))->toBeTrue();

    // This session survives to finish the redirect; nothing else does, and the
    // recaller least of all -- it re-authenticates a severed session.
    expect(DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all())
        ->toBe([$liveSessionId]);
    expect(DB::table('users')->where('id', $user->id)->value('remember_token'))
        ->not->toBe('change-stale-token');
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
