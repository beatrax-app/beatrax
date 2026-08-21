<?php

declare(strict_types=1);

namespace Modules\Goals\Internal\Enums;

// Where a goal sits against its target and its target date. A different axis
// from GoalStatus, which is the row's lifecycle: an active goal is Reached the
// moment contributions cover the target, long before it is marked completed.
enum GoalProgressState: string
{
    case Reached = 'reached';

    case Overdue = 'overdue';

    case InProgress = 'in_progress';
}
