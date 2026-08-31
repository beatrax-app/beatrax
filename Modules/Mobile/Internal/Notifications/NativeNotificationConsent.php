<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Notifications;

use Modules\Notifications\Public\Contracts\SystemNotificationConsent;
use NativePHP\LocalNotifications\Facades\LocalNotifications;
use Psr\Log\LoggerInterface;
use Throwable;

// The device half of the consent seam. Android 13 and later refuse every
// notification until POST_NOTIFICATIONS is granted, and the only way to be
// granted is to ask; the OS shows its own dialog once and remembers the
// answer, so calling this again after a refusal does nothing.
final readonly class NativeNotificationConsent implements SystemNotificationConsent
{
    public function __construct(private LoggerInterface $log) {}

    public function request(): void
    {
        try {
            LocalNotifications::requestPermission();
        } catch (Throwable $e) {
            // A settings save must not fail because the bridge did: the
            // preferences are already written, and the reader can still grant
            // the permission from the system settings.
            $this->log->warning('Could not ask the device for notification permission.', [
                'exception' => $e::class,
            ]);
        }
    }
}
