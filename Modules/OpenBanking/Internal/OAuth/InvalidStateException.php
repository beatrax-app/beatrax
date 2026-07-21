<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\OAuth;

use RuntimeException;

/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final class InvalidStateException extends RuntimeException {}
