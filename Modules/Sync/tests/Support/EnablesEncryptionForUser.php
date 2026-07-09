<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Sync\Internal\Crypto\GdkKeyringService;

/**
 * Decrypt-of-plaintext is a documented `SensitiveColumnCodec` no-op, so a
 * regression test proves nothing unless the fixture user has encryption
 * actually turned on before the path under test runs — a broken
 * predicate/read passes silently against a still-plaintext column. This
 * trait is the single place every 14.1 regression test primes the KEK +
 * epoch-1 keyring, extracted from the previously-inlined fixture in
 * `Modules/Ledger/tests/Feature/RecordTransactionsEncryptionTest.php`.
 *
 * @link ../../../../.docs/adr/0011-code-comment-policy.md
 */
trait EnablesEncryptionForUser
{
    /** 32-byte dummy data key — a valid BackupEncryptor passphrase, never a real KEK. */
    private const TEST_KEK = '********************************';

    protected function enablesEncryptionForUser(User $user): Session
    {
        /** @var Session $session */
        $session = $this->app->make(Session::class);
        (new LockStateManager)->unlock($session, self::TEST_KEK);

        /** @var GdkKeyringService $keyring */
        $keyring = $this->app->make(GdkKeyringService::class);
        $keyring->generateAndPersist((int) $user->id, $session);

        return $session;
    }
}
