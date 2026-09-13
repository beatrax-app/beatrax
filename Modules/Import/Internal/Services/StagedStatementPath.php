<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Modules\Core\Models\User;

// `import_runs.raw_file_path` is a required audit string, not a handle. It is a
// synced column, so a peer writes it against ITS filesystem; and five writers
// put a sentinel there for a run nobody uploaded. Every re-read asks here, and
// gets this device's own staged copy or nothing.
final readonly class StagedStatementPath
{
    public const string DISK = 'local';

    private const string PREFIX = 'imports';

    public function __construct(private StorageFactory $storage) {}

    // The one spelling of where a staged statement lives, so the writer and
    // the containment check below cannot drift apart.
    public static function directoryFor(int $userId): string
    {
        return self::PREFIX.'/'.$userId;
    }

    // Null rather than a refusal: a run this device did not stage is the
    // ordinary state of a window a peer fetched or an upload another device
    // made, and the caller's answer to both is to skip the re-read.
    public function forRun(string $rawFilePath, User $user): ?string
    {
        if ($rawFilePath === '' || RemoteFetchPath::isRemote($rawFilePath)) {
            return null;
        }

        $directory = realpath($this->storage->disk(self::DISK)->path(self::directoryFor($user->id)));
        $resolved = realpath($rawFilePath);

        if ($directory === false || $resolved === false || ! is_file($resolved)) {
            return null;
        }

        // realpath() resolves the symlinks and the `..` segments first, so the
        // prefix test is about the directory the read would actually reach.
        // The separator is part of the needle: `imports/1` must not admit
        // `imports/10`.
        return str_starts_with($resolved, $directory.DIRECTORY_SEPARATOR) ? $resolved : null;
    }
}
