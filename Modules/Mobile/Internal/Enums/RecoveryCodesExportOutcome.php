<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Enums;

// Three outcomes rather than a bool: "there was nothing to save" and "the
// share sheet refused" are different answers to the caller, and the screen
// behind them is the one that shows the codes exactly once.
enum RecoveryCodesExportOutcome
{
    case NoPendingCodes;

    case Shared;

    case NotShared;
}
