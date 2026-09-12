<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Core\Public\Support\RowChunk;

// No rows are needed: nothing in the app runs ANALYZE, so SQLite plans this
// from the schema alone and answers the same way on an empty install as on the
// twenty thousand alerts a reader who never closes one accumulates.
$rsaPlan = static function (DatabaseManager $db): string {
    $query = $db->connection()->table('anomaly_alerts')
        ->where('state', AnomalyAlertState::Snoozed->value)
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
        "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'anomaly_alerts_state_idx'",
    );

    expect($row)->not->toBeNull();
});

// The sweep is global by design, so neither `user_id`-leading index can answer
// it; without one that leads with `state`, the hourly pass reads every alert
// the reader has ever been shown to find the few whose snooze has expired.
it('seeks the snoozed alerts instead of scanning the table for them', function () use ($rsaPlan): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($rsaPlan($db))
        ->toContain('SEARCH anomaly_alerts USING INDEX anomaly_alerts_state_idx')
        ->and($rsaPlan($db))->not->toContain('SCAN anomaly_alerts');
});
