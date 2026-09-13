<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// Outcomes rather than a bool and a throw: the browser half reads `enrolled`
// and nothing else, so an exception escaping enrolment is a button that does
// nothing instead of a message.
enum BiometricEnrollmentOutcome
{
    case Enrolled;

    case Unshielded;

    case SessionLocked;

    // No fresh PIN stood behind this ceremony. An unlocked session is what the
    // enrolment used to cost, and the entry it writes outlives the session by
    // design -- so the session cannot also be the proof that it was asked for.
    case PinNotProved;

    case Failed;
}
