<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Notifications\Public\Contracts\SystemNotificationConsent;

// Counts asks. The real device half calls a bridge function that does not
// exist off a phone, so the seam is where a test can see the prompt happen.
final class RecordingSystemNotificationConsent implements SystemNotificationConsent
{
    public int $asks = 0;

    public function request(): void
    {
        $this->asks++;
    }
}
