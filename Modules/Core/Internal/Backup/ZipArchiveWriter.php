<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use Modules\Core\Public\Exceptions\BackupIoException;
use ZipArchive;

final class ZipArchiveWriter implements ArchiveWriter
{
    private ?ZipArchive $zip = null;

    public function open(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupIoException('The export archive could not be opened for writing.');
        }

        $this->zip = $zip;
    }

    public function addFile(string $sourcePath, string $entryName): void
    {
        if ($this->zip === null || ! $this->zip->addFile($sourcePath, $entryName)) {
            throw new BackupIoException('A file could not be added to the export archive: '.$entryName);
        }
    }

    public function finish(): void
    {
        if ($this->zip === null || ! $this->zip->close()) {
            throw new BackupIoException('The export archive could not be written.');
        }

        $this->zip = null;
    }
}
