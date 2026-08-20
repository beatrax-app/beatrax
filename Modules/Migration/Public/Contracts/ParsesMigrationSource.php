<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Migration\Public\Dto\MigrationBatch;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;

interface ParsesMigrationSource
{
    /**
     * @return 'ynab4'|'nynab'|'actual'
     */
    public function format(): string;

    /**
     * @throws UnrecognizedMigrationFileException when the extracted directory
     *                                            does not match this parser's expected shape — thrown before
     *                                            yielding any partial `MigrationBatch`, never returning one.
     */
    public function parse(string $extractedPath, User $user, int $migrationRunId): MigrationBatch;
}
