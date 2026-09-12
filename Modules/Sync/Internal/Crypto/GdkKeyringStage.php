<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

final readonly class GdkKeyringStage
{
    // A staged, not-yet-finalized keyring file handle from
    // GdkKeyringService::stageFirstEpoch() or stageAppendedEpoch(). Pass back
    // to finalizeStagedEpoch()/discardStagedEpoch() only once the SQL
    // transaction that wrote current_epoch alongside this stage resolves.

    // The blind-index key is null for an appended epoch: it is minted once
    // beside the FIRST epoch and never rotated, so a rotation stage carries
    // whatever the keyring already held, including nothing.
    public function __construct(
        public int $userId,
        public GdkEpoch $epoch,
        public string $tmpEncPath,
        public ?string $blindIndexKeyHex,
    ) {}
}
