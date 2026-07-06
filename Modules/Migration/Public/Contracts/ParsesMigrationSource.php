<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Migration\Public\Dto\MigrationBatch;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;

/**
 * The stable swap seam every format parser (`Ynab4Parser`, `NynabParser`,
 * `ActualParser`) implements. `StartMigrationRun` (Plan 05) resolves the
 * correct implementation by `format()` and calls `parse()` against the
 * already-extracted upload directory — extraction (ZIP-bomb-guarded) happens
 * upstream of this seam, so every implementation receives a plain
 * filesystem path, never a raw upload stream.
 */
interface ParsesMigrationSource
{
    /**
     * The source-product identifier this parser handles. Matches
     * `migration_runs.source_product`.
     *
     * @return 'ynab4'|'nynab'|'actual'
     */
    public function format(): string;

    /**
     * Parses an already-extracted export directory into the common IR.
     *
     * `$extractedPath` is a directory on local disk: for YNAB4/nYNAB it
     * contains the two CSV files (loose, or already unzipped); for Actual it
     * contains `db.sqlite` + `metadata.json`. `$migrationRunId` is stamped
     * onto every staging row the caller subsequently writes from the
     * returned batch — this method itself performs no persistence.
     *
     * @throws UnrecognizedMigrationFileException when the directory's
     *                                            contents do not match this parser's expected shape (missing
     *                                            file, unreadable/corrupt archive, or a required column/table
     *                                            this parser depends on is absent) — Req 1's "corrupt file
     *                                            rejected, not partial import": this method must throw before
     *                                            yielding any partial `MigrationBatch`, never return one.
     */
    public function parse(string $extractedPath, User $user, int $migrationRunId): MigrationBatch;
}
