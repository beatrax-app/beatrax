<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Events\AnomalyAlertOpened;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

// Idempotency here is the UNIQUE(transaction_id) collision being caught at
// the QueryException boundary, not a pre-insert existence check.
beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('produces exactly one alert when evaluated twice on the same transaction', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('large-above');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);
    $evaluator->evaluate($txnId, $user);

    expect(AnomalyAlert::query()->where('transaction_id', $txnId)->count())->toBe(1);
});

it('dispatches AnomalyAlertOpened once on the first insert and not on the re-eval', function (): void {
    Event::fake([AnomalyAlertOpened::class]);

    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('large-above');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);
    $evaluator->evaluate($txnId, $user);

    Event::assertDispatchedTimes(AnomalyAlertOpened::class, 1);
});

it('inserts nothing for a charge that trips no detector', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('large-below');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    expect(AnomalyAlert::query()->where('transaction_id', $txnId)->count())->toBe(0);
});
