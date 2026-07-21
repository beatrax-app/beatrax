<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use RuntimeException;

// Sentinel raised when the OAuth callback's state parameter doesn't
// match the per-flow random state issued at authorize time. Mapped to
// HTTP 400 — the CSRF defence for /oauth/callback/{provider}.
final class InvalidStateException extends RuntimeException {}
