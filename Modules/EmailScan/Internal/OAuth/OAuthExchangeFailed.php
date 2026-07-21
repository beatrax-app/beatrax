<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use RuntimeException;

// Sentinel raised when the OAuth library throws an
// IdentityProviderException for any non-invalid_grant reason (network
// error, malformed response, scope mismatch). The message carries
// only the provider's short error description, safe to surface as-is.
final class OAuthExchangeFailed extends RuntimeException {}
