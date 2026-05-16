<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use RuntimeException;

/**
 * Sentinel raised when the OAuth callback's state parameter does not
 * match the per-flow random state issued during the authorize step.
 *
 * Mapped to HTTP 400 at the route layer. Defends against CSRF on the
 * /oauth/callback/{provider} endpoint by requiring the request to
 * carry a state value that was previously stored in the same
 * session.
 */
final class InvalidStateException extends RuntimeException {}
