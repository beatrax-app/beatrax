<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\StateMachines;

use RuntimeException;

// Thrown inside NotificationStateMachine::resolve when the lockForUpdate
// lookup finds no notifications row for the given id and user — the row
// was deleted (or never existed for this user) between the caller's read
// and the state transition.
final class NotificationNotFoundException extends RuntimeException {}
