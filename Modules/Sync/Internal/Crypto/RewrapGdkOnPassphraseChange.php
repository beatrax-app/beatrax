<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Modules\Auth\Public\Events\AppLockPassphraseChanged;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\StoredCopy;
use Psr\Log\LoggerInterface;

final readonly class RewrapGdkOnPassphraseChange
{
    // Best-effort, never-throw: AppLockProvisioner::changePin() dispatches
    // this event synchronously AFTER the PIN change is already persisted, so
    // a re-wrap failure here must NEVER make changePin() throw or leave
    // user_app_lock_configs half-updated — the keyring is a separate, recoverable concern.
    public function __construct(
        private GdkRewrapContract $rewrap,
        private LoggerInterface $log,
        private SystemAlertWriter $alerts,
    ) {}

    public function handle(AppLockPassphraseChanged $event): void
    {
        try {
            $this->rewrap->rewrap($event->userId, $event->oldKek, $event->newKek);
        } catch (\Throwable $e) {
            // Swallow — a GDK re-wrap failure must NEVER break the
            // already-committed passphrase change.
            $this->log->error('RewrapGdkOnPassphraseChange: GDK re-wrap failed', [
                ...SafeExceptionContext::describe($e),
                'userId' => $event->userId,
            ]);

            // Additive in-app signal: a silent re-wrap failure can leave epoch
            // keys unrecoverable for a single-device user, so surface a critical
            // SystemAlert mirroring PinVerificationService's crypto-desync alert.
            try {
                $line = CopyLine::of('core::alerts.messages.sync_gdk_rewrap_failed');
                $this->alerts->raiseForUser(
                    userId: $event->userId,
                    kind: 'sync.gdk.rewrap_failed',
                    severity: 'critical',
                    message: $line->sentence(),
                    metadata: StoredCopy::inParams($line) + [
                        ...SafeExceptionContext::describe($e),
                        'exception_class' => get_class($e),
                    ],
                );
            } catch (\Throwable) {
                // Last-resort no-op: a SystemAlert write failure (e.g. DB down)
                // must never propagate out of handle() and re-break the
                // already-committed passphrase change.
            }
        }
    }
}
