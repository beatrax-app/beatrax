<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

// The auto-lock windows the app lock offers and the one a fresh config starts
// on. Shared by the settings validator, the mount-time fallback, the select
// that renders them and the column default, so the allow-list has one
// definition instead of five.
final class IdleTimeoutOptions
{
    /** @var array<int, string> window in minutes => the label key it renders under */
    public const array LABEL_KEYS = [
        1 => 'auth::app_lock.idle_1',
        5 => 'auth::app_lock.idle_5',
        15 => 'auth::app_lock.idle_15',
        30 => 'auth::app_lock.idle_30',
    ];

    public const int DEFAULT_MINUTES = 5;

    // Leaving the foreground is a second lock condition on its own fixed
    // window, which no setting above changes. Three places need the same
    // number -- the copy that discloses it, this marker, and lock.js's timer
    // -- and the marker is the only one an Android WebView cannot suspend.
    public const int BACKGROUND_GRACE_SECONDS = 30;

    /**
     * @return list<int>
     */
    public static function minutes(): array
    {
        return array_keys(self::LABEL_KEYS);
    }
}
