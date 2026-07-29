<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Exceptions;

use RuntimeException;

// The row the operation was going to update is not there. Raised rather than
// updating zero rows and reporting success, which would leave a cursor or a
// retry counter looking advanced when nothing moved.
/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class ScanStateNotFoundException extends RuntimeException {}
