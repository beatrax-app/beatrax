<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Sync\Internal\Crypto\GdkKeyringService;

// Decrypting plaintext is a documented no-op, so a test run against a
// still-plaintext user would pass even with a broken read path.
trait EnablesEncryptionForUser
{
    // 32 bytes of dummy key material: a valid BackupEncryptor passphrase, never a real KEK.
    private const TEST_KEK = '********************************';

    protected function enablesEncryptionForUser(User $user): Session
    {
        /** @var Session $session */
        $session = $this->app->make(Session::class);
        AppLockTestHarness::unlock($session, self::TEST_KEK);

        /** @var GdkKeyringService $keyring */
        $keyring = $this->app->make(GdkKeyringService::class);
        $keyring->generateAndPersist((int) $user->id, $session);

        return $session;
    }
}
