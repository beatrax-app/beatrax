<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Mobile\Internal\Notifications\NotificationGrantRecord;
use Modules\Mobile\Tests\Support\OutcomeRecordingListener;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

function outcomeListener(LoggerInterface $log): OutcomeRecordingListener
{
    return new OutcomeRecordingListener(
        app(SuppressionEvaluator::class),
        app(Clock::class),
        $log,
        app(NotificationGrantRecord::class),
    );
}

function outcomeReader(string $username): int
{
    return (int) User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ])->id;
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

    outcomeListener($log)->record('notif-1', null, outcomeReader('outcome-nothing'));
});

it('treats false the same as null, because both mean nothing was posted', function (): void {
    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldReceive('warning')->once();

    outcomeListener($log)->record('notif-2', false, outcomeReader('outcome-false'));
});

it('records what the bridge answered when it answered something', function (): void {
    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['result'] === '{"ok":true}');

    outcomeListener($log)->record('notif-3', '{"ok":true}', outcomeReader('outcome-string'));
});

it('does not warn on a non-string success, which is still a delivery', function (): void {
    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldReceive('info')->once()
        ->withArgs(fn (string $message, array $context): bool => $context['result'] === 'boolean');
    $log->shouldNotReceive('warning');

    outcomeListener($log)->record('notif-4', true, outcomeReader('outcome-boolean'));
});

it('will not call a hand-off a delivery on a device that declined the prompt', function (): void {
    $userId = outcomeReader('outcome-refused');
    app(NotificationGrantRecord::class)->recordAnswer($userId, false);

    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldNotReceive('info');
    $log->shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'refuses them')
            && $context['notification_id'] === 'notif-5');

    outcomeListener($log)->record('notif-5', '{"ok":true}', $userId);
});

it('still reports a hand-off while the answer is outstanding, which is not a refusal', function (): void {
    $userId = outcomeReader('outcome-awaiting');
    app(NotificationGrantRecord::class)->markAsked($userId);

    $log = Mockery::mock(LoggerInterface::class);
    $log->shouldNotReceive('warning');
    $log->shouldReceive('info')->once();

    outcomeListener($log)->record('notif-6', '{"ok":true}', $userId);
});
