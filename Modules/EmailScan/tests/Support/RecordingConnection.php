<?php

declare(strict_types=1);

namespace Modules\EmailScan\Tests\Support;

use Closure;
use Illuminate\Database\Connection;

final class RecordingConnection extends Connection
{
    /** @var list<string> */
    private array $currentTransactionStatements = [];

    private bool $inTransaction = false;

    public function __construct(
        private readonly Connection $inner,
        /** @var list<list<string>> */
        private array &$capturedStatements,
    ) {}

    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->inTransaction = true;
        $this->currentTransactionStatements = [];

        try {
            $result = $this->inner->transaction($callback, $attempts);
        } finally {
            $this->capturedStatements[] = $this->currentTransactionStatements;
            $this->inTransaction = false;
            $this->currentTransactionStatements = [];
        }

        return $result;
    }

    public function table($table, $as = null)
    {
        return $this->inner->table($table, $as);
    }

    public function statement($query, $bindings = [])
    {
        if ($this->inTransaction) {
            $this->currentTransactionStatements[] = $query;
        }

        return $this->inner->statement($query, $bindings);
    }
}
