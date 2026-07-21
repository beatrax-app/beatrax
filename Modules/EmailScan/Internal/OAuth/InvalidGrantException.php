<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use RuntimeException;

// Sentinel raised when the OAuth provider returns invalid_grant. The
// message carries only the provider's short error description, never
// a token payload. Caught to transition the inbox to needs_reauth.
final class InvalidGrantException extends RuntimeException {}
