<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Internal\Jobs\BackfillAnomaliesJob;
use Modules\Anomaly\Internal\Jobs\SafetyNetAnomalySweepJob;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Events\AnomalyAlertOpened;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

uses(RefreshDatabase::class);

// The claim was the completion: `users.anomaly_backfilled_at` was stamped
// before the walk, so a worker killed mid-walk — the 300s timeout, the 128 MB
// ceiling — left it behind, every later attempt returned at the early guard,
// and the job reported success over history nothing ever evaluated. The
// settings screen gates re-dispatch on the same column, so the reader could
// not ask again either.

beforeEach(function (): void {
    // The corpus seeder stamps created_at = 2026-06-13.
    CarbonImmutable::setTestNow('2026-06-14 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function killedWalkRunBackfill(int $userId): void
{
    /** @var BackfillAnomaliesJob $job */
    $job = app(BackfillAnomaliesJob::class, ['userId' => $userId]);
    $job->handle(
        app(AnomalyEvaluator::class),
        app(DatabaseManager::class),
        app(Clock::class),
    );
}

function killedWalkState(int $userId): ?stdClass
{
    return app(DatabaseManager::class)->connection()
        ->table('anomaly_backfill_state')
        ->where('user_id', $userId)
        ->first();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function killedWalkClaim(int $userId, array $overrides = []): void
{
    $now = CarbonImmutable::now()->toDateTimeString();

    app(DatabaseManager::class)->connection()->table('anomaly_backfill_state')->insert([
        'user_id' => $userId,
        'cursor_transaction_id' => null,
        'claimed_at' => $now,
        'claimed_by' => 'a worker that is not this one',
        'started_at' => $now,
        'completed_at' => null,
        'updated_at' => $now,
        ...$overrides,
    ]);
}

// The number itself, against the ceiling it was chosen above. A lease shorter
// than the worker that could be killed holding it lets a second dispatch take
// the claim off a walk that is still running, which is the concurrency this
// row exists to prevent.
it('holds the claim longer than the worker that could be killed holding it', function (): void {
    /** @var array<string, mixed> $worker */
    $worker = (array) config('nativephp.queue_workers.default');
    $ceiling = is_numeric($worker['timeout'] ?? null) ? (int) $worker['timeout'] : 0;

    expect($ceiling)->toBeGreaterThan(0, 'The worker timeout is what the lease is measured against.')
        ->and(app(BackfillAnomaliesJob::class, ['userId' => 1])->uniqueFor())->toBeGreaterThan($ceiling);
});

it('does not write the completion until the walk has run out of history', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();
    AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('large-above'));

    // Read from inside the walk, which is the only place the difference shows:
    // the claim is taken before the first transaction is judged, and the
    // completion is not written until after the last one is.
    $midWalk = [];
    app(Dispatcher::class)->listen(
        AnomalyAlertOpened::class,
        function () use ($db, $user, &$midWalk): void {
            $midWalk['backfilled_at'] = $db->connection()->table('users')
                ->where('id', $user->id)
                ->value('anomaly_backfilled_at');
            $midWalk['claim'] = killedWalkState($user->id);
        },
    );

    killedWalkRunBackfill($user->id);

    expect($midWalk)->toHaveKey('backfilled_at')
        ->and($midWalk['backfilled_at'])->toBeNull()
        ->and($midWalk['claim'])->not->toBeNull()
        ->and($midWalk['claim']->completed_at)->toBeNull();

    $finished = killedWalkState($user->id);
    expect($finished?->completed_at)->not->toBeNull()
        ->and(User::query()->findOrFail($user->id)->anomaly_backfilled_at)->not->toBeNull();
});

it('resumes from the cursor a killed walk left instead of reporting success over it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();

    $covered = AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('large-above'));
    $owed = AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('duplicate-in-window'));

    // What a kill leaves behind: a claim nobody released, a cursor short of
    // the end, and no completion. Both charges would alert; only the one past
    // the cursor is still owed an evaluation.
    killedWalkClaim($user->id, [
        'cursor_transaction_id' => $covered,
        'claimed_at' => CarbonImmutable::now()->subMinutes(20)->toDateTimeString(),
    ]);

    killedWalkRunBackfill($user->id);

    expect(AnomalyAlert::query()->where('transaction_id', $owed)->exists())->toBeTrue()
        ->and(AnomalyAlert::query()->where('transaction_id', $covered)->exists())->toBeFalse()
        ->and(User::query()->findOrFail($user->id)->anomaly_backfilled_at)->not->toBeNull()
        ->and((int) (killedWalkState($user->id)->cursor_transaction_id ?? 0))->toBe($owed);
});

it('leaves the history alone while another runner still holds the claim', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();
    AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('large-above'));

    killedWalkClaim($user->id);

    killedWalkRunBackfill($user->id);

    expect(AnomalyAlert::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(User::query()->findOrFail($user->id)->anomaly_backfilled_at)->toBeNull()
        ->and(killedWalkState($user->id)?->claimed_by)->toBe('a worker that is not this one');
});

it('dispatches the backfill again for the user whose walk never completed, and not for the one whose did', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $stuck = AnomalyCorpusSeeder::makeUser();
    $done = AnomalyCorpusSeeder::makeUser();

    killedWalkClaim($stuck->id, ['claimed_at' => CarbonImmutable::now()->subMinutes(20)->toDateTimeString()]);
    killedWalkClaim($done->id, ['completed_at' => CarbonImmutable::now()->toDateTimeString()]);
    $db->connection()->table('users')->where('id', $done->id)
        ->update(['anomaly_backfilled_at' => CarbonImmutable::now()->toDateTimeString()]);

    Bus::fake();

    $this->artisan('anomaly:safety-net-sweep')->assertSuccessful();

    // The sweep beside it runs for both and reaches neither user's older
    // history: its window is thirty days wide.
    Bus::assertDispatched(SafetyNetAnomalySweepJob::class, 2);
    Bus::assertDispatched(
        BackfillAnomaliesJob::class,
        static fn (BackfillAnomaliesJob $job): bool => $job->userId === $stuck->id,
    );
    Bus::assertDispatched(BackfillAnomaliesJob::class, 1);
});
