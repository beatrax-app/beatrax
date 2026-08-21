<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Exceptions;

use RuntimeException;

// Absent row and other-user row are deliberately indistinguishable:
// telling them apart would confirm another user's statement exists.
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
