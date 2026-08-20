<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Exceptions;

use RuntimeException;

// The provider was reached but did not answer usefully: a transport error, a
// non-2xx the client could not map to something more specific, or a body that
// would not decode. Separate from a configuration problem because retrying is
// reasonable — the mailbox and the grant are both fine.
final class ProviderTransportException extends RuntimeException {}
