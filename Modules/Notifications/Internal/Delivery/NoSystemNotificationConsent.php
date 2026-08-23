<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Delivery;

use Modules\Notifications\Public\Contracts\SystemNotificationConsent;

// The default on web, CI and the desktop shell, none of which gate a
// notification behind a runtime grant.
final class NoSystemNotificationConsent implements SystemNotificationConsent
{
    public function request(): void
    {
        // Nothing to ask. These platforms post what they are given, so there
        // is no grant to hold and no dialog to raise; a caller can invite
        // consent unconditionally and this seam absorbs it.
    }
}
