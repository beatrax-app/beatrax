<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use RuntimeException;

// Every IdentityProviderException that is not invalid_grant. The message
// carries only the provider's short error description, safe to surface as-is.
final class OAuthExchangeFailed extends RuntimeException {}
