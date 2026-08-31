<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Anomaly\Internal\AnomalyEvaluator;
use Modules\Anomaly\Tests\Support\AnomalyCorpusSeeder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    CarbonImmutable::setTestNow('2026-06-16 12:00:00');

    $this->user = AnomalyCorpusSeeder::makeUser();
    $this->txnId = AnomalyCorpusSeeder::seed($this->db, $this->user, AnomalyCorpusSeeder::load('large-above'));
    /** @var AnomalyEvaluator $evaluator */
    $evaluator = $this->app->make(AnomalyEvaluator::class);
    $this->evaluator = $evaluator;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('raises a write that failed for any reason other than the charge already having an alert', function (): void {
    // RAISE(ABORT) reports SQLSTATE 23000, the same class a unique violation
    // does, so a code-only check would swallow this.
    $this->db->connection()->statement(
        "CREATE TRIGGER anomaly_alerts_block_writes BEFORE INSERT ON anomaly_alerts FOR EACH ROW
         BEGIN SELECT RAISE(ABORT, 'no space left on device'); END",
    );

    expect(fn () => $this->evaluator->evaluate($this->txnId, $this->user))
        ->toThrow(QueryException::class);

    expect($this->db->connection()->table('anomaly_alerts')->count())->toBe(0);
});

it('still treats a re-evaluation of a charge that already has an alert as a silent no-op', function (): void {
    $this->evaluator->evaluate($this->txnId, $this->user);
    $this->evaluator->evaluate($this->txnId, $this->user);

    expect($this->db->connection()->table('anomaly_alerts')->where('transaction_id', $this->txnId)->count())->toBe(1);
});

it('writes no alert row at all when the reasons list names a detector the enum does not have', function (): void {
    $caught = null;
    try {
        $this->db->connection()->table('anomaly_alerts')->insert([
            'id' => 1, 'user_id' => $this->user->id, 'transaction_id' => $this->txnId,
            'state' => 'open', 'direction' => 'expense',
            'reasons' => json_encode(['seasonal_spike']),
            'detected_at' => '2026-06-16 12:00:00',
            'created_at' => '2026-06-16 12:00:00', 'updated_at' => '2026-06-16 12:00:00',
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught?->getMessage())->toContain('Invalid anomaly_alerts.reasons value')
        ->and($this->db->connection()->table('anomaly_alerts')->count())->toBe(0);
});

it('rejects an alert that names no reason at all, which no screen could explain', function (): void {
    $caught = null;
    try {
        $this->db->connection()->table('anomaly_alerts')->insert([
            'id' => 2, 'user_id' => $this->user->id, 'transaction_id' => $this->txnId,
            'state' => 'open', 'direction' => 'expense',
            'reasons' => json_encode([]),
            'detected_at' => '2026-06-16 12:00:00',
            'created_at' => '2026-06-16 12:00:00', 'updated_at' => '2026-06-16 12:00:00',
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught?->getMessage())->toContain('Invalid anomaly_alerts.reasons value');
});
