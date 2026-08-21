<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use RuntimeException;

// Distinct from a failed exchange: nothing was attempted, the client id/secret
// has simply never been stored (a fresh install before the wizard runs).
final class OAuthClientNotConfigured extends RuntimeException {}
