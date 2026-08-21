<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Amp\Http\HttpStatus;

// Amp is contained to this namespace, so a client elsewhere in the module reads
// the status it must branch on from here rather than importing the package. The
// values come from Amp itself, which keeps the answering handler and the caller
// that interprets it from drifting apart.
final class PairingHttpStatus
{
    public const int TOO_MANY_REQUESTS = HttpStatus::TOO_MANY_REQUESTS;
}
