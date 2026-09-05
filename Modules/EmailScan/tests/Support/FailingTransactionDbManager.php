<?php

declare(strict_types=1);

namespace Modules\EmailScan\Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

// Forces the .eml-then-DB rollback path without touching production code.
final class FailingTransactionDbManager extends DatabaseManager
{
    public function __construct(
        private readonly DatabaseManager $inner,
        private readonly int $failOnCall,
    ) {
        // Every call proxies to $this->inner, so the parent constructor has
        // nothing to set up.
    }

    /**
     * @param  string|null  $name
     */
    public function connection($name = null): Connection
    {
        return new FailingTransactionConnection(
            $this->inner->connection($name),
            failOnCall: $this->failOnCall,
        );
    }
}
