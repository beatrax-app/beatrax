<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Merge\SearchDocumentRows;
use Modules\Sync\Internal\Merge\SearchIndexRefresher;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// A failed index refresh used to be filed as a quarantined operation: device id
// 'system-fts', reason 'strategy_error', clock zero. Nothing was refused — the
// op was applied and the row is here — and 'strategy_error' is one of the two
// reasons that drive the devices screen's "waiting to be added" notice, under an
// epoch of null that nothing retires.

function ftsRecordingLogger(): LoggerInterface
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = [
                'level' => is_string($level) ? $level : (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    };
}

function ftsRefusingWriter(): SearchIndexWriterContract
{
    return new class implements SearchIndexWriterContract
    {
        public function upsertForTransaction(int $transactionId, int $actorUserId): void
        {
            throw new RuntimeException('fts5: database disk image is malformed');
        }

        public function deleteForTransaction(int $transactionId, int $actorUserId): void
        {
            throw new RuntimeException('fts5: database disk image is malformed');
        }
    };
}

it('writes no quarantine row when the index refresh fails', function (): void {
    $db = app(DatabaseManager::class);

    $documents = new SearchDocumentRows($db);
    $documents->rowWritten('transactions', 7, 1);
    $documents->rowDeleted('transactions', [9]);

    (new SearchIndexRefresher(ftsRefusingWriter(), ftsRecordingLogger()))->refresh($documents, 1);

    expect($db->connection()->table('op_log_quarantine')->count())->toBe(0);
});

// Why an empty quarantine is the point rather than a detail: the reason it used
// to write is one a later pass treats as recoverable, so one index hiccup put a
// notice on the devices screen saying data had arrived and was about to land.
it('never writes the reason that drives the reader-facing backlog notice', function (): void {
    $db = app(DatabaseManager::class);

    expect(QuarantineReason::recoverable())->toContain(QuarantineReason::StrategyError->value);

    $documents = new SearchDocumentRows($db);
    $documents->rowWritten('transactions', 7, 1);

    (new SearchIndexRefresher(ftsRefusingWriter(), ftsRecordingLogger()))->refresh($documents, 1);

    expect($db->connection()->table('op_log_quarantine')
        ->where('reason', QuarantineReason::StrategyError->value)
        ->count())->toBe(0);
});

it('reports the stale index at warning, naming the row, the operation and the way out', function (): void {
    $db = app(DatabaseManager::class);
    $log = ftsRecordingLogger();

    $documents = new SearchDocumentRows($db);
    $documents->rowWritten('transactions', 7, 3);
    $documents->rowDeleted('transactions', [9]);

    (new SearchIndexRefresher(ftsRefusingWriter(), $log))->refresh($documents, 3);

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> $records */
    $records = $log->records;

    expect($records)->toHaveCount(2)
        ->and($records[0]['level'])->toBe('warning')
        ->and($records[0]['message'])->toContain('search:reindex')
        ->and($records[0]['context']['pk'])->toBe('7')
        ->and($records[0]['context']['ftsOperation'])->toBe('upsert')
        ->and($records[0]['context']['userId'])->toBe(3)
        ->and($records[0]['context']['reason'])->toBe(RuntimeException::class)
        ->and($records[1]['level'])->toBe('warning')
        ->and($records[1]['context']['pk'])->toBe('9')
        ->and($records[1]['context']['ftsOperation'])->toBe('delete');
});

it('reports nothing and touches nothing when the index is refreshed cleanly', function (): void {
    $db = app(DatabaseManager::class);
    $log = ftsRecordingLogger();

    $writer = new class implements SearchIndexWriterContract
    {
        public function upsertForTransaction(int $transactionId, int $actorUserId): void {}

        public function deleteForTransaction(int $transactionId, int $actorUserId): void {}
    };

    $documents = new SearchDocumentRows($db);
    $documents->rowWritten('transactions', 7, 1);

    (new SearchIndexRefresher($writer, $log))->refresh($documents, 1);

    expect($log->records)->toBe([])
        ->and($db->connection()->table('op_log_quarantine')->count())->toBe(0);
});
