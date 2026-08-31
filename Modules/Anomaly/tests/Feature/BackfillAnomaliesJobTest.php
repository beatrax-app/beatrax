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

// The reason a backfill exists is history, and history is exactly where a
// merchant's first charge is followed by later ones. Asking for no OTHER
// charge made `first_time` unreachable from this path: the shipped demo
// dataset carried 25 backfilled alerts and not one of them.
it('reaches first_time over a history where the merchant was used again later', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();

    $fixture = AnomalyCorpusSeeder::load('first-time-large');
    $fixture['history_after'] = [
        ['counterparty' => 'new-electronics-shop', 'amount_minor' => -2200, 'currency' => 'EUR', 'posted_at' => '2026-06-16'],
    ];
    $firstChargeId = AnomalyCorpusSeeder::seed($db, $user, $fixture);

    backfillRunJob($user->id);

    $alert = AnomalyAlert::query()->where('transaction_id', $firstChargeId)->first();
    expect($alert)->not->toBeNull();
    expect($alert->reasons)->toContain('first_time');
});

// A duplicate-only alert carries no per-merchant baseline, and the row that
// reads it back must still know what was charged.
it('stamps the charge and its currency on an alert no baseline explains', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();

    $txnId = AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('duplicate-in-window'));

    backfillRunJob($user->id);

    $alert = AnomalyAlert::query()->where('transaction_id', $txnId)->firstOrFail();
    expect($alert->reasons)->toBe(['duplicate'])
        ->and($alert->baseline_amount_minor)->toBeNull()
        ->and($alert->latest_amount_minor)->toBe(-4999)
        ->and($alert->currency)->toBe('EUR');
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

it('claims the backfill before the walk so a racing run that already claimed never re-walks', function (): void {
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

it('only evaluates the owning user (cross-user isolation)', function (): void {
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
