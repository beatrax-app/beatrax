<?php

declare(strict_types=1);

// Enrolment persists `secret (32 bytes) || wrapped_key_bytes` into
// user_biometric_credentials.biometric_wrap_secret — the unwrapping key beside
// the thing it unwraps, in the same SQLite file as the transactions. The only
// thing between that row and a disk-image attacker is SecretShield, and the
// container default is PassthroughSecretShield, the identity function. A
// self-hosted web deployment binds nothing else, so these drive the refusal
// that has to happen there.

use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;

function shieldedEnrolmentUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('shielded-pass'),
        'period_start_day' => 1,
    ]);
}

// A stand-in for the desktop's keychain shield: protect() genuinely changes
// the bytes, so protectsAtRest() may answer true. Reversal is enough — the
// property under test is "the stored bytes are not the plaintext".
function bindProtectingShield(): void
{
    app()->instance(SecretShield::class, new class implements SecretShield
    {
        public function protect(string $plaintext): string
        {
            return strrev($plaintext);
        }

        public function reveal(string $shielded): string
        {
            return strrev($shielded);
        }

        public function protectsAtRest(): bool
        {
            return true;
        }
    });
}

function bindNoBiometricVault(): void
{
    $vault = Mockery::mock(ColdStartVault::class);
    $vault->shouldReceive('isAvailable')->andReturn(false);
    $vault->shouldReceive('isEnrolled')->andReturn(false);

    app()->instance(ColdStartVault::class, $vault);
}

it('refuses a WebAuthn creation challenge when the bound shield does not protect the blob at rest', function (): void {
    $this->actingAs(shieldedEnrolmentUser('enrol-shield-challenge'));

    $this->postJson('/lock/biometric/challenge?enroll=1', [])
        ->assertForbidden()
        ->assertJsonPath('enrolled', false);
});

it('still issues an assertion challenge under the same pass-through shield, so unlocking an existing credential is untouched', function (): void {
    $this->actingAs(shieldedEnrolmentUser('enrol-shield-assertion'));

    $this->postJson('/lock/biometric/challenge', [])
        ->assertOk()
        ->assertJsonStructure(['challenge']);
});

it('refuses the enrolment POST itself, not only the challenge that precedes it', function (): void {
    $this->actingAs(shieldedEnrolmentUser('enrol-shield-post'));

    $response = $this->postJson('/lock/biometric/enroll', []);

    $response->assertForbidden();

    $error = $response->json('error');
    expect($error)->toBeString();
    expect($error)->not->toBe('Session not unlocked.');
    expect($error)->toContain('protected at rest');
});

it('issues the creation challenge where the shield really does protect the blob', function (): void {
    $this->actingAs(shieldedEnrolmentUser('enrol-shield-real'));
    bindProtectingShield();

    $this->postJson('/lock/biometric/challenge?enroll=1', [])
        ->assertOk()
        ->assertJsonStructure(['challenge', 'rp', 'user']);
});

it('the settings section refuses to start a browser enrolment on a self-hosted web deployment', function (): void {
    $user = shieldedEnrolmentUser('enrol-shield-section');
    $this->actingAs($user);
    app(AppLockProvisioner::class)->enable($user->id, '123456', 'shielded-pass');
    bindNoBiometricVault();

    Livewire::test(AppLockSettingsSection::class)
        ->set('lockEnabled', true)
        ->call('startEnroll')
        ->assertNotDispatched('beatrax:webauthn-create')
        ->assertSet('biometricEnrolled', false)
        ->assertSee('Biometric unlock needs an operating-system key store');
});

it('the settings section still starts a browser enrolment where a real shield is bound', function (): void {
    $user = shieldedEnrolmentUser('enrol-shield-section-ok');
    $this->actingAs($user);
    app(AppLockProvisioner::class)->enable($user->id, '123456', 'shielded-pass');
    bindNoBiometricVault();
    bindProtectingShield();

    Livewire::test(AppLockSettingsSection::class)
        ->set('lockEnabled', true)
        ->call('startEnroll')
        ->assertDispatched('beatrax:webauthn-create')
        ->assertSet('flashMessage', '');
});

it('carries the refusal in every locale', function (): void {
    $root = dirname(__DIR__, 2).'/Resources/lang';
    $locales = array_values(array_filter(scandir($root) ?: [], static fn (string $entry): bool => ! str_starts_with($entry, '.')));

    expect($locales)->toHaveCount(26);

    $missing = [];
    foreach ($locales as $locale) {
        /** @var array<string, mixed> $appLock */
        $appLock = require $root.'/'.$locale.'/app_lock.php';

        $refusal = $appLock['error_enroll_unprotected'] ?? null;
        if (! is_string($refusal) || $refusal === '') {
            $missing[] = $locale;
        }
    }

    expect($missing)->toBe([]);
});
