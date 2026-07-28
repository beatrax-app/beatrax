<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use RuntimeException;

// Sentinel raised when a provider's OAuth client id/secret has never been
// stored — the state of a fresh install before the OAuth-client wizard runs.
// Distinct from a failed exchange: nothing was attempted and nothing is wrong
// with the credentials, they simply are not there yet.
final class OAuthClientNotConfigured extends RuntimeException {}
