<?php

declare(strict_types=1);

namespace Modules\Sync\Tests;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Internal\Lock\LockStateManager;
use Tests\TestCase as RootTestCase;

/**
 * Sync module-local TestCase. Extends the root TestCase.
 *
 * Phase 12 device-identity tests compose the real AppLockKeyService, whose
 * release() returns the held data key only when the session is unlocked. The
 * default feature-test session is empty (release() → null), which would trip
 * the D-02 weak-key-window guard before any positive-path assertion runs. We
 * therefore prime the session with an unlocked dummy data key so the
 * encrypt/decrypt key-file paths exercise real crypto. Tests that assert the
 * locked / no-KEK behaviour (DeviceIdentityServiceTest's
 * it_throws_without_app_lock_kek) rebind AppLockKeyService to a
 * release()-returns-null subclass, which overrides this priming at make() time.
 */
abstract class TestCase extends RootTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /** @var Session $session */
        $session = $this->app->make(Session::class);
        // 32-byte dummy data key — a valid BackupEncryptor passphrase.
        (new LockStateManager)->unlock($session, str_repeat("\x2a", 32));
    }
}
