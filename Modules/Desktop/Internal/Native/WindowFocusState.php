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
 * Default state on construction is `focused` — a fresh launch opens
 * the diederik main window directly in front of the user, so the
 * conservative assumption is that the freshly-launched window has OS
 * focus until proven otherwise by a `WindowBlurred` event. Defaulting
 * the other way (unfocused) would mean every notification fired during
 * the boot-up race would pop an OS-level toast on top of the in-app
 * banner the focused user is already looking at — the duplicate
 * "noise on first launch" failure mode the D-13 focus-gate exists to
 * prevent. The provider's focus/blur subscribers correct the flag as
 * soon as the first NativePHP event arrives.
 */
final class WindowFocusState
{
    private bool $focused = true;

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
