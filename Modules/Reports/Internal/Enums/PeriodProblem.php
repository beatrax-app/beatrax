<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Enums;

// Which of the three ways a custom range can be unusable, so the reader is told
// the one that applies rather than a generic "bad dates".
enum PeriodProblem: string
{
    case Incomplete = 'incomplete';

    case Malformed = 'malformed';

    case Inverted = 'inverted';
}
