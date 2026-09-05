<?php

declare(strict_types=1);

namespace Modules\EmailScan\Tests\Support;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;

final class FailingTransactionConnection extends Connection
{
    private int $transactionCallCount = 0;

    public function __construct(
        private readonly Connection $inner,
        private readonly int $failOnCall,
    ) {
        // The inner connection is already initialised and the parent's
        // protected state is never read here.
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        $this->transactionCallCount++;
        if ($this->transactionCallCount === $this->failOnCall) {
            throw new RuntimeException('injected-tx-failure');
        }

        return $this->inner->transaction($callback, $attempts);
    }

    public function table($table, $as = null)
    {
        return $this->inner->table($table, $as);
    }

    public function statement($query, $bindings = [])
    {
        return $this->inner->statement($query, $bindings);
    }
}
