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
    ) {}

    public function current(): SystemNotificationGrant
    {
        if (! $this->currentUser->isAuthenticated()) {
            return SystemNotificationGrant::NotApplicable;
        }

        return $this->record->state($this->currentUser->id());
    }
}
