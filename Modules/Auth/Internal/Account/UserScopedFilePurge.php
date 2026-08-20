<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Account;

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Services\UserDataPathService;

// Erases the parts of an account that never lived in the database: the sync
// identity and group keyring, and the raw mail this device downloaded.

// Two tiers, because a household shares one device. Anything named for the
// account goes whenever that account goes. Anything the device holds once for
// everyone — the relay credentials, the backups, the staged secrets — only
// goes when the account being deleted is the last one on the device.
/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
final readonly class UserScopedFilePurge
{
    private const OWNED = [
        'sync/identity/%d.enc',
        'sync/gdk/%d.enc',
        'inbox/%d',
        'inbox-drop/%d',
    ];

    private const DEVICE_WIDE = [
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

    public function __invoke(int $userId, bool $lastAccountOnDevice): void
    {
        foreach (self::OWNED as $pattern) {
            $this->remove($this->paths->appRelative(sprintf($pattern, $userId)));
        }

        if (! $lastAccountOnDevice) {
            return;
        }

        foreach (self::DEVICE_WIDE as $relative) {
            $this->remove($this->paths->appRelative($relative));
        }
    }

    private function remove(string $path): void
    {
        if ($this->files->isDirectory($path)) {
            $this->files->deleteDirectory($path);

            return;
        }

        if ($this->files->exists($path)) {
            $this->files->delete($path);
        }
    }
}
