<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// Four outcomes rather than a bool and a throw: the browser half reads
// `enrolled` and nothing else, so an exception escaping enrolment is a button
// that does nothing instead of a message.
enum BiometricEnrolmentOutcome
{
    case Enrolled;

    case Unshielded;

    case SessionLocked;

    case Failed;
}
