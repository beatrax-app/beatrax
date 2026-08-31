<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use ZipArchive;

/**
 * @link ../../../../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */
final readonly class ArchiveReaderFactory
{
    // The one place the app asks whether ext-zip is here. A phone answers false
    // and always will; a desktop answers true. Both platforms then follow the
    // same two branches, so the phone's is reachable in a test by constructing
    // this with the answer a phone gives.
    public function __construct(
        private ?bool $zipExtensionAvailable = null,
        private ?ArchiveReader $reader = null,
    ) {}

    public function make(): ArchiveReader
    {
        if ($this->reader !== null) {
            return $this->reader;
        }

        return $this->zipExtensionAvailable() ? new ZipArchiveReader : new NativeZipReader;
    }

    public function zipExtensionAvailable(): bool
    {
        return $this->zipExtensionAvailable ?? class_exists(ZipArchive::class);
    }
}
