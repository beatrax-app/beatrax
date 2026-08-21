<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Internal\Jobs\SafetyNetAnomalySweepJob;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;
use Modules\Core\Public\Contracts\Clock;

uses(RefreshDatabase::class);

// Re-evaluation of an already-alerted row is a no-op because of
// UNIQUE(transaction_id), not because the sweep filters it out.
function snsRunSweep(int $userId): void
{
    /** @var SafetyNetAnomalySweepJob $job */
    $job = app(SafetyNetAnomalySweepJob::class, ['userId' => $userId]);
    $job->handle(
        app(AnomalyEvaluator::class),
        app(DatabaseManager::class),
        app(Clock::class),
    );
}

beforeEach(function (): void {
    // The corpus seeder stamps created_at = 2026-06-13; keep "now" inside
    // the recent sweep window so those rows are eligible.
    CarbonImmutable::setTestNow('2026-06-14 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('alerts a recently-imported-but-unalerted anomalous transaction', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();

    // Seed the large-above fixture WITHOUT running the reactive listener,
    // simulating a charge whose import-time detection was missed.
    AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('large-above'));
    expect(AnomalyAlert::query()->where('user_id', $user->id)->count())->toBe(0);

    snsRunSweep($user->id);

    $alerts = AnomalyAlert::query()->where('user_id', $user->id)->get();
    expect($alerts)->toHaveCount(1);
    expect($alerts->first()->state)->toBe('open');
    expect($alerts->first()->reasons)->toBe(['large']);
});

it('does not duplicate an alert for an already-alerted transaction', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();
    AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('large-above'));

    snsRunSweep($user->id);
    snsRunSweep($user->id);
    snsRunSweep($user->id);

    expect(AnomalyAlert::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('only sweeps the owning user (cross-user isolation)', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userA = AnomalyCorpusSeeder::makeUser();
    $userB = AnomalyCorpusSeeder::makeUser();
    AnomalyCorpusSeeder::seed($db, $userA, AnomalyCorpusSeeder::load('large-above'));
    AnomalyCorpusSeeder::seed($db, $userB, AnomalyCorpusSeeder::load('large-above'));

    snsRunSweep($userA->id);

    expect(AnomalyAlert::query()->where('user_id', $userA->id)->count())->toBe(1);
    expect(AnomalyAlert::query()->where('user_id', $userB->id)->count())->toBe(0);
});

it('ignores transactions imported outside the recent sweep window', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($db, $user, AnomalyCorpusSeeder::load('large-above'));

    $db->connection()->table('transactions')
        ->where('id', $txnId)
        ->update(['created_at' => '2025-01-01 00:00:00']);

    snsRunSweep($user->id);

    // The aged outlier is out of window; the 5 baselines are non-anomalous.
    expect(AnomalyAlert::query()->where('user_id', $user->id)->count())->toBe(0);
});
