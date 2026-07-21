<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../.docs/features/mobile/architecture.md
 */
final class NativeMobileAppServiceProvider
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Dispatcher $events,
    ) {}

    public function boot(): void
    {
        try {
            $this->triggerBoundedDialOutIfWired();
        } catch (Throwable $e) {
            // Non-fatal: a boot-time mobile hook failing must never prevent
            // the app from opening (mirrors the desktop analog's own
            // sync-listener try/catch discipline).
            $this->logger->warning('NativePHP mobile boot: dial-out hook failed non-fatally.', [
                'exception' => $e,
            ]);
        }

        try {
            $this->registerMobileNotificationListenerIfWired();
        } catch (Throwable $e) {
            // Same non-fatal discipline as the dial-out hook above: a
            // failed listener registration must never crash mobile boot.
            $this->logger->warning('NativePHP mobile boot: notification listener wiring failed non-fatally.', [
                'exception' => $e,
            ]);
        }
    }

    // class_exists()-guarded so a dial-out burst can be wired here
    // without this file needing another structural edit - never a
    // background listener that outlives a single request.
    private function triggerBoundedDialOutIfWired(): void
    {
        $triggerService = 'Modules\Mobile\Internal\Sync\MobileSyncTriggerService';
        if (! class_exists($triggerService)) {
            return;
        }
    }

    // Registered only here, never from the shared MobileServiceProvider,
    // because this provider's boot() runs exclusively in the true
    // on-device runtime; the shared provider also loads in non-mobile
    // contexts where this subscription has no business firing.
    private function registerMobileNotificationListenerIfWired(): void
    {
        $listener = 'Modules\Mobile\Internal\Listeners\DispatchMobileNotification';

        if (! class_exists($listener)) {
            return;
        }

        $this->events->listen(NotificationDeliverable::class, [$listener, 'handleNotificationDeliverable']);
    }
}
