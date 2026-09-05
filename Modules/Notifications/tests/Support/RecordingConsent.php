<?php

declare(strict_types=1);

namespace Modules\Notifications\Tests\Support;

use Modules\Notifications\Public\Contracts\SystemNotificationConsent;

final class RecordingConsent implements SystemNotificationConsent
{
    public int $requests = 0;

    public function request(): void
    {
        $this->requests++;
    }
}
