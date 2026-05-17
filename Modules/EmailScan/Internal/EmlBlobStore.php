<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Filesystem repository for per-message raw .eml blobs.
 *
 * Each blob lives at
 * `storage/app/inbox/{user_id}/{inbox_id}/{YYYY}/{MM}/{provider_message_id}.eml`.
 * Partitioning by user + inbox + year + month keeps any one directory
 * tree from accumulating thousands of files (which slows directory
 * listings on the underlying APFS / ext4 host) and lets a future
 * archive job tar up an entire month at a time without touching the
 * rest.
 *
 * Writes are atomic via the tmp + flock + fsync + chmod + rename
 * sequence: open a sibling `.tmp` file, write the raw RFC 822 bytes,
 * fflush + fsync (where the runtime supports it), chmod to 0600, then
 * rename over the canonical path. A POSIX rename is atomic, so a
 * crash mid-write either leaves the previous file in place or has not
 * yet exposed the new one — there is no in-between state where a
 * partial .eml could be observed by a reader. The parent directory is
 * created on first write with mode 0700 so cohabiting OS-level users
 * cannot enumerate or read another user's blobs.
 *
 * `pathFor` rejects provider_message_ids that contain anything other
 * than `[A-Za-z0-9._-]` (1..200 chars) — a defence against directory-
 * traversal payloads landing in the path segment from a crafted API
 * response. Gmail message ids are short hex; Microsoft Graph ids are
 * base64url with a long prefix. Both fit the allow-list.
 */
final class EmlBlobStore
{
    /** Allow-list for provider message-id path segments. */
    private const MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._-]{1,200}$/';

    /** Parent directory mode — owner-only read/write/execute. */
    private const DIR_MODE = 0700;

    /** Blob file mode — owner-only read/write. */
    private const FILE_MODE = 0600;

    public function __construct(private readonly Filesystem $files) {}

    /**
     * Compute the absolute on-disk path for a raw .eml blob without
     * touching the filesystem. Callers use the path to pass to put()
     * or to assert against in tests.
     */
    public function pathFor(
        int $userId,
        int $inboxId,
        DateTimeImmutable $internalDate,
        string $providerMessageId,
    ): string {
        if (preg_match(self::MESSAGE_ID_PATTERN, $providerMessageId) !== 1) {
            throw new InvalidArgumentException(
                'EmlBlobStore: provider_message_id must match '
                .'[A-Za-z0-9._-]{1,200}; rejected to prevent path traversal.',
            );
        }

        return storage_path(sprintf(
            'app/inbox/%d/%d/%04d/%02d/%s.eml',
            $userId,
            $inboxId,
            (int) $internalDate->format('Y'),
            (int) $internalDate->format('m'),
            $providerMessageId,
        ));
    }

    /**
     * Write the raw .eml bytes to disk atomically. Ensures the
     * parent directory exists with mode 0700, writes to a sibling
     * `.tmp` file, fsyncs, chmods to 0600, and renames over the
     * canonical path. Any failure during the write tears down the
     * temp file and rethrows as a RuntimeException.
     */
    public function put(string $absolutePath, string $rawMime): void
    {
        $dir = dirname($absolutePath);
        $this->files->ensureDirectoryExists($dir, self::DIR_MODE, recursive: true);
        @chmod($dir, self::DIR_MODE);

        $tmp = $absolutePath.'.tmp';
        $fp = @fopen($tmp, 'wb');
        if ($fp === false) {
            throw new RuntimeException(
                "EmlBlobStore: could not open temp file at {$tmp}.",
            );
        }

        try {
            @flock($fp, LOCK_EX);
            $written = @fwrite($fp, $rawMime);
            if ($written === false || $written !== strlen($rawMime)) {
                throw new RuntimeException(
                    "EmlBlobStore: short write to temp file at {$tmp}.",
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
                    "EmlBlobStore: failed to chmod temp file at {$tmp}.",
                );
            }

            if (! @rename($tmp, $absolutePath)) {
                throw new RuntimeException(
                    "EmlBlobStore: atomic rename failed from {$tmp} to {$absolutePath}.",
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
                "EmlBlobStore: unexpected failure writing {$absolutePath}.",
            );
        }
    }

    /**
     * Remove a blob from disk. Tolerates an already-absent file —
     * the rollback path in BackfillInboxJob calls this in a catch
     * block where the .eml may or may not have made it to disk
     * before the failing DB transaction.
     */
    public function delete(string $absolutePath): void
    {
        @unlink($absolutePath);
    }

    public function exists(string $absolutePath): bool
    {
        return $this->files->exists($absolutePath);
    }
}
