<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\DriftAlerts\Public\Services\SavingsInsightsQuery;
use Modules\Sync\Public\Events\EntityMutated;

// The card is on the dashboard and on the savings page, so two taps on one
// suggestion are ordinary. Both asked whether the dismissal was already held,
// both got "no", and both announced a create for the single row one of them
// wrote — an op naming a row the announcing device never inserted. The count
// insert-or-ignore returns answers the same question under the same lock.

function ctaDismissUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'cta-dismiss-'.$suffix.'-'.bin2hex(random_bytes(3)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

// The other tab, committed at the instant the pre-read has just said "not
// held".
//
// Armed for the call under test and disarmed again: dismiss() no longer reads
// before it writes, so a listener left live would first fire on the assertions
// below and stand in for a race nobody ran. insert-or-ignore as well as the
// arming, because the capture path behind the event reads rows back — a fire
// inside the window but after the write must land on the row this call wrote
// and be ignored, never raise.
function ctaDismissUnderRace(int $userId, int $dismissalId, string $key, Closure $act): void
{
    $armed = true;
    $injected = false;

    DB::listen(function ($query) use (&$armed, &$injected, $userId, $dismissalId, $key): void {
        if (! $armed || $injected || ! str_contains($query->sql, 'savings_insight_dismissals')) {
            return;
        }
        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }

        $injected = true;
        DB::table('savings_insight_dismissals')->insertOrIgnore([
            'id' => $dismissalId,
            'user_id' => $userId,
            'insight_key' => $key,
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);
    });

    try {
        $act();
    } finally {
        $armed = false;
    }
}

/** @return ArrayObject<int, EntityMutated> */
function ctaDismissRecord(): ArrayObject
{
    /** @var ArrayObject<int, EntityMutated> $captured */
    $captured = new ArrayObject;

    app(Dispatcher::class)->listen(
        EntityMutated::class,
        static function (EntityMutated $event) use ($captured): void {
            $captured[] = $event;
        },
    );

    return $captured;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-01 10:15:00');
    $this->user = ctaDismissUser('card');
    $this->key = 'cheaper:4242';
    $this->dismissalId = DerivedRowId::for('savings_insight_dismissals', [
        'user_id' => (int) $this->user->id,
        'insight_key' => $this->key,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('announces a create describing the row that is actually stored', function (): void {
    $captured = ctaDismissRecord();

    ctaDismissUnderRace(
        (int) $this->user->id,
        $this->dismissalId,
        $this->key,
        fn () => app(SavingsInsightsQuery::class)->dismiss($this->user, $this->key),
    );

    $stored = DB::table('savings_insight_dismissals')->where('id', $this->dismissalId)->first();

    expect($stored)->not->toBeNull()
        ->and($stored->created_at)->toBe('2026-06-01 10:15:00')
        ->and($captured)->toHaveCount(1)
        ->and($captured[0]->pk)->toBe($this->dismissalId)
        ->and($captured[0]->mutationType)->toBe('create')
        ->and($captured[0]->dirtyFields['created_at'])->toBe($stored->created_at);
});

it('announces one create for two dismissals of the same card', function (): void {
    $captured = ctaDismissRecord();

    /** @var SavingsInsightsQuery $insights */
    $insights = app(SavingsInsightsQuery::class);
    $insights->dismiss($this->user, $this->key);
    $insights->dismiss($this->user, $this->key);

    expect(DB::table('savings_insight_dismissals')->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($captured)->toHaveCount(1)
        ->and($captured[0]->pk)->toBe($this->dismissalId);
});

it('announces nothing for a dismissal the device already holds', function (): void {
    DB::table('savings_insight_dismissals')->insert([
        'id' => $this->dismissalId,
        'user_id' => $this->user->id,
        'insight_key' => $this->key,
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    $captured = ctaDismissRecord();

    app(SavingsInsightsQuery::class)->dismiss($this->user, $this->key);

    expect($captured)->toHaveCount(0)
        ->and(DB::table('savings_insight_dismissals')->where('id', $this->dismissalId)->value('created_at'))
        ->toBe('2020-01-01 00:00:00');
});

it('writes nothing for a key this module could not have composed', function (): void {
    $captured = ctaDismissRecord();

    app(SavingsInsightsQuery::class)->dismiss($this->user, str_repeat('a', 500));

    expect($captured)->toHaveCount(0)
        ->and(DB::table('savings_insight_dismissals')->where('user_id', $this->user->id)->count())->toBe(0);
});
