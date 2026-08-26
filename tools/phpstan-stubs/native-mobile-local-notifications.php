<?php

/*
 * Signatures for the `nativephp/mobile-local-notifications` facade the Mobile
 * module posts through.
 *
 * Same rationale as the three sibling stubs in this directory: the package is
 * installed ONLY in `mobile-app/vendor` (the sibling-root topology exists
 * because `nativephp/desktop` conflicts with `nativephp/mobile`), so the
 * repo-root toolchain can never autoload it. Without this, every call is a
 * `class.notFound` — and the alternative, excluding the callers from analysis,
 * would leave the notification path unchecked at the one level that catches a
 * wrong argument to it.
 *
 * Declares nothing beyond what this repo calls: `requestPermission()` from
 * NativeNotificationConsent and `showRaw()` from DispatchMobileNotification.
 * Both return `mixed` because the underlying package does. The file lives
 * outside every composer PSR-4 root, so it is never autoloaded at runtime.
 */

namespace NativePHP\LocalNotifications\Facades;

class LocalNotifications
{
    public static function requestPermission(): mixed {}

    /** @param array<string, mixed> $options */
    public static function showRaw(array $options = []): mixed {}
}
