<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

// Filesystem repository for raw .eml blobs that arrived via file drop
// (wizard upload + watched-folder path), separate from the EmailScan
// inbox blob store. Partitioned per user_id/year/month; writes are
// atomic via tmp + flock + fsync + chmod + rename.
final class FileDropEmlBlobStore
{
    private const MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._\-]{1,200}$/';

    private const DIR_MODE = 0700;

    private const FILE_MODE = 0600;

    public function __construct(
        private readonly Filesystem $files,
        private readonly Application $app,
    ) {}

    public function pathFor(
        int $userId,
        DateTimeImmutable $internalDate,
        string $messageIdHash,
    ): string {
        if (preg_match(self::MESSAGE_ID_PATTERN, $messageIdHash) !== 1) {
            throw new InvalidArgumentException(
                'FileDropEmlBlobStore: messageIdHash must match '
                .'[A-Za-z0-9._-]{1,200}; rejected to prevent path traversal.',
            );
        }

        return $this->app->storagePath(sprintf(
            'app/inbox/%d/file-drop/%04d/%02d/%s.eml',
            $userId,
            (int) $internalDate->format('Y'),
            (int) $internalDate->format('m'),
            $messageIdHash,
        ));
    }

    public function put(string $absolutePath, string $rawMime): void
    {
        $dir = dirname($absolutePath);
        $dirExisted = $this->files->isDirectory($dir);
        $this->files->ensureDirectoryExists($dir, self::DIR_MODE, recursive: true);
        if (! $dirExisted) {
            if (! @chmod($dir, self::DIR_MODE)) {
                throw new RuntimeException(
                    "FileDropEmlBlobStore: failed to chmod 0700 on newly-created directory {$dir}.",
                );
            }
        }

        $tmp = $absolutePath.'.tmp';

        // Narrow umask BEFORE opening the temp file so it is born at
        // mode 0600 rather than the umask-0022 default of 0644 — the
        // explicit chmod below is defence-in-depth on top of that.
        $previousUmask = umask(0077);

        $fp = @fopen($tmp, 'wb');
        if ($fp === false) {
            umask($previousUmask);
            throw new RuntimeException(
                "FileDropEmlBlobStore: could not open temp file at {$tmp}.",
            );
        }

        try {
            @flock($fp, LOCK_EX);
            $written = @fwrite($fp, $rawMime);
            if ($written === false || $written !== strlen($rawMime)) {
                throw new RuntimeException(
                    "FileDropEmlBlobStore: short write to temp file at {$tmp}.",
                );
            }
            @fflush($fp);
            if (function_exists('fsync')) {
                @fsync($fp);
            }
            @flock($fp, LOCK_UN);
            @fclose($fp);
            $fp = null;

            if (! @chmod($tmp, self::FILE_MODE)) {
                throw new RuntimeException(
                    "FileDropEmlBlobStore: failed to chmod temp file at {$tmp}.",
                );
            }

            if (! @rename($tmp, $absolutePath)) {
                throw new RuntimeException(
                    "FileDropEmlBlobStore: atomic rename failed from {$tmp} to {$absolutePath}.",
                );
            }
        } catch (Throwable $e) {
            if (is_resource($fp)) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
            @unlink($tmp);
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException(
                "FileDropEmlBlobStore: unexpected failure writing {$absolutePath}.",
            );
        } finally {
            umask($previousUmask);
        }
    }

    public function delete(string $absolutePath): void
    {
        @unlink($absolutePath);
    }

    public function exists(string $absolutePath): bool
    {
        return $this->files->exists($absolutePath);
    }
}
