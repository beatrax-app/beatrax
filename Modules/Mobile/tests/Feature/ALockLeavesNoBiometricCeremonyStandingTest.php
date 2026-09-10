<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Actions\LogoutAction;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// The enclave hands the decrypted blob to a transient native slot before PHP
// has decided anything, and the session it is admitted into has no handle on
// it: locking drops the data key the session holds and leaves that one where it
// is. An idle re-lock happens with the app still in the foreground, so the
// native lifecycle edge that would have dropped it never fires either.

function ceremonyCountingVault(): object
{
    $tally = new class
    {
        public int $standDowns = 0;
    };

    app()->bind(BiometricKeyVault::class, fn ($app): BiometricKeyVault => new class($app->make(BiometricKeyBlobCodec::class), $app->make(LoggerInterface::class), $tally) extends BiometricKeyVault
    {
        public function __construct(
            BiometricKeyBlobCodec $codec,
            LoggerInterface $log,
            private readonly object $tally,
        ) {
            parent::__construct($codec, $log);
        }

        public function cancelPrompt(): void
        {
            $this->tally->standDowns++;
        }
    });

    return $tally;
}

it('stands the ceremony down when the key service withholds the key', function (): void {
    $tally = ceremonyCountingVault();

    /** @var Session $session */
    $session = app(Session::class);

    app(AppLockKeyService::class)->withhold($session);

    expect($tally->standDowns)->toBe(1);
});

// Announced from the lock funnel rather than from one caller, so a road to a
// lock that was added later cannot be the one that forgets.
it('stands the ceremony down when the reader signs out', function (): void {
    $user = User::query()->create([
        'username' => 'ceremony-signs-out',
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($user);

    $tally = ceremonyCountingVault();

    app(LogoutAction::class)();

    expect($tally->standDowns)->toBe(1);
});

// The positive control. Both assertions above would read the same on a build
// where the listener was never registered and something else raised the count,
// so this pins that an unlocked session raises nothing at all.
it('stands nothing down while the app is merely unlocked', function (): void {
    $tally = ceremonyCountingVault();

    /** @var Session $session */
    $session = app(Session::class);

    app(AppLockKeyService::class)->admitDataKey($session, str_repeat('k', 32));

    expect($tally->standDowns)->toBe(0);
});
