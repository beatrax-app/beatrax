<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Modules\Core\Public\Services\UserDataPathService;

// The GDK keyring holds the only copy of the keys that open a user's sealed
// columns, and it lives beside the database rather than inside it. The set of
// files that must travel with a database is named here rather than re-spelled
// by each mover: three string copies of this path existed before it did.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#a-backup-of-the-database-alone-is-a-backup-of-ciphertext
 */
final class PortableKeyMaterial
{
    private const string KEYRING_DIRECTORY = 'sync/gdk';

    private const string KEYRING_EXTENSION = '.enc';

    public function keyringDirectory(): string
    {
        return UserDataPathService::appPath(self::KEYRING_DIRECTORY);
    }

    public function keyringPath(int $userId): string
    {
        return UserDataPathService::appPath(self::KEYRING_DIRECTORY.'/'.$userId.self::KEYRING_EXTENSION);
    }

    // Keyed by user id rather than returned as paths, because the consumer has
    // to write each one back under the id it belongs to on a machine whose own
    // storage root is somewhere else entirely.
    /** @return array<int, string> user id => absolute keyring path, for every keyring on disk */
    public function keyrings(): array
    {
        $found = [];

        foreach ((array) glob($this->keyringDirectory().'/*'.self::KEYRING_EXTENSION) as $path) {
            if (! is_string($path) || ! is_file($path)) {
                continue;
            }

            $stem = basename($path, self::KEYRING_EXTENSION);
            if (preg_match('/^\d+$/', $stem) !== 1) {
                continue;
            }

            $found[(int) $stem] = $path;
        }

        ksort($found);

        return $found;
    }
}
