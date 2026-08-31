<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Support;

use Modules\Core\Public\Exceptions\BackupIoException;

// The .meta.json written beside every backup, and the only record the next
// run has to decide the database is unchanged since the last one. It reads
// and writes the file itself rather than through the Filesystem, because
// every return has to be checked before a skip can be believed.
final class BackupSidecar
{
    public const string SUFFIX = '.meta.json';

    // Missing or unreadable sidecars must fall through to "not skippable":
    // a wrong skip silently writes no backup at all.
    public function recordsDigest(string $backupsDir, string $digest): bool
    {
        $newest = $this->newestPath($backupsDir);
        if ($newest === null) {
            return false;
        }

        $decoded = json_decode((string) file_get_contents($newest), true);
        $stored = is_array($decoded) ? ($decoded['content_sha256'] ?? null) : null;

        // A sidecar written before this field existed carries none, and a
        // backup nobody can date is one to redo rather than skip.
        return is_string($stored) && $stored === $digest;
    }

    // umask + tmp + rename + chmod, the same order the OAuth secrets file is
    // written in. Every I/O return is checked so a disk-full or cross-device
    // failure raises instead of leaving a half-written or world-readable
    // sidecar.
    /**
     * @throws BackupIoException
     */
    public function write(string $destination, string $digest, string $startedAt, string $completedAt): void
    {
        $sidecar = $destination.self::SUFFIX;
        $tmp = $sidecar.'.tmp';

        $payload = json_encode([
            'content_sha256' => $digest,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'integrity' => 'ok',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $prevUmask = umask(0o077);
        try {
            // @-suppressed so the `=== false` checks decide: unsuppressed,
            // Laravel's handler turns each E_WARNING into an ErrorException
            // before the comparison runs, which the caller's catch misses.
            if (@file_put_contents($tmp, $payload) === false) {
                throw new BackupIoException('Failed to write backup sidecar tmp file at '.$tmp);
            }
            if (@chmod($tmp, 0o600) === false) {
                throw new BackupIoException('Failed to chmod sidecar tmp file at '.$tmp.' to 0600.');
            }
            if (@rename($tmp, $sidecar) === false) {
                throw new BackupIoException('Failed to rename sidecar tmp file to '.$sidecar.'.');
            }
            // rename() preserves the tmp file's mode on every common filesystem,
            // so this re-chmod is belt-and-braces and its failure is non-fatal.
            @chmod($sidecar, 0o600);
        } finally {
            umask($prevUmask);
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    // Sorts basenames, not full paths, so a directory-shape change cannot flip
    // the winner: the fixed `beatrax-` + zero-padded timestamp + suffix makes
    // strcmp DESC equal newest-first.
    private function newestPath(string $backupsDir): ?string
    {
        $candidates = glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'.self::SUFFIX);
        if ($candidates === false || $candidates === []) {
            return null;
        }

        usort($candidates, static fn (string $a, string $b): int => strcmp(basename($b), basename($a)));
        $newest = $candidates[0];

        return is_file($newest) ? $newest : null;
    }
}
