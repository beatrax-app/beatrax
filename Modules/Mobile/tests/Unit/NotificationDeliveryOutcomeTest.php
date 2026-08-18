<?php

declare(strict_types=1);

use Modules\Core\Public\Contracts\Clock;
use Modules\Mobile\Internal\Listeners\DispatchMobileNotification;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Psr\Log\LoggerInterface;

/*
 * The bridge's answer is the only evidence a notification went anywhere.
 *
 * showRaw() returns null when the native bridge function is absent, and that
 * return used to be discarded on purpose — so a notification that was never
 * delivered and one that was looked identical from PHP. On a Galaxy the row
 * was stored, the in-app list showed it, and no OS notification was ever
 * posted, with nothing in any log to say so.
 */

/** Exposes the protected seam, which is all this test is about. */
final class OutcomeRecordingListener extends DispatchMobileNotification
{
    public function record(string $id, mixed $result): void
    {
        $this->recordDeliveryOutcome($id, $result);
    }
}

function outcomeListener(LoggerInterface $log): OutcomeRecordingListener
{
    return new OutcomeRecordingListener(
        app(SuppressionEvaluator::class),
        app(Clock::class),
        $log,
    );
}

it('warns when the bridge answered nothing, naming the notification', function (): void {
    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'not delivered')
                && $context['notification_id'] === 'notif-1'
                && array_key_exists('bridge_available', $context);
        });

    outcomeListener($log)->record('notif-1', null);
});

it('treats false the same as null, because both mean nothing was posted', function (): void {
    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldReceive('warning')->once();

    outcomeListener($log)->record('notif-2', false);
});

it('records what the bridge answered when it answered something', function (): void {
    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['result'] === '{"ok":true}');

    outcomeListener($log)->record('notif-3', '{"ok":true}');
});

it('does not warn on a non-string success, which is still a delivery', function (): void {
    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldReceive('info')->once()
        ->withArgs(fn (string $message, array $context): bool => $context['result'] === 'boolean');
    $log->shouldNotReceive('warning');

    outcomeListener($log)->record('notif-4', true);
});
