<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Modules\Auth\Public\Events\AppLockPassphraseChanged;
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
        }
    }
}
