<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Enums;

// What an `envelope_moves` row is. The stored spelling is the only spelling:
// the recent-moves DTO used to translate these two into an 'in'/'out' pair that
// carried the same fact under a third set of words.
enum EnvelopeMoveKind: string
{
    case MoveOut = 'move_out';

    case MoveIn = 'move_in';

    public function isIncoming(): bool
    {
        return $this === self::MoveIn;
    }
}
