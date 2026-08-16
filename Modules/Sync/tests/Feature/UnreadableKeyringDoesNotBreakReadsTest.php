<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

/*
 * A held key that cannot open the keyring file raises
 * BackupDecryptionException, which extends RuntimeException — and
 * tryLoadKeyring() caught only LogicException, while its sibling
 * tryCurrentEpoch() already caught both. So one wrong key in the session took
 * down every screen that reads an encrypted column, including the dashboard,
 * with a raw 500 and no route back to the lock screen that would replace it.
 *
 * Reached in the wild by a biometric unlock recovering a key enrolled against
 * a different keyring; unreadable is precisely the case this method absorbs.
 */

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
    (new LockStateManager)->unlock($session, str_repeat("\x7f", 32));
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
