<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

/**
 * Tracks whether the diederik main window currently has OS focus.
 *
 * `DispatchOsNotification` consults this state to implement the D-13
 * context-aware notification model: an OS notification fires only
 * when the window is unfocused (the user has the app in the
 * background / tray); when the window is focused the in-app
 * `SystemAlertsBanner` handles the event so the user is not
 * double-notified.
 *
 * The state is mutated by event subscribers in
 * `DesktopServiceProvider::boot()` that listen for NativePHP's
 * `WindowFocused` / `WindowBlurred` events and call
 * `markFocused()` / `markBlurred()` respectively.
 *
 * Default state on construction is `unfocused` — a fresh launch (or
 * a freshly resolved singleton in a test) treats the window as
 * background until the first `WindowFocused` event arrives. This is
 * a deliberately conservative default so notifications during the
 * boot-up race are NOT silently dropped if NativePHP's first focus
 * event is briefly delayed.
 */
final class WindowFocusState
{
    private bool $focused = false;

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function markFocused(): void
    {
        $this->focused = true;
    }

    public function markBlurred(): void
    {
        $this->focused = false;
    }
}
