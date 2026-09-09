<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\ColdStartBiometricSettingsSection;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// The enclave is unreachable in the repo toolchain, so it is faked here and only
// the orchestration and component wiring are exercised.
function settingsVault(bool $available = true, bool $clears = true): BiometricKeyVault
{
    return new class($available, $clears, app(BiometricKeyBlobCodec::class), app(LoggerInterface::class)) extends BiometricKeyVault
    {
        public function __construct(
            private readonly bool $avail,
            private readonly bool $clears,
            BiometricKeyBlobCodec $codec,
            LoggerInterface $log,
        ) {
            parent::__construct($codec, $log);
        }

        protected function runtimeAvailable(): bool
        {
            return $this->avail;
        }

        // Without pinning it, this half of isAvailable() falls to the real
        // bridge, which no repo toolchain can reach, so every availability
        // assertion here would fail for the absence of a phone.
        /**
         * @return array{available?: bool, reason?: string}
         */
        protected function vaultCapability(): array
        {
            return ['available' => true, 'reason' => 'available'];
        }

        public function enroll(int $userId, string $dataKey): bool
        {
            return true;
        }

        public function clear(int $userId): bool
        {
            return $this->clears;
        }
    };
}

function mobileColdStartSettingsUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');

    return $user;
}

it('shows the toggle when the vault is available and reflects not-enrolled', function (): void {
    mobileColdStartSettingsUser('settings-available');
    app()->bind(BiometricKeyVault::class, fn () => settingsVault(available: true));

    Livewire::test(ColdStartBiometricSettingsSection::class)
        ->assertSet('available', true)
        ->assertSet('enrolled', false);
});

it('renders the empty-state (no toggle) when the vault is unavailable', function (): void {
    mobileColdStartSettingsUser('settings-unavailable');
    app()->bind(BiometricKeyVault::class, fn () => settingsVault(available: false));

    Livewire::test(ColdStartBiometricSettingsSection::class)
        ->assertSet('available', false)
        ->assertSee('only available in the Beatrax mobile app');
});

it('enables with the correct PIN', function (): void {
    mobileColdStartSettingsUser('settings-enable');
    app()->bind(BiometricKeyVault::class, fn () => settingsVault(available: true));

    Livewire::test(ColdStartBiometricSettingsSection::class)
        ->set('pin', '123456')
        ->call('enable')
        ->assertSet('enrolled', true)
        ->assertSet('pin', '');
});

it('does not enable on a wrong PIN and surfaces a message', function (): void {
    mobileColdStartSettingsUser('settings-enable-wrong');
    app()->bind(BiometricKeyVault::class, fn () => settingsVault(available: true));

    Livewire::test(ColdStartBiometricSettingsSection::class)
        ->set('pin', '000000')
        ->call('enable')
        ->assertSet('enrolled', false)
        ->assertSet('flashMessage', 'Could not enable biometric unlock — check your PIN and try again.');
});

it('shows the format message on an empty PIN (distinct from a wrong PIN)', function (): void {
    mobileColdStartSettingsUser('settings-empty-pin');
    app()->bind(BiometricKeyVault::class, fn () => settingsVault(available: true));

    Livewire::test(ColdStartBiometricSettingsSection::class)
        ->set('pin', '')
        ->call('enable')
        ->assertSet('enrolled', false)
        ->assertSet('flashMessage', 'Enter your PIN (6–10 digits) to enable biometric unlock.');
});

it('disables an active enrollment', function (): void {
    $user = mobileColdStartSettingsUser('settings-disable');
    app()->bind(BiometricKeyVault::class, fn () => settingsVault(available: true));
    app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);

    Livewire::test(ColdStartBiometricSettingsSection::class)
        ->assertSet('enrolled', true)
        ->call('disable')
        ->assertSet('enrolled', false)
        ->assertSet('flashMessage', '');
});

// Off is off either way — the reader asked for that, and an enrolment left on
// keeps offering an unlock they declined. Saying nothing about the key the
// enclave kept is what tells them it was destroyed.
it('turns the enrollment off and still says the enclave kept the key', function (): void {
    $user = mobileColdStartSettingsUser('settings-disable-refused');
    app()->bind(BiometricKeyVault::class, fn () => settingsVault(available: true, clears: false));
    app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);

    Livewire::test(ColdStartBiometricSettingsSection::class)
        ->assertSet('enrolled', true)
        ->call('disable')
        ->assertSet('enrolled', false)
        ->assertSet('flashMessage', Lang::get('mobile::biometric.clear_refused'));
});
