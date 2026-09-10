<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Models\User;

// The desktop vault keys its file on the user id alone, so a file left by an
// earlier account is a durable wrap of THAT account's data key sitting under an
// id a later one now holds. The flag is the account's own record that it asked
// for the enrolment, and mount() reads it -- but every Livewire method is
// callable whatever mount() rendered, so the gate has to stand on the action.

function foreignEntryUser(string $username, bool $recorded): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    test()->actingAs($user);

    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, $recorded);

    test()->session([LockStateManager::SESSION_KEY => true]);

    return $user;
}

// Stands in for the file the previous holder of this id left behind: present,
// openable, and holding a key this account has never seen.
function foreignEntryVault(string $dataKey): ColdStartVault
{
    $vault = new class($dataKey) implements ColdStartVault
    {
        public int $prompts = 0;

        public function __construct(private readonly string $dataKey) {}

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
            $this->prompts++;

            return $this->dataKey;
        }

        public function forget(int $userId): bool
        {
            return true;
        }
    };

    app()->instance(ColdStartVault::class, $vault);

    return $vault;
}

it('does not admit a key for an enrolment the account never recorded', function (): void {
    $foreign = random_bytes(32);
    foreignEntryUser('foreign-entry-admits', recorded: false);
    foreignEntryVault($foreign);

    Livewire::test(LockScreen::class)
        ->call('nativeUnlock')
        ->assertNoRedirect();

    expect(session(LockStateManager::SESSION_KEY))->toBeTrue()
        ->and(session(LockStateManager::DATA_KEY_SESSION))->toBeNull();
});

it('does not raise the operating system prompt for an enrolment the account never recorded', function (): void {
    foreignEntryUser('foreign-entry-prompt', recorded: false);
    $vault = foreignEntryVault(random_bytes(32));

    Livewire::test(LockScreen::class)->call('nativeUnlock');

    expect($vault->prompts)->toBe(0);
});

it('still unlocks the account that did record the enrolment', function (): void {
    foreignEntryUser('foreign-entry-own', recorded: true);
    foreignEntryVault(random_bytes(32));

    Livewire::test(LockScreen::class)
        ->call('nativeUnlock')
        ->assertRedirect();

    expect(session(LockStateManager::SESSION_KEY))->toBeFalse();
});
