<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Exceptions;

use RuntimeException;

// Nothing to authenticate with: no OAuth client registered, or no credentials
// persisted. Unlike an expired grant, no retry helps — someone has to finish
// the wizard or reconnect the mailbox.
final class InboxNotConfiguredException extends RuntimeException {}
