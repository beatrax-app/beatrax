<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Account;

use Illuminate\Filesystem\Filesystem;
use Modules\Auth\Internal\Exceptions\AccountPurgeException;
use Modules\Core\Public\Services\UserDataPathService;
use Throwable;

// The parts of an account that never lived in the database: the sync identity
// and group keyring, the connector secret, and the raw mail this device
// downloaded.

// Two tiers, because a household shares one device. What is named for the
// account goes with it; what the device holds once for everyone goes only
// when the account being deleted is the last one on it.

// Split a second way, by what a peer could put the account back from. The
// keyed set is three unlinks and is what the deletion may not be reported
// without; the rest is bulk whose survival is residue, not a way back in.
final readonly class UserScopedFilePurge
{
    private const array KEYED_TO_THE_ACCOUNT = [
        'sync/identity/%d.enc',
        'sync/gdk/%d.enc',
        'secrets/open-banking/%d.json',
    ];

    private const array MAIL = [
        'inbox/%d',
        'inbox-drop/%d',
    ];

    private const array DEVICE_WIDE = [
        'secrets',
        'backups',
        'tmp-backups',
        'sync',
        'open-banking-tls',
        'migration-extracts',
        'inbox',
        'inbox-drop',
    ];

    public function __construct(
        private Filesystem $files,
        private UserDataPathService $paths,
    ) {}

    /**
     * @throws AccountPurgeException when any of them is still on disk afterwards
     */
    public function keyedToTheAccount(int $userId): void
    {
        $survivors = [];

        foreach (self::KEYED_TO_THE_ACCOUNT as $pattern) {
            $relative = sprintf($pattern, $userId);

            if (! $this->remove($this->paths->appRelative($relative))) {
                $survivors[] = $relative;
            }
        }

        if ($survivors !== []) {
            throw AccountPurgeException::keyMaterialSurvived($survivors, $userId);
        }
    }

    /** @return list<string> the app-relative paths still on disk afterwards */
    public function residue(int $userId, bool $lastAccountOnDevice): array
    {
        $patterns = self::MAIL;

        if ($lastAccountOnDevice) {
            $patterns = array_merge($patterns, self::DEVICE_WIDE);
        }

        $survivors = [];

        foreach ($patterns as $pattern) {
            $relative = str_contains($pattern, '%d') ? sprintf($pattern, $userId) : $pattern;

            if (! $this->remove($this->paths->appRelative($relative))) {
                $survivors[] = $relative;
            }
        }

        return $survivors;
    }

    // Each path answers for itself. One that threw used to abandon every path
    // after it, and the identity was only ever first in the list by luck.
    private function remove(string $path): bool
    {
        try {
            return $this->removeAndVerify($path);
        } catch (Throwable) {
            return false;
        }
    }

    // `delete()` and `deleteDirectory()` report a refused unlink by returning
    // false and never by throwing, and that return value was discarded -- so
    // the ordinary failure was not swallowed, it was never noticed.
    private function removeAndVerify(string $path): bool
    {
        if ($this->files->isDirectory($path)) {
            $this->files->deleteDirectory($path);
        } elseif ($this->files->exists($path)) {
            $this->files->delete($path);
        }

        return ! $this->stillThere($path);
    }

    // The filesystem's own answer rather than the seam's, because it is the
    // one the readers of these paths get: GdkKeyringService opens the keyring
    // with a bare file_exists(), and stat results are cached per request.
    private function stillThere(string $path): bool
    {
        clearstatcache(true, $path);

        return file_exists($path);
    }
}
