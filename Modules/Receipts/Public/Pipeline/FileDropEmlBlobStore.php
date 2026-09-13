<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\SecretFileMode;
use Modules\Receipts\Internal\Exceptions\FileDropBlobWriteException;
use RuntimeException;
use Throwable;

// Filesystem repository for raw .eml blobs that arrived via file drop
// (wizard upload + watched-folder path), separate from the EmailScan
// inbox blob store. Partitioned per user_id/year/month; writes are
// atomic via tmp + flock + fsync + chmod + rename.
final readonly class FileDropEmlBlobStore
{
    private const string MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._\-]{1,200}$/';

    public function __construct(
        private Filesystem $files,
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

        // appPath(), not the framework's storagePath(): on iOS the two name
        // different trees, and UserDataLocations answers appPath() when the
        // reader asks where their mail is, deletes it, or exports it.
        return UserDataPathService::appPath(sprintf(
            'inbox/%d/file-drop/%04d/%02d/%s.eml',
            $userId,
            (int) $internalDate->format('Y'),
            (int) $internalDate->format('m'),
            $messageIdHash,
        ));
    }

    // Split out rather than inlined: put() sits one branch under the
    // analyser's ceiling with this in it, and the three failures here are one
    // question — are the bytes on disk? fwrite reports what it put in a
    // userspace buffer, so a full disk surfaces at the flush, not at the write.
    /**
     * @param  resource  $handle
     */
    private function writeEveryByteToDisk($handle, string $tmp, string $rawMime): void
    {
        $written = @fwrite($handle, $rawMime);

        if ($written === false || $written !== strlen($rawMime)) {
            throw FileDropBlobWriteException::shortWrite($tmp);
        }

        if (@fflush($handle) === false) {
            throw FileDropBlobWriteException::couldNotFlush($tmp, 'fflush');
        }

        if (function_exists('fsync') && @fsync($handle) === false) {
            throw FileDropBlobWriteException::couldNotFlush($tmp, 'fsync');
        }
    }

    public function put(string $absolutePath, string $rawMime): void
    {
        $dir = dirname($absolutePath);
        $dirExisted = $this->files->isDirectory($dir);
        $this->files->ensureDirectoryExists($dir, SecretFileMode::DIRECTORY, recursive: true);
        if (! $dirExisted && ! @chmod($dir, SecretFileMode::DIRECTORY)) {
            throw FileDropBlobWriteException::chmodDirectoryFailed($dir);
        }

        $tmp = $absolutePath.'.tmp';

        // Narrow umask BEFORE opening the temp file so it is born at
        // mode 0600 rather than the umask-0022 default of 0644 — the
        // explicit chmod below is defence-in-depth on top of that.
        $previousUmask = umask(0077);

        $fp = @fopen($tmp, 'wb');
        if ($fp === false) {
            umask($previousUmask);
            throw FileDropBlobWriteException::couldNotOpenTempFile($tmp);
        }

        try {
            @flock($fp, LOCK_EX);
            $this->writeEveryByteToDisk($fp, $tmp, $rawMime);
            @flock($fp, LOCK_UN);
            $closed = @fclose($fp);
            $fp = null;

            if ($closed === false) {
                throw FileDropBlobWriteException::couldNotFlush($tmp, 'fclose');
            }

            if (! @chmod($tmp, SecretFileMode::FILE)) {
                throw FileDropBlobWriteException::chmodTempFileFailed($tmp);
            }

            if (! @rename($tmp, $absolutePath)) {
                throw FileDropBlobWriteException::atomicRenameFailed($tmp, $absolutePath);
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
            throw FileDropBlobWriteException::unexpectedFailure($absolutePath);
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
