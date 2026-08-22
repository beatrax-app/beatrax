<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Internal\Http\Livewire\ResetPasswordPage;
use Modules\Auth\Internal\Lock\AppLockDisableResult;
use Modules\Auth\Internal\Lock\AppLockKeyState;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;

// The recovery wrap is the only thing standing behind a forgotten PIN, and
// nothing reads it until that day — so every assertion here is about whether
// the account password still produces the SAME data key, not about columns.

/**
 * @return array{user: User, codes: list<string>}
 */
function recoveryWrapOwner(string $username, string $password): array
{
    /** @var SignupAction $signup */
    $signup = app(SignupAction::class);

    $result = $signup($username, $password);

    return ['user' => $result['user'], 'codes' => $result['codesPlain']];
}

function recoveryWrapSetPin(string $pin, string $password): void
{
    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', $pin)
        ->set('confirmPin', $pin)
        ->set('accountPassword', $password)
        ->call('setPin');
}

// What the account password is actually worth: the data key it produces at the
// next sign-in. A fingerprint, so a mismatch reads as a mismatch.
function recoveryWrapKeyFromPassword(int $userId, string $password): ?string
{
    /** @var Session $session */
    $session = app(Session::class);

    /** @var AppLockKeyService $keys */
    $keys = app(AppLockKeyService::class);
    $keys->withhold($session);

    app(AppLockProvisioner::class)->primeSessionAfterLogin($userId, $password, $session);

    $key = $keys->release($session);

    return $key === null ? null : substr(hash('sha256', $key), 0, 12);
}

// actingAs() pins the model instance it was handed, and every password path
// here rewrites users.password underneath it — so the component would keep
// checking against a hash the database no longer has.
function recoveryWrapReload(int $userId): User
{
    /** @var User $user */
    $user = User::query()->findOrFail($userId);

    return $user;
}

function recoveryWrapAlertCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('system_alerts')
        ->where('user_id', $userId)
        ->where('kind', 'auth.lock.recovery_wrap_stale')
        ->count();
}

it('carries the recovery wrap across a password change, so the same data key comes back', function (): void {
    ['user' => $user] = recoveryWrapOwner('rewrap-owner', 'old-password-123');
    $this->actingAs($user);

    recoveryWrapSetPin('123456', 'old-password-123');
    $before = recoveryWrapKeyFromPassword((int) $user->id, 'old-password-123');
    expect($before)->not->toBeNull();

    Livewire::test(ChangePasswordPage::class)
        ->set('currentPassword', 'old-password-123')
        ->set('newPassword', 'new-password-456')
        ->set('newPasswordConfirmation', 'new-password-456')
        ->call('submit');

    expect(recoveryWrapKeyFromPassword((int) $user->id, 'new-password-456'))->toBe($before);
    expect(app(AppLockProvisioner::class)->keyState((int) $user->id))->toBe(AppLockKeyState::Held);
});

// The user-facing half of the same fact: the reset the "Forgot your PIN?" link
// runs needs that wrap, and is the only thing that reads it.
it('keeps the forgotten-PIN reset working after a password change', function (): void {
    ['user' => $user] = recoveryWrapOwner('rewrap-forgot', 'old-password-123');
    $this->actingAs($user);

    recoveryWrapSetPin('123456', 'old-password-123');

    Livewire::test(ChangePasswordPage::class)
        ->set('currentPassword', 'old-password-123')
        ->set('newPassword', 'new-password-456')
        ->set('newPasswordConfirmation', 'new-password-456')
        ->call('submit');

    $this->actingAs(recoveryWrapReload((int) $user->id));

    Livewire::test(AppLockSettingsSection::class)
        ->set('accountPassword', 'new-password-456')
        ->set('newPin', '654321')
        ->set('confirmPin', '654321')
        ->call('resetForgottenPin')
        ->assertSet('flashMessage', '')
        ->assertDispatched('toast');
});

it('says so instead of failing quietly when a recovery-code reset cannot carry the wrap', function (): void {
    ['user' => $user, 'codes' => $codes] = recoveryWrapOwner('rewrap-reset', 'old-password-123');
    $this->actingAs($user);

    recoveryWrapSetPin('123456', 'old-password-123');

    Livewire::test(ResetPasswordPage::class)
        ->set('username', 'rewrap-reset')
        ->set('recoveryCode', $codes[0])
        ->set('newPassword', 'code-reset-password')
        ->set('newPasswordConfirmation', 'code-reset-password')
        ->call('submit');

    expect(app(AppLockProvisioner::class)->keyState((int) $user->id))->toBe(AppLockKeyState::RecoveryUnreadable);
    expect(recoveryWrapAlertCount((int) $user->id))->toBe(1);
});

it('says so when an owner sets a partner password it holds no old password for', function (): void {
    ['user' => $owner] = recoveryWrapOwner('rewrap-owner-dev', 'owner-password-123');

    /** @var User $partner */
    $partner = User::query()->create([
        'username' => 'rewrap-partner',
        'password' => bcrypt('partner-password-123'),
        'period_start_day' => 1,
    ]);

    $this->actingAs($partner);
    recoveryWrapSetPin('123456', 'partner-password-123');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('users')->where('id', $owner->id)->update(['is_developer' => true]);

    $this->actingAs(recoveryWrapReload((int) $owner->id));

    Livewire::test(ManageUserPage::class, ['username' => 'rewrap-partner'])
        ->set('newPartnerPassword', 'set-by-owner-123')
        ->call('setPartnerPassword');

    expect(app(AppLockProvisioner::class)->keyState((int) $partner->id))->toBe(AppLockKeyState::RecoveryUnreadable);
    expect(recoveryWrapAlertCount((int) $partner->id))->toBe(1);
});

it('offers the re-link on the screen the lock is configured from, and repairs the wrap', function (): void {
    ['user' => $user, 'codes' => $codes] = recoveryWrapOwner('rewrap-relink', 'old-password-123');
    $this->actingAs($user);

    recoveryWrapSetPin('123456', 'old-password-123');
    $before = recoveryWrapKeyFromPassword((int) $user->id, 'old-password-123');

    Livewire::test(ResetPasswordPage::class)
        ->set('username', 'rewrap-relink')
        ->set('recoveryCode', $codes[0])
        ->set('newPassword', 'code-reset-password')
        ->set('newPasswordConfirmation', 'code-reset-password')
        ->call('submit');

    $this->actingAs(recoveryWrapReload((int) $user->id));

    expect(recoveryWrapKeyFromPassword((int) $user->id, 'code-reset-password'))->toBeNull();

    Livewire::test(AppLockSettingsSection::class)
        ->assertSet('recoveryWrapStale', true)
        ->assertSee('no longer opens this app lock')
        ->call('confirmRelinkRecovery')
        ->set('currentPin', '123456')
        ->set('accountPassword', 'code-reset-password')
        ->call('relinkRecovery')
        ->assertSet('recoveryWrapStale', false)
        ->assertDispatched('toast');

    // The repair has to return the ORIGINAL key, not a new one: everything
    // encrypted under it is still encrypted under it.
    expect(recoveryWrapKeyFromPassword((int) $user->id, 'code-reset-password'))->toBe($before);
    expect(app(AppLockProvisioner::class)->keyState((int) $user->id))->toBe(AppLockKeyState::Held);
});

it('refuses the re-link on a wrong PIN and leaves the wrap stale', function (): void {
    ['user' => $user, 'codes' => $codes] = recoveryWrapOwner('rewrap-wrong-pin', 'old-password-123');
    $this->actingAs($user);

    recoveryWrapSetPin('123456', 'old-password-123');

    Livewire::test(ResetPasswordPage::class)
        ->set('username', 'rewrap-wrong-pin')
        ->set('recoveryCode', $codes[0])
        ->set('newPassword', 'code-reset-password')
        ->set('newPasswordConfirmation', 'code-reset-password')
        ->call('submit');

    $this->actingAs(recoveryWrapReload((int) $user->id));

    Livewire::test(AppLockSettingsSection::class)
        ->call('confirmRelinkRecovery')
        ->set('currentPin', '999999')
        ->set('accountPassword', 'code-reset-password')
        ->call('relinkRecovery')
        ->assertSee('Incorrect PIN.');

    expect(app(AppLockProvisioner::class)->keyState((int) $user->id))->toBe(AppLockKeyState::RecoveryUnreadable);
});

// A fault nobody has must not be reported: without a lock there is no wrap to
// carry over, and stamping one would put a critical alert on a healthy account.
it('reports nothing for a user who has no app lock at all', function (): void {
    ['user' => $user, 'codes' => $codes] = recoveryWrapOwner('rewrap-no-lock', 'old-password-123');
    $this->actingAs($user);

    Livewire::test(ChangePasswordPage::class)
        ->set('currentPassword', 'old-password-123')
        ->set('newPassword', 'new-password-456')
        ->set('newPasswordConfirmation', 'new-password-456')
        ->call('submit');

    Livewire::test(ResetPasswordPage::class)
        ->set('username', 'rewrap-no-lock')
        ->set('recoveryCode', $codes[0])
        ->set('newPassword', 'code-reset-password')
        ->set('newPasswordConfirmation', 'code-reset-password')
        ->call('submit');

    expect(app(AppLockProvisioner::class)->keyState((int) $user->id))->toBe(AppLockKeyState::Absent);
    expect(recoveryWrapAlertCount((int) $user->id))->toBe(0);
});

// A stamp is about one wrap. Re-provisioning writes a new one, so a stamp that
// outlived it would report a dead recovery path over a live wrap — and the
// screen would offer a re-link for a fault that had already been repaired.
it('clears the stale stamp when the lock is re-provisioned', function (): void {
    ['user' => $user, 'codes' => $codes] = recoveryWrapOwner('rewrap-restamp', 'old-password-123');
    $this->actingAs($user);

    recoveryWrapSetPin('123456', 'old-password-123');

    Livewire::test(ResetPasswordPage::class)
        ->set('username', 'rewrap-restamp')
        ->set('recoveryCode', $codes[0])
        ->set('newPassword', 'code-reset-password')
        ->set('newPasswordConfirmation', 'code-reset-password')
        ->call('submit');

    $this->actingAs(recoveryWrapReload((int) $user->id));

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    expect($provisioner->keyState((int) $user->id))->toBe(AppLockKeyState::RecoveryUnreadable);

    expect($provisioner->disable((int) $user->id, '123456'))->toBe(AppLockDisableResult::Disabled);
    expect($provisioner->keyState((int) $user->id))->toBe(AppLockKeyState::Absent);

    recoveryWrapSetPin('654321', 'code-reset-password');

    expect($provisioner->keyState((int) $user->id))->toBe(AppLockKeyState::Held);
    expect(recoveryWrapKeyFromPassword((int) $user->id, 'code-reset-password'))->not->toBeNull();
});

// The backstop for a password writer nobody enumerated: whatever replaced the
// password, the sign-in that finds the wrap dead is proof, and it says so
// rather than quietly starting locked.
it('stamps a dead recovery wrap at the sign-in that finds it, whatever killed it', function (): void {
    ['user' => $user] = recoveryWrapOwner('rewrap-signin-detect', 'old-password-123');
    $this->actingAs($user);

    recoveryWrapSetPin('123456', 'old-password-123');

    // A password rewritten straight on the row, past every path that knows to
    // carry the wrap — which is exactly what an unenumerated writer looks like.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('users')->where('id', $user->id)->update([
        'password' => bcrypt('rewritten-behind-our-back'),
    ]);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    expect($provisioner->keyState((int) $user->id))->toBe(AppLockKeyState::Held);

    expect(recoveryWrapKeyFromPassword((int) $user->id, 'rewritten-behind-our-back'))->toBeNull();

    expect($provisioner->keyState((int) $user->id))->toBe(AppLockKeyState::RecoveryUnreadable);
    expect(recoveryWrapAlertCount((int) $user->id))->toBe(1);
});
