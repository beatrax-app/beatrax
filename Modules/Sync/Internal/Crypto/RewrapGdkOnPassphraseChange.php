<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Modules\Auth\Public\Events\AppLockPassphraseChanged;
use Modules\Core\Models\SystemAlert;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class RewrapGdkOnPassphraseChange
{
    // Best-effort, never-throw: AppLockProvisioner::changePin() dispatches
    // this event synchronously AFTER the PIN change is already persisted, so
    // a re-wrap failure here must NEVER make changePin() throw or leave
    // user_app_lock_configs half-updated — the keyring is a separate, recoverable concern.
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
            // already-committed passphrase change.
            $this->log->error('RewrapGdkOnPassphraseChange: GDK re-wrap failed', [
                'exception' => $e->getMessage(),
                'userId' => $event->userId,
            ]);

            // Additive in-app signal: a silent re-wrap failure can leave epoch
            // keys unrecoverable for a single-device user, so surface a critical
            // SystemAlert mirroring PinVerificationService's crypto-desync alert.
            try {
                SystemAlert::create([
                    'user_id' => $event->userId,
                    'kind' => 'sync.gdk.rewrap_failed',
                    'severity' => 'critical',
                    'message' => 'GDK keyring re-wrap failed after an app-lock passphrase change — encrypted data may be unrecoverable until the keyring is re-wrapped.',
                    'metadata' => [
                        'exception' => $e->getMessage(),
                        'exception_class' => get_class($e),
                    ],
                ]);
            } catch (\Throwable) {
                // Last-resort no-op: a SystemAlert write failure (e.g. DB down)
                // must never propagate out of handle() and re-break the
                // already-committed passphrase change.
            }
        }
    }
}
