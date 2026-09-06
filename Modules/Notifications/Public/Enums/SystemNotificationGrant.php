<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Enums;

// What the operating system has said about showing this app's notifications,
// which is a separate question from whether the reader wants them: the
// preferences decide what is worth notifying about, and this decides whether
// anything the platform is handed can appear at all.
enum SystemNotificationGrant: string
{
    // The platform posts what it is given. Web, CI and the desktop shell.
    case NotApplicable = 'not_applicable';

    // The prompt has never been raised on this install, so the OS is
    // refusing every notification and nothing has asked it not to.
    case NeverAsked = 'never_asked';

    // Asked, no answer recorded. The reader may still be looking at the
    // dialog. Deliberately not read as a refusal: the next ask returns the
    // settled answer without showing anything, so this state recovers.
    case Awaiting = 'awaiting';

    case Granted = 'granted';

    case Refused = 'refused';

    // The one case a delivery record may name as the reason a notification
    // went nowhere. Awaiting is unknown, not negative.
    public function stopsDelivery(): bool
    {
        return $this === self::Refused;
    }
}
