<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\RowChunk;
use Modules\DriftAlerts\Internal\Jobs\RevivedExpiredDriftSnoozesJob;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;

// No rows are needed: nothing in the app runs ANALYZE, so SQLite plans this
// from the schema alone and answers the same way on an empty install as on the
// alert history an hourly sweep actually walks.
$rsdPlan = static function (DatabaseManager $db): string {
    $query = $db->connection()->table('drift_alerts')
        ->where('state', DriftAlertState::Snoozed->value)
        ->where(static function (Builder $expiry): void {
            $expiry->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', '2026-09-12 10:00:00');
        })
        ->orderBy('id')
        ->select('id')
        ->limit(RowChunk::DEFAULT_SIZE);

    return implode("\n", array_map(
        static fn (object $row): string => (string) ($row->detail ?? ''),
        $db->connection()->select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()),
    ));
};

it('carries an index the state predicate leads with', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $row = $db->connection()->selectOne(
        "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'drift_alerts_state_idx'",
    );

    expect($row)->not->toBeNull();
});

// The sweep is global by design, so neither `user_id`-leading index can answer
// it; without one that leads with `state`, the hourly pass reads every alert
// the reader has ever been shown to find the few whose snooze has expired.
it('seeks the snoozed alerts instead of scanning the table for them', function () use ($rsdPlan): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($rsdPlan($db))
        ->toContain('SEARCH drift_alerts USING INDEX drift_alerts_state_idx')
        ->and($rsdPlan($db))->not->toContain('SCAN drift_alerts');
});

// An empty sweep is enough to read the shape: the walk asks its first page
// whether or not anything matches, and an unbounded read would have no limit
// on it. The set it collects is bounded only by the hours it accumulated over.
it('asks for the candidates a page at a time rather than all at once', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $statements = [];
    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    (new RevivedExpiredDriftSnoozesJob)->handle(
        $db,
        app(DriftAlertStateMachine::class),
        app(Clock::class),
    );

    $candidateReads = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_contains($sql, 'from "drift_alerts"'),
    ));

    expect($candidateReads)->toHaveCount(1)
        ->and($candidateReads[0])->toContain('limit '.RowChunk::DEFAULT_SIZE);
});
