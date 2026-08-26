<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

// The cache keys a preview occupies. Shared by the writer that fills them and
// the reader that pages through them, so a chunk written under one name is
// never looked for under another.
final class PreviewKeys
{
    // How many rows travel in one cache entry. Small enough that reading a
    // page never inflates more than a page, large enough that a 30,000-row
    // statement is not 30,000 round trips.
    public const int CHUNK_ROWS = 250;

    // Deliberately not the old `import.%d.preview`, which held a whole result
    // array. An entry written by the previous build must read as a preview that
    // is not there -- an expired preview is a state the wizard already handles,
    // and hydrating a head out of the old shape would throw on the screen.
    public static function head(int $importRunId): string
    {
        return sprintf('import.%d.preview.head', $importRunId);
    }

    public static function rowChunk(int $importRunId, int $chunk): string
    {
        return sprintf('import.%d.preview.rows.%d', $importRunId, $chunk);
    }

    public static function canonicalChunk(int $importRunId, int $chunk): string
    {
        return sprintf('import.%d.canonical.%d', $importRunId, $chunk);
    }

    public static function enrichmentChunk(int $importRunId, int $chunk): string
    {
        return sprintf('import.%d.enrichments.%d', $importRunId, $chunk);
    }
}
