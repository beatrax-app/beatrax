<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Modules\Auth\Public\Events\AppLockPassphraseChanged;
use Psr\Log\LoggerInterface;

/**
 * D-10: re-wraps the GDK keyring whenever the app-lock passphrase changes.
 *
 * Registered in `SyncServiceProvider::boot()` (single-owner, Plan 02
 * forward-registration) against `Modules\Auth\Public\Events\AppLockPassphraseChanged`.
 * This class only ever consumes an Auth Public event — it never reaches
 * into `Modules\Auth\Internal` — so the module boundary is respected in
 * both directions.
 *
 * Best-effort, never-throw (mirrors `SyncCaptureListener`'s D-07 discipline):
 * `AppLockProvisioner::changePin()` dispatches this event synchronously
 * AFTER the PIN change is already persisted, so a re-wrap failure here must
 * NEVER make `changePin()` throw and break its documented `bool` contract,
 * and must NEVER leave `user_app_lock_configs` half-updated. The GDK
 * keyring and the app-lock PIN wrap are independent concerns: a corrupted
 * or unreadable keyring file is its own (separately recoverable, D-11)
 * failure mode and must not block a legitimate PIN change.
 */
final class RewrapGdkOnPassphraseChange
{
    public function __construct(
        private readonly GdkRewrapContract $rewrap,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(AppLockPassphraseChanged $event): void
    {
        try {
            $this->rewrap->rewrap($event->userId, $event->oldKek, $event->newKek);
        } catch (\Throwable $e) {
            // Swallow — a GDK re-wrap failure must NEVER break the
            // already-committed passphrase change (D-10 best-effort).
            $this->log->error('RewrapGdkOnPassphraseChange: GDK re-wrap failed', [
                'exception' => $e->getMessage(),
                'userId' => $event->userId,
            ]);
        }
    }
}
