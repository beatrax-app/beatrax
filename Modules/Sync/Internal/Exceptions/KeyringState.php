<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

// Which unusable state the keyring is in. NoCurrentEpoch is the one that is not
// a fault: the user has simply never enabled encryption, and a writer may
// proceed in the clear. The others mean the ledger is sealed and this process
// cannot open it, which a writer must be refused rather than told to carry on.
enum KeyringState
{
    case NoCurrentEpoch;

    case MissingKeyForEpoch;

    case CorruptPayload;
}
