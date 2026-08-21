<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// Who drove a guarded state transition: a person acting in the UI, or an
// automated detector/sweep. Recorded on the transition's history row and
// validated by every alert/series state machine, so it lives in Core where
// all three (Anomaly, DriftAlerts, Recurring) can share one definition.
enum TransitionActor: string
{
    case User = 'user';

    case Detector = 'detector';
}
