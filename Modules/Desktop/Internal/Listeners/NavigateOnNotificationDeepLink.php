<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Public\Events\NotificationDeepLink;
use Native\Desktop\Facades\Window;

// $screenRoute is always an app-emitted URL (never user input) — see
// DispatchOsNotification, the sole producer. Destination screens still
// sit behind auth, so a click can never reach another user's data.
final class NavigateOnNotificationDeepLink
{
    public function handle(NotificationDeepLink $event): void
    {
        Window::current()->url($event->screenRoute);
    }
}
