<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Storage;

use Modules\Core\Public\Services\UserDataPathService;

/**
 * @link ../../../../.docs/features/core/one-export-action.md
 */
final class UserDataLocations
{
    public const string DATABASE = 'database';

    public const string ARTEFACTS_IMPORTS = 'artefacts_imports';

    public const string ARTEFACTS_MAIL = 'artefacts_mail';

    public const string ARTEFACTS_DROP = 'artefacts_drop';

    public const string BACKUPS = 'backups';

    public const string SECRETS = 'secrets';

    public const string LOGS = 'logs';

    // What the export archive carries. The database rides as an encrypted
    // snapshot rather than as a copy of this directory, so DATABASE is not here.
    /** @var list<string> */
    private const array EXPORTED = [
        self::ARTEFACTS_IMPORTS,
        self::ARTEFACTS_MAIL,
        self::ARTEFACTS_DROP,
    ];

    // Connector credentials are why this list is spelled out: they sit one
    // directory from the source documents, and a sweep of the storage root
    // would put them in a file the reader then mails to themselves. The .docs
    // page carries the reasoning for the other three.
    /** @var list<string> */
    private const array WITHHELD = [
        self::DATABASE,
        self::BACKUPS,
        self::SECRETS,
        self::LOGS,
    ];

    // One inventory with three readers: the page that shows where the data is,
    // the deletion procedure that has to name every path, and the export that
    // bundles them. A location added here reaches all three, or none.
    /**
     * @return array<string, string> location key => resolved absolute path
     */
    public static function all(): array
    {
        return [
            self::DATABASE => UserDataPathService::databaseFile(),
            self::ARTEFACTS_IMPORTS => UserDataPathService::appPath('private/imports'),
            self::ARTEFACTS_MAIL => UserDataPathService::appPath('inbox'),
            self::ARTEFACTS_DROP => UserDataPathService::appPath('inbox-drop'),
            self::BACKUPS => UserDataPathService::backupsPath(),
            self::SECRETS => UserDataPathService::secretsPath(),
            self::LOGS => UserDataPathService::logsDirectory(),
        ];
    }

    // The source documents the reader handed the application. These live
    // outside the backup, which is why the export bundles them alongside it
    // rather than trusting the snapshot to carry them.
    /**
     * @return array<string, string> location key => resolved absolute path
     */
    public static function artefacts(): array
    {
        return array_intersect_key(self::all(), array_flip(self::EXPORTED));
    }

    // Named one by one rather than left as "whatever artefacts() did not take".
    // The export is a copy with a boundary, and the packagers' own boundary was
    // once inferred instead of stated — which is how a shipped bundle came to
    // carry the signing key that made it.
    /**
     * @return array<string, string> location key => resolved absolute path
     */
    public static function withheldFromExport(): array
    {
        return array_intersect_key(self::all(), array_flip(self::WITHHELD));
    }

    // WAL mode keeps recent commits in `-wal` until a checkpoint, so a copy
    // taken without the journal files is a copy missing the newest
    // transactions. The deletion procedure names them for the same reason.
    /**
     * @return list<string>
     */
    public static function databaseFiles(): array
    {
        $database = UserDataPathService::databaseFile();

        return [$database, $database.'-wal', $database.'-shm'];
    }
}
