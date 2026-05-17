<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Filesystem repository for raw .eml blobs that arrived via file
 * drop (wizard upload + watched-folder secondary path), separate
 * from the EmailScan inbox blob store.
 *
 * Per-user blob duplication: the path is partitioned by `user_id` so
 * two users dropping byte-identical email files land in two distinct
 * physical files. This preserves per-user `chmod 0700` directory
 * isolation; cross-user deduplication would defeat that boundary.
 *
 * Each blob lives at
 * `storage/app/inbox/{user_id}/file-drop/{YYYY}/{MM}/{messageIdHash}.eml`
 * where `messageIdHash` is either:
 *
 *   - the RFC 822 Message-ID header value (when present), normalised
 *     to fit the allow-list; or
 *   - the sha256 hex of the full raw .eml bytes (when the Message-ID
 *     header is absent — the headerless-file fallback that keeps the
 *     UNIQUE constraint workable and preserves byte-identical
 *     re-drop idempotency).
 *
 * The directory partition is by user + year + month so any single
 * directory stays small enough for fast listings on APFS / ext4.
 *
 * Writes are atomic via the tmp + flock + fsync + chmod + rename
 * sequence. A POSIX rename is atomic, so a crash mid-write either
 * leaves the previous file in place or has not yet exposed the new
 * one — there is no in-between state where a partial .eml could be
 * observed by a reader. The parent directory is created on first
 * write with mode 0700 so cohabiting OS-level users cannot enumerate
 * another user's blobs.
 *
 * `pathFor` rejects message ids whose characters fall outside the
 * `[A-Za-z0-9._-]{1,200}` allow-list — the sha256 hex synthetic id
 * (purely [0-9a-f]) trivially satisfies it, and a sanitiser at the
 * call site normalises any provider Message-ID into the allow-list
 * before this method runs.
 */
final class FileDropEmlBlobStore
{
    /** Allow-list for message-id-derived filename components. */
    private const MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._\-]{1,200}$/';

    /** Parent directory mode — owner-only read/write/execute. */
    private const DIR_MODE = 0700;

    /** Blob file mode — owner-only read/write. */
    private const FILE_MODE = 0600;

    public function __construct(
        private readonly Filesystem $files,
        private readonly Application $app,
    ) {}

    /**
     * Compute the absolute on-disk path for a raw .eml blob without
     * touching the filesystem. Callers use the path to pass to put()
     * or to assert against in tests.
     */
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

    /**
     * Write raw .eml bytes to disk atomically. Ensures the parent
     * directory exists with mode 0700 (chmod applied only on first
     * creation so subsequent writes do not narrow permissions an
     * admin may have widened intentionally), writes to a sibling
     * `.tmp` file, fsyncs, chmods to 0600, then renames over the
     * canonical path. Any failure during the write tears down the
     * temp file and rethrows as a RuntimeException.
     */
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
        // mode 0600 rather than the umask-0022 default of 0644. The
        // sibling EmlBlobStore + OAuthSecretsRepository writers (Phase
        // 6) carry the same pattern. The explicit chmod below remains
        // as defence-in-depth — together they close both the umask
        // race and any filesystem that ignores the umask narrowing.
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
