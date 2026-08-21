<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

// The `suppressed-skip` fixture is a charge that would fire `large` but
// carries a matching suppression rule, so the evaluator never inserts.
beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('inserts no alert when a matching suppression rule exists', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('suppressed-skip');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    expect(AnomalyAlert::query()->where('transaction_id', $txnId)->count())->toBe(0);
});

it('inserts one alert for a non-suppressed large charge', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('large-above');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    /** @var AnomalyAlert $alert */
    $alert = AnomalyAlert::query()->where('transaction_id', $txnId)->firstOrFail();
    expect($alert->state)->toBe('open');
    expect($alert->reasons)->toBe(['large']);
    expect($alert->direction)->toBe('expense');
    expect($alert->sensitivity_percent_used)->toBe(50);
});

it('aggregates multiple reasons into one alert (first-time-large carries large + first_time)', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $fixture = AnomalyCorpusSeeder::load('first-time-large');
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, $fixture);

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    $rows = AnomalyAlert::query()->where('transaction_id', $txnId)->get();
    expect($rows)->toHaveCount(1);
    /** @var AnomalyAlert $alert */
    $alert = $rows->first();
    expect($alert->reasons)->toContain('large');
    expect($alert->reasons)->toContain('first_time');
});
