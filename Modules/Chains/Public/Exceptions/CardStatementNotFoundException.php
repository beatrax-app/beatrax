<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Exceptions;

use RuntimeException;

// Thrown when the state machine holds a write lock on a card_statements
// row that is absent or belongs to another user. The two cases are
// deliberately indistinguishable to the caller: telling them apart would
// confirm the existence of another user's statement.
final class CardStatementNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly int $statementId,
        public readonly int $userId,
    ) {
        parent::__construct(sprintf(
            'card_statement %d not found for user %d',
            $statementId,
            $userId,
        ));
    }
}
