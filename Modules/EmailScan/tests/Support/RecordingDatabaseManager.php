<?php

declare(strict_types=1);

namespace Modules\EmailScan\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

// capturedStatements collects one array per transaction() invocation, holding
// the raw SQL issued via statement() inside that transaction body.
final class RecordingDatabaseManager extends DatabaseManager
{
    /** @var list<list<string>> */
    public array $capturedStatements = [];

    public function __construct(
        private readonly DatabaseManager $inner,
        array &$statementsInTransactions = [],
    ) {
        $this->capturedStatements = &$statementsInTransactions;
    }

    public function connection($name = null): Connection
    {
        return new RecordingConnection(
            $this->inner->connection($name),
            capturedStatements: $this->capturedStatements,
        );
    }
}
