<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Exceptions;

use RuntimeException;

// One named constructor per failure mode, so the upload wizard's error
// surface and the logs can tell them apart.
/**
 * @link ../../../../.docs/features/import/architecture.md#upload-wizard
 */
final class UploadStagingException extends RuntimeException
{
    public static function sha256Unavailable(): self
    {
        return new self('Could not compute SHA256 of uploaded file.');
    }

    public static function sourceUnreadable(string $path): self
    {
        return new self(sprintf('Could not read upload source file: %s', $path));
    }

    public static function persistFailed(string $relativePath): self
    {
        return new self(sprintf('Could not persist upload to stable storage: %s', $relativePath));
    }

    public static function absolutePathsUnsupported(): self
    {
        return new self('Stable storage disk does not expose absolute paths.');
    }

    public static function stagedCopyIsShort(string $relativePath, int $expected, int $written): self
    {
        return new self(sprintf(
            'Staged upload is %d bytes, not %d: %s',
            $written,
            $expected,
            $relativePath,
        ));
    }
}
