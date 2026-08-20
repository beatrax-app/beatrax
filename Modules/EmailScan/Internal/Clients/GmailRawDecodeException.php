<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use RuntimeException;

// Gmail returned a raw message payload that is not valid base64url, so
// the RFC 822 byte stream cannot be reconstructed. The bytes are
// malformed at the source rather than in transit, so the fetch is
// abandoned rather than retried.
final class GmailRawDecodeException extends RuntimeException {}
