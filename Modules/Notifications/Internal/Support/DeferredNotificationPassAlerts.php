<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\StoredCopy;
use Modules\Notifications\Internal\Enums\DeferredNotificationPass;

// A pass that cannot finish produces no notification, and a notification that
// was never derived is indistinguishable from a quiet week. The log line the
// middleware writes is for whoever goes looking; the reader is not going to.
final readonly class DeferredNotificationPassAlerts
{
    private const string KIND_PREFIX = 'notifications.deferred_pass_failed.';

    public function __construct(
        private SystemAlertWriter $alerts,
        private Clock $clock,
    ) {}

    public function passDidNotComplete(int $userId, DeferredNotificationPass $pass): void
    {
        $line = CopyLine::of('core::alerts.messages.notifications_deferred_pass_failed', [
            'pass' => CopyParam::line('core::alerts.deferred_pass.'.$pass->value),
        ]);

        $this->alerts->raiseOnceForUser(
            userId: $userId,
            kind: self::kind($pass),
            severity: SystemAlertSeverity::Warning->value,
            message: $line->sentence(),
            metadata: StoredCopy::inParams($line),
        );
    }

    // Taken down by the run that succeeds rather than by the reader: the fault
    // it reported is one they can do nothing about, so leaving it standing
    // after the pass recovered only teaches them to dismiss without reading.
    public function passCompleted(int $userId, DeferredNotificationPass $pass): void
    {
        $this->alerts->withdrawForUser($userId, self::kind($pass), $this->clock->now());
    }

    private static function kind(DeferredNotificationPass $pass): string
    {
        return self::KIND_PREFIX.$pass->value;
    }
}
