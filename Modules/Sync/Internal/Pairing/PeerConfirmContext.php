<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class PeerConfirmContext
{
    // What survives the PAIR_CONFIRM gate sequence: the row the frame was
    // authenticated against, plus the two facts the caller still needs. Its
    // existence IS the assertion that every gate passed — nothing downstream
    // re-derives a side or re-checks a signature.
    public function __construct(
        public \stdClass $row,
        public int $rowId,
        public string $peerConfirmedColumn,
        public ?string $localConfirmedAt,
    ) {}
}
