<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Mobile\Internal\Listeners\DispatchMobileNotification;

// showRaw() returns null when the native bridge function is absent, and that
// return used to be discarded on purpose, so a notification that was never
// delivered and one that was looked identical from PHP. On a Galaxy the row was
// stored and the in-app list showed it while no OS notification was ever posted.
final class OutcomeRecordingListener extends DispatchMobileNotification
{
    public function record(string $id, mixed $result): void
    {
        $this->recordDeliveryOutcome($id, $result);
    }
}
