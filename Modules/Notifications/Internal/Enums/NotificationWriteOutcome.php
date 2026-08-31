<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Enums;

// What actually happened to a draft. `Deferred` is the case a caller could not
// see before: the row was withheld because this process cannot seal it, which
// is indistinguishable from `Duplicate` to anyone counting rows and was
// indistinguishable from a genuine failure to anyone reading the log.
enum NotificationWriteOutcome: string
{
    case Written = 'written';

    case Duplicate = 'duplicate';

    case Deferred = 'deferred';
}
