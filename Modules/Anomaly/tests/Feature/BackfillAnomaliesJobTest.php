<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Internal\Jobs\BackfillAnomaliesJob;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

uses(RefreshDatabase::class);

function backfillRunJob(int $userId): void
{
    /** @var BackfillAnomaliesJob $job */
    $job = app(BackfillAnomaliesJob::class, ['userId' => $userId]);
    $job->handle(
        app(AnomalyEvaluator::class),
        app(DatabaseManager::class),
        app(Clock::class),
    );
}

it('backfills the full history, lands the anomalous charge in Open, and sets anomaly_backfilled_at', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();

    // large-above seeds 5 stable Spotify baselines plus one €23.49 outlier.
    AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('large-above'));

    expect(AnomalyAlert::query()->where('user_id', $user->id)->count())->toBe(0);

    backfillRunJob($user->id);

    $alerts = AnomalyAlert::query()->where('user_id', $user->id)->get();
    expect($alerts)->toHaveCount(1);
    expect($alerts->first()->state)->toBe('open');
    expect($alerts->first()->reasons)->toBe(['large']);

    $user->refresh();
    expect($user->anomaly_backfilled_at)->not->toBeNull();
});

it('is a no-op on a second run once anomaly_backfilled_at is set', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();
    AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('large-above'));

    backfillRunJob($user->id);
    $firstRunCount = AnomalyAlert::query()->where('user_id', $user->id)->count();
    expect($firstRunCount)->toBe(1);

    // Minting the new anomalous transaction under a fresh user and
    // re-parenting it is the only way to add unevaluated history to a user
    // whose backfill guard is already stamped.
    $otherUser = AnomalyCorpusSeeder::makeUser();
    $newTxnId = AnomalyCorpusSeeder::seed($db, $otherUser, AnomalyCorpusSeeder::load('large-above'));
    $db->connection()->table('transactions')->where('id', $newTxnId)->update(['user_id' => $user->id]);

    backfillRunJob($user->id);

    expect(AnomalyAlert::query()->where('user_id', $user->id)->count())->toBe($firstRunCount);
    expect(AnomalyAlert::query()->where('transaction_id', $newTxnId)->count())->toBe(0);
});

it('is idempotent — re-running without resetting the guard never duplicates alerts', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();
    AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('large-above'));

    backfillRunJob($user->id);
    backfillRunJob($user->id);
    backfillRunJob($user->id);

    expect(AnomalyAlert::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('claims the backfill before the walk so a racing run that already claimed never re-walks (WR-01)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();
    AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('large-above'));

    // Stamping the guard by hand simulates worker A having claimed the
    // backfill but not yet finished walking; the conditional whereNull claim
    // is the mutex worker B must lose.
    $db->connection()->table('users')
        ->where('id', $user->id)
        ->update(['anomaly_backfilled_at' => '2026-06-13 00:00:00']);

    backfillRunJob($user->id);

    expect(AnomalyAlert::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('only evaluates the owning user (cross-user isolation, T-09-16)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $userA = AnomalyCorpusSeeder::makeUser();
    $userB = AnomalyCorpusSeeder::makeUser();
    AnomalyCorpusSeeder::seed($db, $userA, AnomalyCorpusSeeder::load('large-above'));
    AnomalyCorpusSeeder::seed($db, $userB, AnomalyCorpusSeeder::load('large-above'));

    backfillRunJob($userA->id);

    expect(AnomalyAlert::query()->where('user_id', $userA->id)->count())->toBe(1);
    expect(AnomalyAlert::query()->where('user_id', $userB->id)->count())->toBe(0);
    expect(User::query()->findOrFail($userB->id)->anomaly_backfilled_at)->toBeNull();
});
