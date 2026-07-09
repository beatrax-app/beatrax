<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

/**
 * D-10 atomicity: the pending (staged, not-yet-finalized) epoch-1 keyring
 * file handle returned by {@see GdkKeyringService::stageFirstEpoch()}.
 *
 * Carries only a plain userId + the in-memory {@see GdkEpoch} + a `.tmp`
 * filesystem path — no wrap key, no ambient state. The caller passes this
 * value back to {@see GdkKeyringService::finalizeStagedEpoch()} (on
 * success) or {@see GdkKeyringService::discardStagedEpoch()} (on failure)
 * once the SQL transaction that wrote `sync_encryption_state.current_epoch`
 * alongside this stage has committed or rolled back — never before.
 */
final readonly class GdkKeyringStage
{
    public function __construct(
        public int $userId,
        public GdkEpoch $epoch,
        public string $tmpEncPath,
    ) {}
}
