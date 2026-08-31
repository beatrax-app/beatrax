<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\EmailScan\Public\Events\IcsStatementReady;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

function isrtUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

// Delivery is globally suppressed so no case here ever reaches a real OS
// notification.
function isrtFire(User $user, CarbonImmutable $internalDate, int $messageId = 1): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($user, $internalDate, $messageId): void {
        app(Dispatcher::class)->dispatch(new IcsStatementReady(
            userId: $user->id,
            messageId: $messageId,
            internalDate: $internalDate,
        ));
    });
}

function isrtCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', NotificationTrigger::IcsStatementReady)
        ->count();
}

it('persists exactly one nudge for a single IcsStatementReady event', function (): void {
    $user = isrtUser('isrt-one-nudge');

    isrtFire($user, CarbonImmutable::parse('2026-07-15'));

    expect(isrtCount($user->id))->toBe(1);
});

it('deep-links to the guided ICS import anchor and carries no transaction data in the body', function (): void {
    $user = isrtUser('isrt-deep-link');

    isrtFire($user, CarbonImmutable::parse('2026-07-15'));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('notifications')
        ->where('user_id', $user->id)
        ->where('trigger_type', NotificationTrigger::IcsStatementReady)
        ->first();

    expect($row)->not->toBeNull();
    $params = json_decode((string) $row->params, true);
    expect($params['target_kind'] ?? null)->toBe('ics-import');

    // The nudge pipeline is metadata-only end to end, so no amount or currency
    // should ever have reached the stored body — nor the copy spec beside it,
    // which now carries every value the body is re-rendered from.
    expect((string) $row->body)->not->toContain('EUR');
    expect((string) $row->body)->not->toContain('€');
    expect((string) $row->params)->not->toContain('EUR');
    expect((string) $row->params)->not->toContain('€');
});

it('dedups a same-DAY re-dispatch but fires a second nudge for a different-day statement in the same month', function (): void {
    $user = isrtUser('isrt-day-dedup');

    isrtFire($user, CarbonImmutable::parse('2026-07-15'), messageId: 1);
    expect(isrtCount($user->id))->toBe(1);

    // A new message id for the same day is a re-scan or a bank-side resend,
    // and still collapses to one row.
    isrtFire($user, CarbonImmutable::parse('2026-07-15'), messageId: 2);
    expect(isrtCount($user->id))->toBe(1);

    // A second statement on a different day of the same month is a genuinely
    // separate nudge; the old Y-m occurrence key swallowed it, Y-m-d keeps it.
    isrtFire($user, CarbonImmutable::parse('2026-07-28'), messageId: 3);
    expect(isrtCount($user->id))->toBe(2);

    isrtFire($user, CarbonImmutable::parse('2026-08-16'), messageId: 4);
    expect(isrtCount($user->id))->toBe(3);
});

it('never leaks one user\'s nudge into another user\'s notifications (cross-user)', function (): void {
    $userA = isrtUser('isrt-cross-a');
    $userB = isrtUser('isrt-cross-b');

    isrtFire($userA, CarbonImmutable::parse('2026-07-15'));

    expect(isrtCount($userA->id))->toBe(1);
    expect(isrtCount($userB->id))->toBe(0);
});
