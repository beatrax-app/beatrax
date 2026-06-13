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

/*
 * Feature coverage for the full-history BackfillAnomaliesJob: it
 * enumerates the user's whole transaction history via lazyById, lands
 * alerts in Open through the shared AnomalyEvaluator::evaluate() path, is
 * idempotent on UNIQUE(transaction_id), claims the backfill BEFORE the walk
 * (WR-01) so a concurrent dispatch cannot double-walk history, and no-ops
 * once users.anomaly_backfilled_at is set (D-13/D-14, T-09-15).
 */

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

    // The large-above fixture seeds 5 stable Spotify baselines + one
    // €23.49 outlier. Only the outlier is anomalous.
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

    // The guard is now set. Seed a SECOND anomalous fixture as a fresh
    // user to mint a brand-new anomalous transaction, then re-parent that
    // transaction onto our backfilled user. A second dispatch must skip
    // it wholesale (re-activation is an explicit Plan 05 concern).
    $otherUser = AnomalyCorpusSeeder::makeUser();
    $newTxnId = AnomalyCorpusSeeder::seed($db, $otherUser, AnomalyCorpusSeeder::load('large-above'));
    $db->connection()->table('transactions')->where('id', $newTxnId)->update(['user_id' => $user->id]);

    backfillRunJob($user->id);

    // Guard short-circuited: the new anomalous row was never evaluated.
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

    // UNIQUE(transaction_id) + the anomaly_backfilled_at guard together
    // keep the alert count at exactly one across repeated runs.
    expect(AnomalyAlert::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('claims the backfill before the walk so a racing run that already claimed never re-walks (WR-01)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();
    AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('large-above'));

    // Simulate worker A having ALREADY claimed the backfill (stamped the
    // guard) but not yet finished walking — the conditional whereNull claim
    // is the mutex. The User model loaded inside handle() still reads the
    // stamped value, so the early D-13 guard short-circuits; even if it did
    // not, the claim update would affect 0 rows and bail. Either way worker
    // B must NOT walk history.
    $db->connection()->table('users')
        ->where('id', $user->id)
        ->update(['anomaly_backfilled_at' => '2026-06-13 00:00:00']);

    backfillRunJob($user->id);

    // No alerts: worker B never walked the (anomalous) history.
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
    // User B's history was never touched: no alerts, guard still null.
    expect(AnomalyAlert::query()->where('user_id', $userB->id)->count())->toBe(0);
    expect(User::query()->findOrFail($userB->id)->anomaly_backfilled_at)->toBeNull();
});
