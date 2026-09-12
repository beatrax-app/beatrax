<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\OwnerOnlyPath;

// A 0700 directory under app storage, NEVER sys_get_temp_dir(): /tmp is
// world-traversable at 1777, and what a restore stages there is the ENTIRE
// database in clear. Both restore paths stage something now, so the directory
// and its mode are named once rather than spelled twice.
/**
 * @link ../../../../.docs/features/core/one-export-action.md#the-staging-discipline
 */
final readonly class RestoreStagingArea
{
    private const string DIRECTORY = 'tmp-restore';

    private const string PREFIX = 'beatrax-restore-';

    public function __construct(
        private OwnerOnlyPath $ownerOnly,
        private Filesystem $files,
    ) {}

    /**
     * @throws BackupIoException when the staging directory cannot be made owner-only
     */
    public function path(string $tag): string
    {
        $directory = rtrim(UserDataPathService::appPath(self::DIRECTORY), '/');

        if (! $this->ownerOnly->directory($directory)) {
            throw new BackupIoException('The restore staging directory could not be made owner-only: '.$directory);
        }

        return $directory.'/'.self::PREFIX.$tag.'-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    // A staged database is three files: the framework connection that verified
    // it left a `-wal` and a `-shm` holding pages of the same plaintext ledger,
    // so unlinking the one named file left the newest of it here. Those two
    // were swept only as a side effect of a `PRAGMA` the keyring lift ran.
    public function discard(string $path): void
    {
        $this->files->delete([$path, $path.'-wal', $path.'-shm']);
    }
}
