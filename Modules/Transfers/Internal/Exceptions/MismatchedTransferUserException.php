<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Exceptions;

use RuntimeException;

// Thrown by the PairTransferCandidates listener when a TransactionImported
// event carries a user whose id does not match the transaction's user_id —
// an integrity violation, so the listener refuses to pair rather than
// cross a user boundary.
final class MismatchedTransferUserException extends RuntimeException {}
