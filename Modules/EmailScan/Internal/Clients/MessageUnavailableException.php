<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use RuntimeException;

// The provider no longer has the message the cursor named — deleted, or moved
// out of reach between the history read and the fetch. Permanent for that id,
// so the scan skips it rather than stalling the cursor behind it.
final class MessageUnavailableException extends RuntimeException {}
