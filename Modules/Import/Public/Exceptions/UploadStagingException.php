<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use RuntimeException;

// Raised while staging an upload to durable storage — hashing the bytes,
// reading the temp source, persisting the stable copy, or resolving its
// absolute path. Each named constructor pins one failure mode so the
// upload wizard's error surface and the logs can tell them apart.
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
}
