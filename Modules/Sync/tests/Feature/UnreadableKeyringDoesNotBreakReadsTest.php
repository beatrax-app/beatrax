<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

// A key that cannot open the keyring raises BackupDecryptionException, which
// extends RuntimeException — and tryLoadKeyring() caught only LogicException
// where its sibling tryCurrentEpoch() caught both. One wrong key in the session
// then 500'd every screen reading an encrypted column, the dashboard included.

it('renders an unreadable column rather than failing the whole request', function (): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $user = User::query()->create([
        'username' => 'unreadable-keyring',
        'password' => bcrypt('unreadable-keyring-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $keyring->generateAndPersist($user->id, $session);
    $ciphertext = $codec->encryptValue('counterparties', 'display_name', 'ALBERT HEIJN', $user->id, $session);

    // Swap the session's data key for a different one, exactly as a stale
    // enrolment does: structurally valid, and it opens nothing.
    AppLockTestHarness::unlock($session, str_repeat("\x7f", 32));
    $this->app->forgetInstance(GdkKeyringService::class);
    $this->app->forgetInstance(SensitiveColumnCodec::class);

    /** @var SensitiveColumnCodec $fresh */
    $fresh = $this->app->make(SensitiveColumnCodec::class);

    $read = $fresh->decryptValue('counterparties', 'display_name', $ciphertext, $user->id, $session);

    // Blanked, not thrown: the ciphertext guard treats it as unreadable and
    // the page still renders.
    expect($read['decrypted'])->toBeFalse()
        ->and($read['value'])->toBe('');
});
