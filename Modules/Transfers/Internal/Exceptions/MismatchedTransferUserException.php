<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Exceptions;

use RuntimeException;

// A TransactionImported event whose user does not match the transaction's
// user_id: an integrity violation, so the listener refuses to pair rather
// than cross a user boundary.
final class MismatchedTransferUserException extends RuntimeException {}
