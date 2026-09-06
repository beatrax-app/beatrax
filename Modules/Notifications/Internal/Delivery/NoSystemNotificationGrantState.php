<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Delivery;

use Modules\Notifications\Public\Contracts\SystemNotificationGrantState;
use Modules\Notifications\Public\Enums\SystemNotificationGrant;

// Beside NoSystemNotificationConsent and for the same platforms: nothing was
// asked because nothing gates delivery, so there is no answer to report.
final class NoSystemNotificationGrantState implements SystemNotificationGrantState
{
    public function current(): SystemNotificationGrant
    {
        return SystemNotificationGrant::NotApplicable;
    }
}
