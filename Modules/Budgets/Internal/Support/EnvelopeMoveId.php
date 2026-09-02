<?php

declare(strict_types=1);

namespace Modules\Budgets\Internal\Support;

use Modules\Budgets\Public\Enums\EnvelopeMoveKind;
use Modules\Core\Public\Support\DerivedRowId;

// The id of one row of a move, computed rather than minted. envelope_moves
// declares no unique index but its primary key, so two devices writing while
// apart both take the next autoincrement and hand it to unrelated moves, and
// nothing afterwards can tell those two rows apart.
/**
 * @link ../../../../.docs/features/budgets/architecture.md
 */
final class EnvelopeMoveId
{
    // The group uuid is minted once by the device making the move and travels
    // with both rows, so the pair is unique wherever the arithmetic is run.
    // period_start is in the tuple because a rekey re-creates the row against
    // a new period, and both devices rekey on their own.
    public static function for(string $moveGroupId, EnvelopeMoveKind $kind, string $periodStart): int
    {
        return DerivedRowId::for('envelope_moves', [
            'move_group_id' => $moveGroupId,
            'kind' => $kind->value,
            'period_start' => $periodStart,
        ]);
    }
}
