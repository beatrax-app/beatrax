<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Mobile\Internal\Listeners\DispatchMobileNotification;

/**
 * Reaches recordDeliveryOutcome() with an answer the bridge could really give.
 * The shell is the only thing that produces one, so the alternative is a case
 * that can only run on a device.
 */
final class ExposedDispatchMobileNotification extends DispatchMobileNotification
{
    public function recordOutcome(string $notificationId, mixed $result, int $userId): void
    {
        $this->recordDeliveryOutcome($notificationId, $result, $userId);
    }
}
