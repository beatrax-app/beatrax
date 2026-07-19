<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\EmailScan\Public\Events\IcsStatementReady;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;
use Modules\Notifications\Public\Services\SuppressionEvaluator;

/*
 * Req 14 (D-14/D-15) — the ICS "statement ready" nudge listener.
 * `PersistIcsStatementReady` persists a once-per-statement-month
 * notification deep-linking to the guided ICS import
 * (`settings.open-banking#ics-import`), and never carries transaction data
 * (Req 14's "metadata only" guarantee holds all the way through delivery).
 */

function isrtUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

/** Fires IcsStatementReady with delivery globally suppressed (D-43) — no test ever attempts a real OS notification. */
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
        ->where('trigger_type', DeterministicKeyDeriver::TRIGGER_ICS_STATEMENT_READY)
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
        ->where('trigger_type', DeterministicKeyDeriver::TRIGGER_ICS_STATEMENT_READY)
        ->first();

    expect($row)->not->toBeNull();
    $params = json_decode((string) $row->params, true);
    expect($params)->toBe(['target_kind' => 'ics-import']);

    // No amount, currency symbol, or merchant name in the stored copy —
    // the whole pipeline is metadata-only, so there was never any
    // transaction data to leak into the body.
    expect((string) $row->body)->not->toContain('EUR');
    expect((string) $row->body)->not->toContain('€');
});

it('WR-12: dedups a same-DAY re-dispatch but fires a second nudge for a different-day statement in the same month (Pitfall 4)', function (): void {
    $user = isrtUser('isrt-day-dedup');

    isrtFire($user, CarbonImmutable::parse('2026-07-15'), messageId: 1);
    expect(isrtCount($user->id))->toBe(1);

    // Same DAY, a different message id (e.g. the detector re-scanning the
    // same row on the next hourly tick, or a bank-side resend) — must still
    // collapse to one row (same-day idempotency holds).
    isrtFire($user, CarbonImmutable::parse('2026-07-15'), messageId: 2);
    expect(isrtCount($user->id))->toBe(1);

    // WR-12: a SECOND distinct statement arriving on a DIFFERENT day in the
    // SAME calendar month is a genuinely separate nudge — the old Y-m key
    // wrongly swallowed it; the Y-m-d key keeps it.
    isrtFire($user, CarbonImmutable::parse('2026-07-28'), messageId: 3);
    expect(isrtCount($user->id))->toBe(2);

    // Next month's statement legitimately fires a third, distinct nudge.
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
