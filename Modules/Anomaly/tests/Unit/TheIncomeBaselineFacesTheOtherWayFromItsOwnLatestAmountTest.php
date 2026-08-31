<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Internal\Detectors\LargeVsTypicalDetector;
use Modules\Anomaly\Internal\Support\AnomalySensitivity;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

function constrainAnomalyDetectorsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require base_path('Modules/Anomaly/Database/Migrations/2026_08_27_000001_constrain_anomaly_detectors.php');

    return $migration;
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-26 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('reports an income baseline on the same side of zero as the charge it explains', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, AnomalyCorpusSeeder::load('income-spike-above'));

    /** @var LargeVsTypicalDetector $detector */
    $detector = $this->app->make(LargeVsTypicalDetector::class);
    $result = $detector->fires(
        AnomalyCorpusSeeder::transactionRow($this->db, $txnId),
        $user,
        AnomalySensitivity::fromStored($user->anomaly_sensitivity_percent),
        $user->anomaly_min_amount_minor,
    );

    expect($result)->not->toBeNull()
        ->and($result['latest_amount_minor'])->toBe(900000)
        ->and($result['baseline_amount_minor'])->toBe(300000);
});

it('keeps an expense baseline negative, the sign the ledger already writes', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, AnomalyCorpusSeeder::load('large-above'));

    /** @var LargeVsTypicalDetector $detector */
    $detector = $this->app->make(LargeVsTypicalDetector::class);
    $result = $detector->fires(
        AnomalyCorpusSeeder::transactionRow($this->db, $txnId),
        $user,
        AnomalySensitivity::fromStored($user->anomaly_sensitivity_percent),
        $user->anomaly_min_amount_minor,
    );

    expect($result)->not->toBeNull()
        ->and($result['latest_amount_minor'])->toBe(-2349)
        ->and($result['baseline_amount_minor'])->toBe(-999);
});

it('stores the pair on one side of zero, so the row never reads baseline -3000 -> actual 9000', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, AnomalyCorpusSeeder::load('income-spike-above'));

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    $row = $this->db->connection()->table('anomaly_alerts')->where('transaction_id', $txnId)->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->baseline_amount_minor * (int) $row->latest_amount_minor)->toBeGreaterThan(0);
});

it('re-signs a stored baseline that straddles zero against its own latest amount', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, AnomalyCorpusSeeder::load('income-spike-above'));

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    $this->db->connection()->table('anomaly_alerts')
        ->where('transaction_id', $txnId)
        ->update(['baseline_amount_minor' => -300000]);

    constrainAnomalyDetectorsMigration()->up();

    $row = $this->db->connection()->table('anomaly_alerts')->where('transaction_id', $txnId)->first();

    expect((int) $row->baseline_amount_minor)->toBe(300000);
});

it('leaves a baseline that already agrees with its latest amount alone', function (): void {
    $user = AnomalyCorpusSeeder::makeUser();
    $txnId = AnomalyCorpusSeeder::seed($this->db, $user, AnomalyCorpusSeeder::load('large-above'));

    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $evaluator->evaluate($txnId, $user);

    constrainAnomalyDetectorsMigration()->up();

    $row = $this->db->connection()->table('anomaly_alerts')->where('transaction_id', $txnId)->first();

    expect((int) $row->baseline_amount_minor)->toBe(-999);
});
