<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Exceptions;

use RuntimeException;

/**
 * Thrown by SaveTransactionSplit when leg amounts do not sum exactly to the
 * parent transaction's settled_amount_minor (D-04). Enforced app-side inside
 * the DB write transaction — never a DB CHECK constraint, matching the
 * pair_transaction_id precedent.
 *
 * Extends RuntimeException so it is distinct from argument-validation errors.
 */
final class SplitSumMismatchException extends RuntimeException {}
