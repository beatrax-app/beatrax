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
 * `storage/app/inbox/{user_id}/{inbox_id}/{YYYY}/{MM}/{slug}.eml`.
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
 * `pathFor` rejects provider_message_ids whose characters fall outside
 * the URL-safe base64 + `=` + `.` + `_` + `-` set (matching the Graph
 * spec for both legacy ids and ImmutableId-prefixed values, and the
 * short hex shape Gmail returns), or that exceed 512 bytes. The slug
 * that ends up on disk is derived from a SHA-256 of the full id (32
 * hex chars) plus a sanitised prefix of the first 40 characters, with
 * `+`, `/`, `=` collapsed to `-`. Two distinct provider ids therefore
 * cannot collide on disk even on case-insensitive filesystems, while
 * the source of truth for the id itself stays the unique key on
 * (inbox_id, provider_message_id) in inbox_messages.
 */
final class EmlBlobStore
{
    /**
     * Allow-list for provider message ids. Covers both Gmail's short
     * hex shape and the Microsoft Graph URL-safe base64 shape
     * (including ImmutableId-prefixed values with `=` padding) up to
     * 512 bytes; the on-disk slug is hashed regardless so the filename
     * never depends on the raw id's case sensitivity.
     */
    private const MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._%=+\-]{1,512}$/';

    /** Parent directory mode — owner-only read/write/execute. */
    private const DIR_MODE = 0700;

    /** Blob file mode — owner-only read/write. */
    private const FILE_MODE = 0600;

    public function __construct(private readonly Filesystem $files) {}

    /**
     * Compute the absolute on-disk path for a raw .eml blob without
     * touching the filesystem. Callers use the path to pass to put()
     * or to assert against in tests.
     *
     * The resulting filename embeds a SHA-256 prefix of the provider
     * message id so distinct ids never collide on disk — including on
     * case-insensitive filesystems where two Graph ids that differ
     * only in letter case would otherwise resolve to the same path.
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
                .'[A-Za-z0-9._%=+-]{1,512}; rejected to prevent path traversal.',
            );
        }

        $slug = $this->slugFor($providerMessageId);

        return storage_path(sprintf(
            'app/inbox/%d/%d/%04d/%02d/%s.eml',
            $userId,
            $inboxId,
            (int) $internalDate->format('Y'),
            (int) $internalDate->format('m'),
            $slug,
        ));
    }

    /**
     * Derive a filesystem-safe, collision-resistant slug for the
     * provider message id. The 32-hex SHA-256 prefix is the primary
     * uniqueness guard; the appended sanitised prefix preserves some
     * human-readability when inspecting the directory tree by hand.
     */
    private function slugFor(string $providerMessageId): string
    {
        $hash = substr(hash('sha256', $providerMessageId), 0, 32);
        $readable = substr(strtr($providerMessageId, ['+' => '-', '/' => '-', '=' => '-']), 0, 40);

        return $hash.'_'.$readable;
    }

    /**
     * Write the raw .eml bytes to disk atomically. Ensures the
     * parent directory exists with mode 0700 (chmod is applied only
     * on first creation so subsequent writes do not narrow back
     * permissions an admin may have widened intentionally for
     * backup tooling), writes to a sibling `.tmp` file, fsyncs,
     * chmods to 0600, and renames over the canonical path. Any
     * failure during the write tears down the temp file and rethrows
     * as a RuntimeException.
     */
    public function put(string $absolutePath, string $rawMime): void
    {
        $dir = dirname($absolutePath);
        $dirExisted = $this->files->isDirectory($dir);
        $this->files->ensureDirectoryExists($dir, self::DIR_MODE, recursive: true);
        if (! $dirExisted) {
            // Apply chmod ONCE on first create. A failure here is
            // a hard error — the directory must be 0700 before any
            // .eml lands inside it so cohabiting OS users cannot
            // enumerate or read another user's blobs.
            if (! @chmod($dir, self::DIR_MODE)) {
                throw new RuntimeException(
                    "EmlBlobStore: failed to chmod 0700 on newly-created directory {$dir}.",
                );
            }
        }

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
