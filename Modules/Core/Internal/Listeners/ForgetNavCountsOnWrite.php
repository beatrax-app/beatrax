<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Modules\Core\Internal\Support\MigrationWindow;
use Modules\Core\Public\Services\NavCountsService;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

// Invalidation for the sidebar badges, taken from the statement rather than
// from each writing module's discipline. Core may not import the eight modules
// that own those tables, and a statement is the one thing all of them produce
// whether they write through Eloquent or the query builder.
/**
 * @link ../../../../.docs/features/core/an-id-read-after-an-insert.md
 */
final readonly class ForgetNavCountsOnWrite
{
    // insert, update and delete are all six characters, which is what makes
    // the cheap prefix test on the overwhelming majority — selects — a single
    // comparison rather than a regex on every query the app runs.
    private const int WRITE_VERB_LENGTH = 6;

    /**
     * @var list<string>
     */
    private const array WRITE_VERBS = ['insert', 'update', 'delete'];

    private const string NOT_INVALIDATED = 'ForgetNavCountsOnWrite: the sidebar badge counts could not be retired for this write and stay stale until their TTL expires.';

    public function __construct(
        private NavCountsService $counts,
        private MigrationWindow $migrations,
        private LoggerInterface $log,
    ) {}

    public function handle(QueryExecuted $event): void
    {
        // Adding a foreign key rebuilds the table on SQLite, and the copy it
        // emits reads as a write to the table it is copying. Bumping then put
        // the generation into `cache`, which a later migration still had to
        // create, and the whole first-launch run died on the missing table.
        if ($this->migrations->isOpen()) {
            return;
        }

        if (! self::touchesACountedTable($event->sql)) {
            return;
        }

        // This runs inside Connection::run(), after the statement it is
        // reading has already succeeded, so anything raised here is reported
        // as that statement failing. A badge the reader has to wait five
        // minutes for is the cost; a refused write is not.
        try {
            $this->counts->bumpGeneration();
        } catch (Throwable $e) {
            $this->log->warning(self::NOT_INVALIDATED, SafeExceptionContext::describe($e));
        }
    }

    // Matched on the quoted identifier the grammar emits, so `transactions`
    // does not also match a table that merely contains the word.
    private static function touchesACountedTable(string $sql): bool
    {
        $statement = ltrim($sql);

        if (! in_array(strtolower(substr($statement, 0, self::WRITE_VERB_LENGTH)), self::WRITE_VERBS, true)) {
            return false;
        }

        return array_any(NavCountsService::countedTables(), fn (string $table): bool => str_contains($statement, '"'.$table.'"'));
    }
}
