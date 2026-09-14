<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Livewire\Livewire;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;

// The app-lock row asks the browser for WebAuthn and tells the server what it
// found. That probe is the browser road's own answer, and in a shell it used to
// overwrite the platform's: a WKWebView exposes window.PublicKeyCredential
// whatever the enclave says, so an iPhone whose vault had already answered
// "none_enrolled" was offered an enrolment it could not complete. Walked on an
// iPhone 12 mini on 2026-09-13; the Galaxy A51 hid it by having no WebAuthn at
// all, which is why the control below matters as much as the case.

function probeOverrideUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('probe-pass'),
        'period_start_day' => 1,
    ]);
}

function probeOverrideVault(bool $available): void
{
    $vault = Mockery::mock(ColdStartVault::class);
    $vault->shouldReceive('isAvailable')->andReturn($available);
    $vault->shouldReceive('isEnrolled')->andReturn(false);
    $vault->shouldReceive('forget')->andReturnTrue();

    app()->instance(ColdStartVault::class, $vault);
}

// Defined here rather than borrowed: Pest loads only the file it is asked to
// run, so a helper taken from a sibling makes this suite fail on its own.
function probeOverrideShield(): void
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

// The row lives behind @if ($lockEnabled), and that property is #[Locked], so
// the lock is turned on by doing it. Without this both cases pass on a page
// that never drew the row at all.
function probeOverrideEnabledSection(): string
{
    return Livewire::test(AppLockSettingsSection::class)
        ->set('accountPassword', 'probe-pass')
        ->call('setPin', '123456', '123456')
        ->assertSet('lockEnabled', true)
        ->html();
}

function probeOverrideShellIs(bool $running): void
{
    app(ConfigRepository::class)->set('nativephp-internal.running', $running);
}

it('does not ship the browser probe where a shell has already answered', function (): void {
    $this->actingAs(probeOverrideUser('probe-shell'));
    probeOverrideShield();
    probeOverrideVault(available: false);
    probeOverrideShellIs(true);

    $html = probeOverrideEnabledSection();

    // The row is drawn and says biometric unlock is not on offer. Asserted
    // because "the probe is absent" is also true of a page that never reached
    // the row, and that is how the first draft of this case passed.
    expect($html)->toContain('cannot offer biometric unlock')
        ->and($html)->not->toContain('window.PublicKeyCredential');
});

it('still ships it where nothing else can answer', function (): void {
    // The control. Without it, "the probe is gone" would also pass on a build
    // that dropped the browser road altogether — which is every self-hosted
    // install, where WebAuthn is the only biometric there is.
    $this->actingAs(probeOverrideUser('probe-browser'));
    probeOverrideShield();
    probeOverrideVault(available: false);
    probeOverrideShellIs(false);

    $html = probeOverrideEnabledSection();

    expect($html)->toContain('window.PublicKeyCredential');
});
