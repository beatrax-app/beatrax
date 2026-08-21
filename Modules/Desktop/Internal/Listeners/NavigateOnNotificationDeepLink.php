<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Public\Events\NotificationDeepLink;
use Native\Desktop\Facades\Window;

// $screenRoute is always an app-emitted URL, never user input — DispatchOsNotification
// is the sole producer, and every destination still sits behind auth.
final class NavigateOnNotificationDeepLink
{
    public function handle(NotificationDeepLink $event): void
    {
        Window::current()->url($event->screenRoute);
    }
}
