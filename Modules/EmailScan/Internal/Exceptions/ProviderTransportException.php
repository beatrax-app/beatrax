<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Exceptions;

use RuntimeException;

// The provider was reached but did not answer usefully. Separate from a
// configuration problem because a retry is reasonable: mailbox and grant are
// both fine.
final class ProviderTransportException extends RuntimeException {}
