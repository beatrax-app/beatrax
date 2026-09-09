<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Notifications;

// What the platform will allow right now, as opposed to what the reader
// answered when the dialog was raised. null is not false: an install whose
// shell predates the plugin patch cannot be asked at all, and reading that as
// a refusal names a decision nobody made.
interface PlatformNotificationSwitch
{
    public function enabled(): ?bool;
}
