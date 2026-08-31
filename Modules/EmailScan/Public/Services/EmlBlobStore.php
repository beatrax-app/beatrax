<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\SecretFileMode;
use Modules\EmailScan\Public\Exceptions\EmlBlobWriteException;
use Throwable;

final readonly class EmlBlobStore
{
    // Covers Gmail's short hex and Graph's URL-safe base64, whose
    // ImmutableId values carry `=` padding.
    private const string MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._%=+\-]{1,512}$/';

    private const int MAX_PARENT_HOPS = 32;

    public function __construct(
        private Filesystem $files,
        private UserDataPathService $paths,
    ) {}

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

    // The 32-hex sha256 prefix is the uniqueness guard — a case-insensitive
    // filesystem would otherwise collapse two ids onto one .eml. The
    // sanitised suffix only exists to make the tree readable by hand.
    private function slugFor(string $providerMessageId): string
    {
        $hash = substr(hash('sha256', $providerMessageId), 0, 32);
        $readable = substr(strtr($providerMessageId, ['+' => '-', '/' => '-', '=' => '-']), 0, 40);

        return $hash.'_'.$readable;
    }

    // Atomic: a reader must never see a partial .eml, and the bytes must
    // never be briefly world-readable.
    /** @throws EmlBlobWriteException */
    public function put(string $absolutePath, string $rawMime): void
    {
        $dir = dirname($absolutePath);
        $this->files->ensureDirectoryExists($dir, SecretFileMode::DIRECTORY, recursive: true);

        // ensureDirectoryExists only chmods the leaf, so the per-user and
        // per-inbox levels would inherit umask 0755 and let a cohabiting OS
        // user enumerate inbox ids with `ls`.
        $this->chmodInboxChain($dir);

        $tmp = $absolutePath.'.tmp';

        // Narrowed so the temp file is born 0600 rather than the umask-0022
        // default of 0644.
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

            if (! @chmod($tmp, SecretFileMode::FILE)) {
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
                previous: $e,
            );
        } finally {
            umask($prevUmask);
        }
    }

    private function chmodInboxChain(string $leafDir): void
    {
        // Trailing separators on both sides so a sibling like
        // inbox-staging/ cannot satisfy the prefix match.
        $root = $this->paths->appRelative('inbox').DIRECTORY_SEPARATOR;
        $current = rtrim($leafDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $iters = 0;
        while (
            $iters++ < self::MAX_PARENT_HOPS
            && str_starts_with($current, $root)
            && is_dir(rtrim($current, DIRECTORY_SEPARATOR))
        ) {
            @chmod(rtrim($current, DIRECTORY_SEPARATOR), SecretFileMode::DIRECTORY);
            $parent = dirname(rtrim($current, DIRECTORY_SEPARATOR));
            if ($parent === rtrim($current, DIRECTORY_SEPARATOR)) {
                break;
            }
            $current = $parent.DIRECTORY_SEPARATOR;
        }
    }

    // Tolerates an already-absent file: BackfillInboxJob's rollback calls
    // this where the .eml may or may not have landed.
    public function delete(string $absolutePath): void
    {
        @unlink($absolutePath);
    }

    public function exists(string $absolutePath): bool
    {
        return $this->files->exists($absolutePath);
    }
}
