<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Enums;

use Modules\Ledger\Public\Enums\ClearedStatus;

// Every spelling a YNAB4/nYNAB register writes into its Cleared column. YNAB4
// abbreviates, nYNAB writes the word, and both ship reconciled rows — matching
// only 'C' imported every one of those as uncleared.
enum YnabClearedFlag: string
{
    case C = 'C';

    case Cleared = 'CLEARED';

    case R = 'R';

    case Reconciled = 'RECONCILED';

    case U = 'U';

    case Uncleared = 'UNCLEARED';

    public static function statusFor(string $cell): ClearedStatus
    {
        return match (self::tryFrom(mb_strtoupper(trim($cell)))) {
            self::C, self::Cleared => ClearedStatus::Cleared,
            self::R, self::Reconciled => ClearedStatus::Reconciled,
            self::U, self::Uncleared, null => ClearedStatus::Uncleared,
        };
    }
}
