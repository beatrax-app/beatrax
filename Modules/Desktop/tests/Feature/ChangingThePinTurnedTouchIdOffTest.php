<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Livewire\Livewire;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Events\AppLockPassphraseChanged;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Models\User;

// The enclave/safeStorage blob wraps the DATA KEY, and a PIN change re-wraps
// that same key rather than replacing it. Forgetting on every passphrase event
// deleted a blob that still opened the correct key.

final class RecordingColdStartVault implements ColdStartVault
{
    /** @var list<int> */
    public array $forgotten = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function isEnrolled(int $userId): bool
    {
        return true;
    }

    public function enroll(int $userId, string $dataKey): bool
    {
        return true;
    }

    public function recover(int $userId, string $reason): ?string
    {
        return null;
    }

    public function forget(int $userId): void
    {
        $this->forgotten[] = $userId;
    }
}

function touchIdVaultUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    app(MobileLockGateway::class)->enableAppLock((int) $user->id, '123456', 'account-password', $session);

    return $user;
}

it('keeps the Touch ID enrolment when a PIN change re-wraps the same data key', function (): void {
    touchIdVaultUser('desktop-pin-change-keeps-vault');

    $vault = new RecordingColdStartVault;
    app()->instance(ColdStartVault::class, $vault);

    Livewire::test(AppLockSettingsSection::class)
        ->set('currentPin', '123456')
        ->set('newPin', '654321')
        ->set('confirmPin', '654321')
        ->call('changePin')
        ->assertSet('flashMessage', '');

    expect($vault->forgotten)->toBe([], 'a PIN change rotates nothing, so the enclave blob still unwraps the correct key');
});

it('still forgets the enrolment when the data key genuinely rotates', function (): void {
    $user = touchIdVaultUser('desktop-rotation-forgets-vault');

    $vault = new RecordingColdStartVault;
    app()->instance(ColdStartVault::class, $vault);

    event(new AppLockPassphraseChanged((int) $user->id, str_repeat('a', 32), str_repeat('b', 32)));

    expect($vault->forgotten)->toBe([(int) $user->id]);
});
