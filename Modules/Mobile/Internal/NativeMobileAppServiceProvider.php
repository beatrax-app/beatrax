<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Notifications\Public\Events\NotificationDeliverable;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class NativeMobileAppServiceProvider
{
    public function __construct(
        private LoggerInterface $logger,
        private Dispatcher $events,
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

    private function triggerBoundedDialOutIfWired(): void
    {
        // Reserved wiring point: a bounded dial-out burst is dispatched from
        // here once wired, so boot() already routes through it and never a
        // background listener that outlives a single request. Intentionally
        // inert today, which is why boot() tolerates it doing nothing.
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
