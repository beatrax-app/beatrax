<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Modules\Ledger\Internal\Services\BackfillStartingBalanceFromStatementSummaries;

return new class extends Migration
{
    public function up(): void
    {
        // Anonymous migrations are instantiated by Laravel's migrator with
        // no constructor arguments, so the backfill service is resolved from
        // the application container at the migration boundary.
        /** @var BackfillStartingBalanceFromStatementSummaries $service */
        $service = Container::getInstance()->make(BackfillStartingBalanceFromStatementSummaries::class);

        $service->run();
    }

    public function down(): void
    {
        // Backfill is data-only; restoring the previous NULL values requires
        // a SQLite backup. Re-running up() is idempotent and safe.
    }
};
