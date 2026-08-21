<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Exceptions;

use RuntimeException;

// Raised rather than updating zero rows and reporting success, which would
// leave a cursor or retry counter looking advanced when nothing moved.
final class ScanStateNotFoundException extends RuntimeException {}
