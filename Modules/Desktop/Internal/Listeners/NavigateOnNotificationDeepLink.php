<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Internal\Native\AppWindow;
use Modules\Desktop\Public\Events\NotificationDeepLink;
use Native\Desktop\Facades\Window;

// $screenRoute is always an app-emitted URL, never user input — DispatchOsNotification
// is the sole producer, and every destination still sits behind auth.
final class NavigateOnNotificationDeepLink
{
    public function handle(NotificationDeepLink $event): void
    {
        // Addressed by id, never by focus: the notification exists only because
        // the window was not focused when it fired, and it may since have been
        // hidden to the tray. open() on an id already in the shell's window
        // state shows and focuses it, which is what the click asked for.
        Window::get(AppWindow::ID)->url($event->screenRoute);
        Window::open(AppWindow::ID);
    }
}
