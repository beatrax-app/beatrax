<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Internal\Enums\AnomalyDetector;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('derives the canonical reasons order from the enum rather than a second hand-written list', function (): void {
    expect(AnomalyDetector::values())->toBe(['large', 'first_time', 'duplicate'])
        ->and(AnomalyDetector::inCanonicalOrder([AnomalyDetector::Duplicate, AnomalyDetector::Large]))
        ->toBe([AnomalyDetector::Large, AnomalyDetector::Duplicate]);
});

it('drops a reason string the enum does not name instead of carrying it to a screen', function (): void {
    expect(AnomalyDetector::listFrom(['large', 'seasonal_spike', 'duplicate']))
        ->toBe([AnomalyDetector::Large, AnomalyDetector::Duplicate])
        ->and(AnomalyDetector::listFrom('not-a-list'))->toBe([]);
});

it('rejects an out-of-band INSERT carrying a suppression detector outside the enum', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();

    $caught = null;
    try {
        $this->db->connection()->table('anomaly_suppression_rules')->insert([
            'user_id' => $user->id, 'counterparty_id' => null, 'detector' => 'seasonal_spike',
            'direction' => 'expense', 'amount_band_low_minor' => -2700, 'amount_band_high_minor' => -2000,
            'currency' => 'EUR', 'created_at' => '2026-06-16 00:00:00', 'updated_at' => '2026-06-16 00:00:00',
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught?->getMessage())->toContain('Invalid anomaly_suppression_rules.detector value')
        ->and($this->db->connection()->table('anomaly_suppression_rules')->count())->toBe(0);
});

it('rejects an UPDATE that drifts a suppression detector out of the enum', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $id = $this->db->connection()->table('anomaly_suppression_rules')->insertGetId([
        'user_id' => $user->id, 'counterparty_id' => null, 'detector' => AnomalyDetector::Large->value,
        'direction' => 'expense', 'amount_band_low_minor' => -2700, 'amount_band_high_minor' => -2000,
        'currency' => 'EUR', 'created_at' => '2026-06-16 00:00:00', 'updated_at' => '2026-06-16 00:00:00',
    ]);

    $caught = null;
    try {
        $this->db->connection()->table('anomaly_suppression_rules')->where('id', $id)->update(['detector' => 'seasonal_spike']);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught?->getMessage())->toContain('Invalid anomaly_suppression_rules.detector value')
        ->and($this->db->connection()->table('anomaly_suppression_rules')->where('id', $id)->value('detector'))
        ->toBe(AnomalyDetector::Large->value);
});

it('accepts every detector the enum names, so the constraint and the code cannot drift', function (string $detector): void {
    $user = AnomalyCorpusSeeder::makeUser();

    $id = $this->db->connection()->table('anomaly_suppression_rules')->insertGetId([
        'user_id' => $user->id, 'counterparty_id' => null, 'detector' => $detector,
        'direction' => 'expense', 'amount_band_low_minor' => -2700, 'amount_band_high_minor' => -2000,
        'currency' => 'EUR', 'created_at' => '2026-06-16 00:00:00', 'updated_at' => '2026-06-16 00:00:00',
    ]);

    expect($this->db->connection()->table('anomaly_suppression_rules')->where('id', $id)->value('detector'))->toBe($detector);
})->with(AnomalyDetector::values());

it('hands the read layer typed detectors, so a blade compares cases and never a raw key', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, AnomalyCorpusSeeder::load('first-time-large'));

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    /** @var AnomalyAlertQuery $query */
    $query = $this->app->make(AnomalyAlertQuery::class);
    $open = $query->openForUser($user);

    expect($open)->toHaveCount(1)
        ->and($open[0]->reasons)->toBe([AnomalyDetector::Large, AnomalyDetector::FirstTime]);
});

it('keys the dashboard breakdown on the enum value the badge reads back', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, AnomalyCorpusSeeder::load('first-time-large'));

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    /** @var AnomalyAlertQuery $query */
    $query = $this->app->make(AnomalyAlertQuery::class);

    expect($query->openDetectorBreakdownForUser($user))
        ->toBe([AnomalyDetector::Large->value => 1, AnomalyDetector::FirstTime->value => 1]);
});
