<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

/**
 * Thrown by the `CurrentUser` contract when the caller asks for the current
 * user but no user is bound to the active guard.
 */
final class NotAuthenticatedException extends RuntimeException {}
