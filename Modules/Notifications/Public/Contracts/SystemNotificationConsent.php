<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Contracts;

// Android 13 and later drop every notification an app posts until the reader
// has granted POST_NOTIFICATIONS, and the grant only ever comes from a prompt
// the app raises itself. Nothing here decides whether to notify — the
// preferences do that; this only asks the OS to allow what they decided.
interface SystemNotificationConsent
{
    // Safe to call whenever the reader has just asked to be notified: a
    // platform that needs no consent, or has already been answered, does
    // nothing.
    public function request(): void;
}
