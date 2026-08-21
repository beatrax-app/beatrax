<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use RuntimeException;

// Gmail returned a raw payload that is not valid base64url. Malformed at the
// source rather than in transit, so the fetch is abandoned, not retried.
final class GmailRawDecodeException extends RuntimeException {}
