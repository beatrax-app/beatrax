<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Notifications\Public\Services\SuppressionEvaluator;
use Modules\Position\Internal\Jobs\EmitPositionDigestJob;
use Modules\Position\Public\Events\PositionDigestDue;
use Modules\Position\Public\Services\PositionQuery;

uses(RefreshDatabase::class);

function pdcUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Delivery is suppressed so no case here attempts a real OS notification.
function pdcRunDigest(int $userId, DigestCadence $cadence): void
{
    /** @var SuppressionEvaluator $suppression */
    $suppression = app(SuppressionEvaluator::class);

    $suppression->suppressDelivery(function () use ($userId, $cadence): void {
        $job = new EmitPositionDigestJob($userId, $cadence);
        $job->handle(
            app(Clock::class),
            app(PositionQuery::class),
            app(PeriodQuery::class),
            app(Dispatcher::class),
            app(AuthFactory::class),
        );
    });
}

function pdcDigestCount(int $userId): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('notifications')
        ->where('user_id', $userId)
        ->where('trigger_type', NotificationTrigger::PositionDigest)
        ->count();
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('daily cadence emits exactly one digest row per day across a 7-day span, deduping same-day re-runs', function (): void {
    $user = pdcUser('pdc-daily');

    for ($day = 0; $day < 7; $day++) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 09:00:00')->addDays($day));
        pdcRunDigest((int) $user->id, DigestCadence::Daily);
    }

    // A second run on day 6 still leaves 7 rows: the date occurrence key
    // dedupes it at insert.
    pdcRunDigest((int) $user->id, DigestCadence::Daily);

    expect(pdcDigestCount((int) $user->id))->toBe(7);
});

it('weekly cadence emits exactly one digest row per ISO week across a 14-day span', function (): void {
    $user = pdcUser('pdc-weekly');

    // 2026-05-04 is a Monday: 14 consecutive days from a Monday cover
    // exactly two ISO weeks (Mon 05-04..Sun 05-10, then Mon 05-11..Sun 05-17).
    for ($day = 0; $day < 14; $day++) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-04 09:00:00')->addDays($day));
        pdcRunDigest((int) $user->id, DigestCadence::Weekly);
    }

    expect(pdcDigestCount((int) $user->id))->toBe(2);
});

it('asserts the exact Sunday -> Monday ISO-week boundary produces two rows', function (): void {
    $user = pdcUser('pdc-weekly-boundary');

    // 2026-05-03 is a Sunday (end of an ISO week); 2026-05-04 is the
    // following Monday (start of the next ISO week).
    CarbonImmutable::setTestNow('2026-05-03 09:00:00');
    pdcRunDigest((int) $user->id, DigestCadence::Weekly);

    CarbonImmutable::setTestNow('2026-05-04 09:00:00');
    pdcRunDigest((int) $user->id, DigestCadence::Weekly);

    expect(pdcDigestCount((int) $user->id))->toBe(2);
});

it('off cadence never emits a digest row nor a PositionDigestDue event, across a 7-day span', function (): void {
    $user = pdcUser('pdc-off');

    Event::fake([PositionDigestDue::class]);

    for ($day = 0; $day < 7; $day++) {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-01 09:00:00')->addDays($day));
        pdcRunDigest((int) $user->id, DigestCadence::Off);
    }

    expect(pdcDigestCount((int) $user->id))->toBe(0);
    Event::assertNotDispatched(PositionDigestDue::class);
});

it('gives a user with no notable activity their digest on cadence anyway, with a non-empty body', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = pdcUser('pdc-nothing-notable');

    CarbonImmutable::setTestNow('2026-05-10 09:00:00');
    pdcRunDigest((int) $user->id, DigestCadence::Daily);

    $row = $db->connection()->table('notifications')
        ->where('user_id', $user->id)
        ->where('trigger_type', NotificationTrigger::PositionDigest)
        ->first();

    expect($row)->not->toBeNull();
    expect($row?->body)->not->toBe('');
});

it('one user\'s digest run produces no row for another user (cross-user isolation)', function (): void {
    $userA = pdcUser('pdc-cross-a');
    $userB = pdcUser('pdc-cross-b');

    CarbonImmutable::setTestNow('2026-05-12 09:00:00');
    pdcRunDigest((int) $userA->id, DigestCadence::Daily);

    expect(pdcDigestCount((int) $userA->id))->toBe(1);
    expect(pdcDigestCount((int) $userB->id))->toBe(0);
});
