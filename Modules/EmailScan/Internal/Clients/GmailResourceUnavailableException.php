<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use RuntimeException;

// The Google SDK's Gmail service was built without users.messages or
// users.history. An SDK-shape break, so no refresh or retry recovers it.
final class GmailResourceUnavailableException extends RuntimeException {}
