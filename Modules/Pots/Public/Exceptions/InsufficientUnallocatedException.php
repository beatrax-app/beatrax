<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Exceptions;

use RuntimeException;

/**
 * Thrown by PotWriter when a fund or transfer amount exceeds the account's
 * current unallocated balance (D-03). Prevents over-allocation from user pot
 * actions — negative unallocated can only arise from real-world spending.
 *
 * Extends RuntimeException so it is distinct from argument-validation errors.
 */
final class InsufficientUnallocatedException extends RuntimeException {}
