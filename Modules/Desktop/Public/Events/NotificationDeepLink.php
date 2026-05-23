<?php

declare(strict_types=1);

namespace Modules\Desktop\Public\Events;

/**
 * Fired when the user clicks a native OS notification — drives the
 * D-14 deep-link behavior: focus the main window and navigate it to
 * the in-app screen the notification points at.
 *
 * `DispatchOsNotification` attaches this event class to every fired
 * notification via NativePHP's `Notification::event(...)->reference(...)
 * ->show()` chain; the `reference` is the in-app route URL the
 * notification surface returns when clicked. NativePHP dispatches
 * THIS event back into the Laravel event bus with the reference as
 * the click payload — a small handler in the Desktop module then
 * navigates the focused window via `Window::current()->url(...)`.
 *
 * Trust boundary (T-15-03): `$screenRoute` is an app-emitted route
 * URL — never user-supplied — so the click handler simply navigates;
 * destination screens already sit behind `auth` middleware so a
 * clicked notification cannot reach another user's data.
 */
final readonly class NotificationDeepLink
{
    public function __construct(
        public string $screenRoute,
    ) {}
}
