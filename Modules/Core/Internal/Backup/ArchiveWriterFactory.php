<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use ZipArchive;

/**
 * @link ../../../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */
final readonly class ArchiveWriterFactory
{
    // The one place the export asks whether ext-zip is here. A phone answers
    // false and always will; a desktop answers true. Both platforms follow the
    // same two branches, so the phone's is reachable in a test by constructing
    // this with the answer a phone gives.
    public function __construct(
        private ?bool $zipExtensionAvailable = null,
        private ?ArchiveWriter $writer = null,
    ) {}

    public function make(): ArchiveWriter
    {
        if ($this->writer !== null) {
            return $this->writer;
        }

        return $this->zipExtensionAvailable() ? new ZipArchiveWriter : new NativeZipWriter;
    }

    public function zipExtensionAvailable(): bool
    {
        return $this->zipExtensionAvailable ?? class_exists(ZipArchive::class);
    }
}
