<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Exceptions;

use RuntimeException;

// Nothing to authenticate with: no OAuth client registered, or no credentials
// persisted for the inbox. Retrying will not help — someone has to finish the
// wizard or reconnect the mailbox — which is what separates it from an expired
// grant, answered with a 401 the refresh flow already handles.
/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class InboxNotConfiguredException extends RuntimeException {}
