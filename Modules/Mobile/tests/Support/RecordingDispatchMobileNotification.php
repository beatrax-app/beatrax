<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Mobile\Internal\Listeners\DispatchMobileNotification;

/**
 * @param  array<int, array{notificationId: string, title: string, body: string, deepLinkRoute: string|null}>  $fired
 */
final class RecordingDispatchMobileNotification extends DispatchMobileNotification
{
    /** @var array<int, array{notificationId: string, title: string, body: string, deepLinkRoute: string|null}> */
    public array $fired = [];

    protected function fire(string $notificationId, string $title, string $body, ?string $deepLinkRoute): void
    {
        $this->fired[] = [
            'notificationId' => $notificationId,
            'title' => $title,
            'body' => $body,
            'deepLinkRoute' => $deepLinkRoute,
        ];
    }
}
