<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\EmailScan\Public\Exceptions\EmlBlobWriteException;
use Throwable;

final class EmlBlobStore
{
    // Allow-list covering both Gmail's short hex shape and the
    // Microsoft Graph URL-safe base64 shape (including
    // ImmutableId-prefixed values with = padding) up to 512 bytes; the
    // on-disk slug is hashed regardless of the raw id's case.
    private const MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._%=+\-]{1,512}$/';

    private const DIR_MODE = 0700;

    private const FILE_MODE = 0600;

    public function __construct(
        private readonly Filesystem $files,
        private readonly UserDataPathService $paths,
    ) {}

    // Computes the absolute on-disk path for a raw .eml blob without
    // touching the filesystem, embedding a sha256 prefix of the
    // provider message id so distinct ids never collide on disk, even
    // on case-insensitive filesystems.
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

        return $this->paths->appRelative(sprintf(
            'inbox/%d/%d/%04d/%02d/%s.eml',
            $userId,
            $inboxId,
            (int) $internalDate->format('Y'),
            (int) $internalDate->format('m'),
            $slug,
        ));
    }

    // Derives a filesystem-safe, collision-resistant slug: the 32-hex
    // sha256 prefix is the primary uniqueness guard, and the appended
    // sanitised prefix preserves some human-readability when
    // inspecting the directory tree by hand.
    private function slugFor(string $providerMessageId): string
    {
        $hash = substr(hash('sha256', $providerMessageId), 0, 32);
        $readable = substr(strtr($providerMessageId, ['+' => '-', '/' => '-', '=' => '-']), 0, 40);

        return $hash.'_'.$readable;
    }

    // Writes the raw .eml bytes to disk atomically: ensures the parent
    // directory exists at 0700, writes to a sibling .tmp file, fsyncs,
    // chmods to 0600, and renames over the canonical path. Any failure
    // tears down the temp file and rethrows as an EmlBlobWriteException.
    public function put(string $absolutePath, string $rawMime): void
    {
        $dir = dirname($absolutePath);
        $this->files->ensureDirectoryExists($dir, self::DIR_MODE, recursive: true);

        // Chmods every directory level under storage/app/inbox/ to
        // 0700, since ensureDirectoryExists only chmods the leaf on
        // first creation (intermediate levels inherit umask). See
        // architecture.md for the enumeration risk this closes.
        $this->chmodInboxChain($dir);

        $tmp = $absolutePath.'.tmp';

        // Narrows umask before opening the temp file so it's born at
        // 0600 rather than the umask-0022 default of 0644 — see
        // architecture.md for why (OAuthSecretsRepository mirrors this).
        $prevUmask = umask(0077);

        $fp = @fopen($tmp, 'wb');
        if ($fp === false) {
            umask($prevUmask);
            throw new EmlBlobWriteException(
                "EmlBlobStore: could not open temp file at {$tmp}.",
            );
        }

        try {
            @flock($fp, LOCK_EX);
            $written = @fwrite($fp, $rawMime);
            if ($written === false || $written !== strlen($rawMime)) {
                throw new EmlBlobWriteException(
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
                throw new EmlBlobWriteException(
                    "EmlBlobStore: failed to chmod temp file at {$tmp}.",
                );
            }

            if (! @rename($tmp, $absolutePath)) {
                throw new EmlBlobWriteException(
                    "EmlBlobStore: atomic rename failed from {$tmp} to {$absolutePath}.",
                );
            }
        } catch (Throwable $e) {
            if (is_resource($fp)) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
            @unlink($tmp);
            if ($e instanceof EmlBlobWriteException) {
                throw $e;
            }
            throw new EmlBlobWriteException(
                "EmlBlobStore: unexpected failure writing {$absolutePath}.",
            );
        } finally {
            umask($prevUmask);
        }
    }

    // Walks from $leafDir upward and chmods each directory to 0700,
    // stopping at the storage/app/inbox/ root, so intermediate levels
    // don't inherit the default Filesystem mode and let a cohabiting
    // OS user enumerate inbox ids (see architecture.md).
    private function chmodInboxChain(string $leafDir): void
    {
        // Trailing separator on both sides of the prefix check so a
        // sibling like storage/app/inbox-staging/ cannot satisfy the
        // match — only true descendants of inbox/ are walked.
        $root = $this->paths->appRelative('inbox').DIRECTORY_SEPARATOR;
        $current = rtrim($leafDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $iters = 0;
        // Caps iterations defensively in case dirname() loops on a
        // malformed input.
        while (
            $iters++ < 32
            && str_starts_with($current, $root)
            && is_dir(rtrim($current, DIRECTORY_SEPARATOR))
        ) {
            @chmod(rtrim($current, DIRECTORY_SEPARATOR), self::DIR_MODE);
            $parent = dirname(rtrim($current, DIRECTORY_SEPARATOR));
            if ($parent === rtrim($current, DIRECTORY_SEPARATOR)) {
                break;
            }
            $current = $parent.DIRECTORY_SEPARATOR;
        }
    }

    // Removes a blob from disk, tolerating an already-absent file —
    // BackfillInboxJob's rollback path calls this in a catch block
    // where the .eml may or may not have made it to disk.
    public function delete(string $absolutePath): void
    {
        @unlink($absolutePath);
    }

    public function exists(string $absolutePath): bool
    {
        return $this->files->exists($absolutePath);
    }
}
