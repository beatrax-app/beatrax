<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\NullColdStartVault;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Support\Lang;

// Pressing Enroll on an iPhone with no face enrolled answered "This version of
// Beatrax has nowhere to store an unlock key, so biometric unlock is not
// offered. Your device is not the limitation." Every clause was wrong there:
// the build carries the vault, the offer was two rows above the sentence, and
// the platform had answered none_enrolled — the device WAS the limitation, and
// enrolling a face was the one thing the reader could have done about it.
// Walked on an iPhone 12 mini on 2026-09-13.

function refusalUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('refusal-pass'),
        'period_start_day' => 1,
    ]);
}

// Defined here rather than borrowed: Pest loads only the file it is asked to
// run, so a helper taken from a sibling makes this suite fail on its own.
function refusalShield(): void
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

function refusalInAShell(): void
{
    app(ConfigRepository::class)->set('nativephp-internal.running', true);
}

// A vault that IS bound and answers no — an enclave with nothing enrolled in
// it, which is not the same device as one whose build has no vault at all.
function refusalBindRefusingVault(): void
{
    $vault = Mockery::mock(ColdStartVault::class);
    $vault->shouldReceive('isAvailable')->andReturn(false);
    $vault->shouldReceive('isEnrolled')->andReturn(false);
    $vault->shouldReceive('forget')->andReturnTrue();

    app()->instance(ColdStartVault::class, $vault);
}

function refusalPressEnroll(): string
{
    $component = Livewire::test(AppLockSettingsSection::class)
        ->set('accountPassword', 'refusal-pass')
        ->call('setPin', '123456', '123456')
        ->assertSet('lockEnabled', true)
        ->call('startEnroll');

    return (string) $component->get('flashMessage');
}

it('blames the platform where a vault is bound and refused', function (): void {
    $this->actingAs(refusalUser('refusal-platform'));
    refusalShield();
    refusalBindRefusingVault();
    refusalInAShell();

    expect(refusalPressEnroll())
        ->toBe(Lang::get('auth::app_lock.error_enroll_device_refused'))
        ->not->toBe(Lang::get('auth::app_lock.error_enroll_unsupported'));
});

it('still blames the build where there is no vault to refuse', function (): void {
    // The control, and the case the old wording was written for: nothing but
    // the null vault is bound, so the build really is the limitation and the
    // reader has nothing to enrol.
    $this->actingAs(refusalUser('refusal-build'));
    refusalShield();
    app()->instance(ColdStartVault::class, new NullColdStartVault);
    refusalInAShell();

    expect(refusalPressEnroll())->toBe(Lang::get('auth::app_lock.error_enroll_unsupported'));
});
