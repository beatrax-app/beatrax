<?php

declare(strict_types=1);

namespace Modules\Sync\Tests;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Tests\TestCase as RootTestCase;

// The default feature-test session is empty, so AppLockKeyService::release()
// hands back null and the weak-key-window guard trips before any positive-path
// assertion runs. Priming an unlocked dummy data key keeps the real crypto in
// play; a test asserting the locked path rebinds the service to override it.
abstract class TestCase extends RootTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /** @var Session $session */
        $session = $this->app->make(Session::class);
        // 32-byte dummy data key — a valid BackupEncryptor passphrase.
        AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    }
}
