<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

/**
 * Thrown by `PreviewSummaryBuilder::forRun()` when a migration run has no
 * staged rows yet — i.e. `StartMigrationRun` never successfully populated
 * `migration_staging_*` for it (parse failed before staging, or the run id
 * otherwise reached preview/confirm before parsing completed). Mirrors the
 * cache-miss typed-exception discipline `Modules\Import\Internal\Pipeline\
 * PreviewCache` established for the serialized-blob approach this phase's
 * relational staging tables replace (D-06) — throw a specific exception
 * rather than returning a zero-count `PreviewSummary` that would silently
 * look like "an empty but valid import".
 */
final class MigrationRunNotParsedException extends RuntimeException
{
    public function __construct(int $migrationRunId)
    {
        parent::__construct("Migration run {$migrationRunId} has no staged rows — it has not been parsed yet, or parsing failed before staging completed.");
    }
}
