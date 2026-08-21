<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

final readonly class GdkKeyringStage
{
    // The pending (staged, not-yet-finalized) epoch-1 keyring file handle
    // from GdkKeyringService::stageFirstEpoch(). Pass back to
    // finalizeStagedEpoch()/discardStagedEpoch() only once the SQL
    // transaction that wrote current_epoch alongside this stage resolves.
    public function __construct(
        public int $userId,
        public GdkEpoch $epoch,
        public string $tmpEncPath,
        public string $blindIndexKeyHex,
    ) {}
}
