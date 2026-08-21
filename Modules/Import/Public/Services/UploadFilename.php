<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Modules\Ingestion\Public\Enums\SourceFormat;

final class UploadFilename
{
    private const string FALLBACK_STEM = 'upload';

    private const string PRESET_EXTENSION = '.csv';

    // The result is concatenated into a filesystem path, so every character
    // that could climb out of one is folded away first, and the extension is
    // taken from the declared format rather than from the uploaded name so a
    // later re-read of the stored copy still sniffs as the format it claims.
    public static function sanitise(string $original, string $extension): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? self::FALLBACK_STEM : $safe;

        return $stemPart.$extension;
    }

    // A format the enum does not name is a runtime CSV preset, which is why an
    // unrecognised value stores as CSV rather than raising: the presets are
    // user-defined layouts over the same comma-separated file.
    public static function extensionFor(string $sourceFormat): string
    {
        return SourceFormat::tryFrom($sourceFormat)?->fileExtension() ?? self::PRESET_EXTENSION;
    }
}
