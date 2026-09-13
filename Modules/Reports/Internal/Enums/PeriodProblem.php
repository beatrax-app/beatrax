<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Enums;

// Which of the four ways a period can be unusable, so the reader is told the one
// that applies rather than a generic "bad dates". Three are about the custom
// range; the fourth is a preset word no rail can produce.
enum PeriodProblem: string
{
    case Incomplete = 'incomplete';

    case Malformed = 'malformed';

    case Inverted = 'inverted';

    case UnknownPreset = 'unknown_preset';
}
