<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Exceptions;

use RuntimeException;

// Enforced app-side inside the DB write transaction — never a DB CHECK
// constraint — and kept distinct from argument-validation errors.
final class SplitSumMismatchException extends RuntimeException {}
