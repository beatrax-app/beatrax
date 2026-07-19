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

it('does not re-fire for the same statement month (dedup) but DOES fire again next month (Pitfall 4)', function (): void {
    $user = isrtUser('isrt-month-dedup');

    isrtFire($user, CarbonImmutable::parse('2026-07-15'), messageId: 1);
    expect(isrtCount($user->id))->toBe(1);

    // Same month, a different message id (e.g. the detector re-scanning
    // the same row on the next hourly tick, or a bank-side resend) — must
    // still collapse to one row.
    isrtFire($user, CarbonImmutable::parse('2026-07-28'), messageId: 2);
    expect(isrtCount($user->id))->toBe(1);

    // Next month's statement legitimately fires a second, distinct nudge.
    isrtFire($user, CarbonImmutable::parse('2026-08-16'), messageId: 3);
    expect(isrtCount($user->id))->toBe(2);
});

it('never leaks one user\'s nudge into another user\'s notifications (cross-user)', function (): void {
    $userA = isrtUser('isrt-cross-a');
    $userB = isrtUser('isrt-cross-b');

    isrtFire($userA, CarbonImmutable::parse('2026-07-15'));

    expect(isrtCount($userA->id))->toBe(1);
    expect(isrtCount($userB->id))->toBe(0);
});
