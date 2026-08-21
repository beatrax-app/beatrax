<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Modules\Ledger\Internal\Services\BackfillStartingBalanceFromStatementSummaries;

return new class extends Migration
{
    public function up(): void
    {
        /** @var BackfillStartingBalanceFromStatementSummaries $service */
        $service = Container::getInstance()->make(BackfillStartingBalanceFromStatementSummaries::class);

        $service->run();
    }

    public function down(): void
    {
        // Data-only: restoring the previous NULLs needs a backup, and re-running
        // up() is idempotent anyway.
    }
};
