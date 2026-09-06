<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Contracts;

use Modules\Notifications\Public\Enums\SystemNotificationGrant;

// The read half of the consent seam. SystemNotificationConsent asks; this
// says what came back, so a settings screen can tell a reader their device is
// dropping what the app decided to send, and a delivery record can stop
// reporting a hand-off to the platform as an arrival.
interface SystemNotificationGrantState
{
    public function current(): SystemNotificationGrant;
}
