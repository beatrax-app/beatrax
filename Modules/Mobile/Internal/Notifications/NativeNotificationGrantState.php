<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Notifications;

use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Notifications\Public\Contracts\SystemNotificationGrantState;
use Modules\Notifications\Public\Enums\SystemNotificationGrant;

// The device half of the read seam. A guest has no install-scoped answer to
// report and no notification to be refused, so it reports the state that
// names no platform decision rather than inventing one.
final readonly class NativeNotificationGrantState implements SystemNotificationGrantState
{
    public function __construct(
        private CurrentUser $currentUser,
        private NotificationGrantRecord $record,
        private PlatformNotificationSwitch $platform,
    ) {}

    public function current(): SystemNotificationGrant
    {
        if (! $this->currentUser->isAuthenticated()) {
            return SystemNotificationGrant::NotApplicable;
        }

        $recorded = $this->record->state($this->currentUser->id());

        // Before the dialog has been answered the platform's "off" and "not
        // yet asked" are the same reading, so only a settled answer is read
        // back against the platform. The record stays the history of the
        // dialog; the platform is the truth about the next notification.
        if (! in_array($recorded, [SystemNotificationGrant::Granted, SystemNotificationGrant::Refused], true)) {
            return $recorded;
        }

        return match ($this->platform->enabled()) {
            true => SystemNotificationGrant::Granted,
            false => SystemNotificationGrant::Refused,
            null => $recorded,
        };
    }
}
