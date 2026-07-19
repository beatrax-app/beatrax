<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\OAuth;

use RuntimeException;

/**
 * Sentinel raised when the Open Banking consent callback's `state`
 * query parameter does not match the per-flow random state issued when
 * the connect step started — mirrors
 * `Modules\EmailScan\Internal\OAuth\InvalidStateException` exactly.
 *
 * Defends against CSRF on the /oauth/callback/open-banking endpoint by
 * requiring the request to carry a state value that was previously
 * stored in the same session under the same authenticated user.
 */
final class InvalidStateException extends RuntimeException {}
