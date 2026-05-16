<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use RuntimeException;

/**
 * Sentinel raised when the OAuth provider returns `invalid_grant` on a
 * refresh-token rotation or an authorisation-code exchange.
 *
 * The message carries the provider's short error description only —
 * never the request body, never any token payload — so logging
 * surfaces above this exception can never accidentally leak
 * credential material.
 *
 * The state machine catches this sentinel to transition the affected
 * inbox to needs_reauth; the UI then surfaces the Reconnect button.
 */
final class InvalidGrantException extends RuntimeException {}
