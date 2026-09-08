<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Search\Public\Contracts\SearchIndexRepairContract;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Merge\SearchDocumentRows;
use Modules\Sync\Internal\Merge\SearchIndexRefresher;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// The refresher's catch ended at a log line whose recovery was `search:reindex`
// — a console command. Neither a phone nor a desktop window has a console, so
// the row stayed unfindable for as long as nobody edited it again, and a row
// that arrived by sync may never be edited here at all.

// The repair queue beside it already drains on the next unlocked request, and
// nothing told it.
function ftsGapRecordingLogger(): LoggerInterface
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

function ftsGapWriterThatThrows(): SearchIndexWriterContract
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

it('owes the index doc of a row whose refresh threw', function (): void {
    $db = app(DatabaseManager::class);
    /** @var SearchIndexRepairContract $repairs */
    $repairs = app(SearchIndexRepairContract::class);

    $documents = new SearchDocumentRows($db);
    $documents->rowWritten('transactions', 7, 1);

    (new SearchIndexRefresher(ftsGapWriterThatThrows(), null, $repairs))->refresh($documents, 1);

    expect($db->connection()->table('search_index_repairs')->where('transaction_id', 7)->exists())
        ->toBeTrue('the row is stored, unfindable, and nothing is owed for it');
});

// A failed DELETE is owed on the same queue. The drain calls the writer's
// upsert, which finds the transaction gone and takes the doc with it, so one
// queue answers both directions.
it('owes the index doc of a row whose tombstone refresh threw', function (): void {
    $db = app(DatabaseManager::class);
    /** @var SearchIndexRepairContract $repairs */
    $repairs = app(SearchIndexRepairContract::class);

    $documents = new SearchDocumentRows($db);
    $documents->rowDeleted('transactions', [9]);

    (new SearchIndexRefresher(ftsGapWriterThatThrows(), null, $repairs))->refresh($documents, 1);

    expect($db->connection()->table('search_index_repairs')->where('transaction_id', 9)->exists())->toBeTrue();
});

// Reported twice is one debt: the queue holds one row per transaction, so a
// replay that meets the same row again does not grow the backlog.
it('records one debt however often the same row fails', function (): void {
    $db = app(DatabaseManager::class);
    /** @var SearchIndexRepairContract $repairs */
    $repairs = app(SearchIndexRepairContract::class);

    $documents = new SearchDocumentRows($db);
    $documents->rowWritten('transactions', 7, 1);

    $refresher = new SearchIndexRefresher(ftsGapWriterThatThrows(), null, $repairs);
    $refresher->refresh($documents, 1);
    $refresher->refresh($documents, 1);

    expect($db->connection()->table('search_index_repairs')->where('transaction_id', 7)->count())->toBe(1);
});

// Without the queue there is nothing to name but the console command, and the
// line says so rather than claiming a recovery that is not wired up.
it('still names the console command when no repair queue was supplied', function (): void {
    $db = app(DatabaseManager::class);

    $log = ftsGapRecordingLogger();
    $documents = new SearchDocumentRows($db);
    $documents->rowWritten('transactions', 7, 1);

    (new SearchIndexRefresher(ftsGapWriterThatThrows(), $log, null))->refresh($documents, 1);

    expect($log->records)->toHaveCount(1)
        ->and($log->records[0]['context']['recoverWith'])->toBe('search:reindex');
});

// Recording the debt is only half of it — the drain has to be able to see it.
// A fresh row carries no failed_fingerprint, so it is unanswered under every
// keyring, which is what makes the next unlocked request pick it up.
it('leaves the debt visible to the pass that drains it', function (): void {
    $db = app(DatabaseManager::class);
    /** @var SearchIndexRepairContract $repairs */
    $repairs = app(SearchIndexRepairContract::class);

    $documents = new SearchDocumentRows($db);
    $documents->rowWritten('transactions', 7, 1);

    (new SearchIndexRefresher(ftsGapWriterThatThrows(), null, $repairs))->refresh($documents, 1);

    expect($repairs->hasWork(1, null))->toBeTrue()
        ->and($repairs->hasWork(1, str_repeat('a', 64)))->toBeTrue(
            'the debt is invisible to a pass holding key material, which is the only pass that can settle it'
        )
        ->and($repairs->hasWork(2, null))->toBeFalse('another account was billed for it');
});
