<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Http\Livewire\MobileLockScreen;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\BiometricRecoverResult;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// Enrolling a second fingerprint destroys the Keystore key the cold-start blob
// was wrapped under, and the next read answers `missing` inline without ever
// raising a prompt. Every input the screen consulted to decide whether to offer
// the control — a database flag, a hardware probe — reads exactly as it did the
// day the enrolment worked. Read off a Galaxy A51: BiometricVault.Get completed
// successfully, no sheet, no message, no state change.

function unansweredVaultUser(string $username): User
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
    app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);

    DB::table('user_app_lock_configs')->where('user_id', $user->id)
        ->update(['last_pin_unlock_at' => CarbonImmutable::now()->toDateTimeString()]);

    test()->session([AppLockTestHarness::LOCKED_SESSION_KEY => true]);

    return $user;
}

// The enclave is unreachable from the repo toolchain, so both halves of
// isAvailable() and the recovery outcome are dictated here.
function unansweredVaultAnswering(BiometricRecoverResult $result): void
{
    app()->bind(BiometricKeyVault::class, fn ($app) => new class($app->make(BiometricKeyBlobCodec::class), $app->make(LoggerInterface::class), $result) extends BiometricKeyVault
    {
        public function __construct(
            BiometricKeyBlobCodec $codec,
            LoggerInterface $log,
            private readonly BiometricRecoverResult $result,
        ) {
            parent::__construct($codec, $log);
        }

        protected function runtimeAvailable(): bool
        {
            return true;
        }

        /**
         * @return array{available?: bool, reason?: string}
         */
        protected function vaultCapability(): array
        {
            return ['available' => true, 'reason' => 'available'];
        }

        public function recover(int $userId, string $reason = 'Unlock Beatrax'): BiometricRecoverResult
        {
            return $this->result;
        }
    });
}

function unansweredVaultReleased(): ?string
{
    /** @var Session $session */
    $session = app(Session::class);

    return app(AppLockKeyService::class)->release($session);
}

it('offers the tap while the flag and the probe both say it can work', function (): void {
    unansweredVaultUser('vault-offers');
    unansweredVaultAnswering(BiometricRecoverResult::missing());

    Livewire::test(MobileLockScreen::class)->assertSet('biometricAvailable', true);
});

// What makes the offer honest at mount rather than at the tap: the screen asks
// the enclave for itself as it renders, and the one refusal that means the entry
// has gone comes back inline, with no sheet and nothing for the reader to answer.
it('asks the vault for itself as the screen mounts', function (): void {
    unansweredVaultUser('vault-asks-at-mount');
    unansweredVaultAnswering(BiometricRecoverResult::missing());

    Livewire::test(MobileLockScreen::class)->assertSeeHtml('x-init="$wire.biometricPrompt()"');
});

it('tells the reader when the vault can no longer read an entry', function (): void {
    unansweredVaultUser('vault-says-missing');
    unansweredVaultAnswering(BiometricRecoverResult::missing());

    Livewire::test(MobileLockScreen::class)
        ->call('biometricPrompt')
        ->assertNoRedirect()
        ->assertSet('flashMessage', Lang::get('mobile::lock.errors.biometric_reset'));

    expect(unansweredVaultReleased())->toBeNull();
});

it('takes the control down in the same response rather than leaving it to be tapped again', function (): void {
    unansweredVaultUser('vault-stands-down');
    unansweredVaultAnswering(BiometricRecoverResult::missing());

    Livewire::test(MobileLockScreen::class)
        ->call('biometricPrompt')
        ->assertSet('biometricAvailable', false);
});

it('records the enrolment as gone, so the settings control can arm it again', function (): void {
    $user = unansweredVaultUser('vault-clears-flag');
    unansweredVaultAnswering(BiometricRecoverResult::missing());

    Livewire::test(MobileLockScreen::class)->call('biometricPrompt');

    expect(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeFalse();
});

it('does not offer the tap on the next mount once the enrolment is recorded as gone', function (): void {
    unansweredVaultUser('vault-next-mount');
    unansweredVaultAnswering(BiometricRecoverResult::missing());

    Livewire::test(MobileLockScreen::class)->call('biometricPrompt');

    Livewire::test(MobileLockScreen::class)->assertSet('biometricAvailable', false);
});

// A reader who dismissed the sheet is still enrolled, and the enclave will open
// for the next tap. Reading that as a lost enrolment would throw away a working
// one every time somebody changed their mind.

it('leaves a refusal that is not about the entry enrolled, silent and still offered', function (BiometricRecoverResult $refusal): void {
    $user = unansweredVaultUser('vault-keeps-'.$refusal->status);
    unansweredVaultAnswering($refusal);

    Livewire::test(MobileLockScreen::class)
        ->call('biometricPrompt')
        ->assertSet('flashMessage', '')
        ->assertSet('biometricAvailable', true);

    expect(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeTrue();
})->with([
    'the reader dismissed the sheet' => [fn (): BiometricRecoverResult => BiometricRecoverResult::canceled()],
    'the authentication did not succeed' => [fn (): BiometricRecoverResult => BiometricRecoverResult::failed()],
    'the prompt is still running' => [fn (): BiometricRecoverResult => BiometricRecoverResult::pendingAsync()],
]);
