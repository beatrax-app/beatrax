<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use RuntimeException;

/**
 * Sentinel raised when the underlying OAuth library throws an
 * IdentityProviderException for any reason that is not invalid_grant
 * (network error, malformed response, scope mismatch, etc.).
 *
 * The message carries the provider's short error description only —
 * never the request body, never any token payload — so callers can
 * safely surface the message to the UI flash.
 */
final class OAuthExchangeFailed extends RuntimeException {}
