<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Services;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Recurring\Internal\Jobs\EmitPaymentRemindersJob;

// The seam the notification pass reaches this module through. `$leadDays`
// arrives as an int so Recurring stays wholly ignorant of Notifications, which
// is the same reason EmitPaymentRemindersJob takes it rather than reading it.
final readonly class PaymentReminderDispatch
{
    public function __construct(
        private Dispatcher $bus,
    ) {}

    public function forUser(int $userId, int $leadDays): void
    {
        $this->bus->dispatch(new EmitPaymentRemindersJob($userId, $leadDays));
    }

    // In-process, for a caller whose own session holds the app-lock key: a
    // reminder queued from a request would be sealed by a worker that has no
    // session of its own, which is where the last one was refused.
    public function forUserNow(int $userId, int $leadDays): void
    {
        $this->bus->dispatchSync(new EmitPaymentRemindersJob($userId, $leadDays));
    }
}
