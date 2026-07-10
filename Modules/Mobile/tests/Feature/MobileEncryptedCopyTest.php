<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;

uses(RefreshDatabase::class);

/*
 * MOBILE-01 — Wave-0 RED contract test (15-VALIDATION.md CRYPT-01 mobile
 * row). Turns GREEN in Plan 04 (15-04), which flesh this file out into a
 * passing feature test exercising SensitiveColumnCodec::encryptAttrs on
 * write + decryptRow on read against the on-device DB path.
 *
 * Behavior pinned: an on-device-written row stores sensitive columns as
 * ciphertext at rest; SensitiveColumnCodec::decryptRow returns plaintext
 * only when a KEK/session is present (release()d); a withheld/locked
 * session still returns ciphertext (T-15-07 — stolen-phone-reads-DB-file
 * mitigation).
 *
 * RED reason: the on-device write path (`Modules\Mobile\Internal\Boot\
 * MobileFirstLaunchBootstrap`, which Plan 04 ships per 15-04-PLAN.md) does
 * not exist yet — this is a clean, collectible FAILING assertion (not a
 * class-not-found error), so the suite still collects and reports 4
 * failing, not errored.
 */
it('stores sensitive columns as ciphertext on-device, decrypting only with an unlocked KEK/session', function (): void {
    $user = User::query()->create([
        'username' => 'mobile-crypto-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    /** @var Session $session */
    $session = app(Session::class);
    (new LockStateManager)->unlock($session, str_repeat('k', 32));

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $keyring->generateAndPersist((int) $user->id, $session);

    $bootstrapClass = 'Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap';

    expect(class_exists($bootstrapClass))->toBeTrue(
        'MobileFirstLaunchBootstrap does not exist yet — the on-device write path lands in Plan 04 (15-04). '
        .'Once it ships, flesh this test out per the Task 3 behavior docblock above.',
    );
});
