<?php

declare(strict_types=1);

namespace Modules\Desktop\Public\Events;

// $screenRoute is always an app-emitted route URL from
// DispatchOsNotification's own UrlGenerator — never user input.
final readonly class NotificationDeepLink
{
    public function __construct(
        public string $screenRoute,
    ) {}
}
