<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use RuntimeException;

// The Google SDK's Gmail service was built without one of the REST
// resources this client drives (users.messages / users.history). That
// is an SDK-shape invariant breaking, not a mailbox condition, so no
// token refresh or retry can recover it.
/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class GmailResourceUnavailableException extends RuntimeException {}
