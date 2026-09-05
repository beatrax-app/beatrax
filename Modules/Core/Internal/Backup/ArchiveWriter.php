<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use Modules\Core\Public\Exceptions\BackupIoException;

interface ArchiveWriter
{
    /**
     * @throws BackupIoException
     */
    public function open(string $path): void;

    /**
     * @throws BackupIoException
     */
    public function addFile(string $sourcePath, string $entryName): void;

    /**
     * @throws BackupIoException
     */
    public function finish(): void;
}
