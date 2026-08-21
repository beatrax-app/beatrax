<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

// Where the enable-encryption modal is: confirm -> progress -> done, dropping
// to error when migrate() threw. A separate vocabulary from the pairing
// wizard's own steps even though both spell one "confirm" — this one gates a
// row migration, that one gates a trust decision.
enum EncryptionSetupStep: string
{
    case Confirm = 'confirm';

    case Progress = 'progress';

    case Done = 'done';

    case Error = 'error';
}
