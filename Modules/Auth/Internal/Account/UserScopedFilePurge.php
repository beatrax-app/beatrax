<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Account;

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Services\UserDataPurgePlan;
use Throwable;

// The parts of an account that never lived in the database: the sync identity
// and group keyring, the connector secret, the raw mail this device downloaded
// and the statements the reader imported.

// Two tiers, because a household shares one device. What is named for the
// account goes with it; what the device holds once for everyone goes only
// when the account being deleted is the last one on it.

// Split a second way, by what a peer could put the account back from. The keyed
// set is the unlinks a deletion is not finished without; the rest is bulk whose
// survival is disclosure rather than a way back in. Both splits are read off
// Core's inventory rather than listed a second time here.
/**
 * @link ../../../../.docs/features/core/one-export-action.md#the-boundary-is-a-list-not-a-sweep
 */
final readonly class UserScopedFilePurge
{
    public function __construct(
        private Filesystem $files,
        private UserDataPurgePlan $plan,
    ) {}

    // Named rather than thrown, and that is the whole difference between the
    // two tiers now: both run past the commit, where there is no transaction
    // left for a throw to roll back. What the caller does with a name it did
    // not want to see is the caller's, and it is not "nothing was changed".
    /** @return list<string> the app-relative paths still on disk afterwards */
    public function keyedToTheAccount(int $userId): array
    {
        return $this->removeAll($this->scoped($userId, keyMaterial: true));
    }

    /** @return list<string> the app-relative paths still on disk afterwards */
    public function residue(int $userId, bool $lastAccountOnDevice): array
    {
        $paths = $this->scoped($userId, keyMaterial: false);

        if ($lastAccountOnDevice) {
            foreach ($this->plan->deviceWide() as $locationPaths) {
                $paths = [...$paths, ...$locationPaths];
            }
        }

        return $this->removeAll($paths);
    }

    // The account's own paths, split on whether the location holds material no
    // peer can reproduce. A location in neither tier reaches no deletion at
    // all, which is what a guard over the inventory is there to catch.
    /**
     * @return list<string>
     */
    private function scoped(int $userId, bool $keyMaterial): array
    {
        $keyed = $this->plan->keyMaterialLocations();
        $paths = [];

        foreach ($this->plan->forAccount($userId) as $location => $locationPaths) {
            if (in_array($location, $keyed, true) === $keyMaterial) {
                $paths = [...$paths, ...$locationPaths];
            }
        }

        return $paths;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string> the paths still on disk afterwards
     */
    private function removeAll(array $paths): array
    {
        $survivors = [];

        foreach ($paths as $path) {
            if (! $this->remove($path)) {
                $survivors[] = $path;
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
