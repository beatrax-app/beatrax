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

    public const string ARTIFACTS_IMPORTS = 'artifacts_imports';

    public const string ARTIFACTS_MAIL = 'artifacts_mail';

    public const string ARTIFACTS_DROP = 'artifacts_drop';

    public const string BACKUPS = 'backups';

    public const string SECRETS = 'secrets';

    public const string LOGS = 'logs';

    public const string KEY_MATERIAL = 'key_material';

    public const string EXPORT_STAGING = 'export_staging';

    public const string MIGRATION_EXTRACTS = 'migration_extracts';

    public const string OPEN_BANKING_TLS = 'open_banking_tls';

    // What the export archive carries. The database rides as an encrypted
    // snapshot rather than as a copy of this directory, so DATABASE is not here.
    /** @var list<string> */
    private const array EXPORTED = [
        self::ARTIFACTS_IMPORTS,
        self::ARTIFACTS_MAIL,
        self::ARTIFACTS_DROP,
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
        self::KEY_MATERIAL,
        self::EXPORT_STAGING,
        self::MIGRATION_EXTRACTS,
        self::OPEN_BANKING_TLS,
    ];

    // What one account owns inside each location. The deletion reads this; a
    // location holding per-account files and naming none of them here is a
    // location a deletion walks past.
    /** @var array<string, list<string>> location key => app-relative template */
    private const array ACCOUNT_SCOPED = [
        self::ARTIFACTS_IMPORTS => ['private/imports/%d'],
        self::ARTIFACTS_MAIL => ['inbox/%d'],
        self::ARTIFACTS_DROP => ['inbox-drop/%d'],
        self::SECRETS => ['secrets/open-banking/%d.json'],
        self::KEY_MATERIAL => ['sync/identity/%d.enc', 'sync/gdk/%d.enc'],
    ];

    // The trees that go when the last account on the device does.
    /** @var array<string, list<string>> location key => app-relative path */
    private const array DEVICE_WIDE = [
        self::ARTIFACTS_IMPORTS => ['private/imports'],
        self::ARTIFACTS_MAIL => ['inbox'],
        self::ARTIFACTS_DROP => ['inbox-drop'],
        self::SECRETS => ['secrets'],
        self::KEY_MATERIAL => ['sync'],
        self::BACKUPS => ['backups'],
        self::EXPORT_STAGING => ['tmp-backups'],
        self::MIGRATION_EXTRACTS => ['migration-extracts'],
        self::OPEN_BANKING_TLS => ['open-banking-tls'],
    ];

    // Locations with nothing one account owns, declined by name. A backup and a
    // staged export are each the whole database; an extract directory is named
    // for its run and the certificate is one per device. Declining explicitly
    // is what makes a location holding per-account files and naming none fail.
    /** @var list<string> */
    private const array NO_ACCOUNT_SCOPE = [
        self::BACKUPS,
        self::EXPORT_STAGING,
        self::MIGRATION_EXTRACTS,
        self::OPEN_BANKING_TLS,
    ];

    // Two no file purge removes. The database goes as rows inside the deletion's
    // own transaction, and its file outlives every account because the next one
    // onboards into it. The log is the record OF the deletion: it carries the
    // residue report, and the process writing it still holds the handle.
    /** @var list<string> */
    private const array NOT_REMOVED_BY_A_FILE_PURGE = [
        self::DATABASE,
        self::LOGS,
    ];

    // Key material is the half a deletion is not finished without: a peer can
    // put rows back, and it cannot put these back. The rest is bulk whose
    // survival is disclosure rather than a route in.
    /** @var list<string> */
    private const array KEY_MATERIAL_LOCATIONS = [
        self::KEY_MATERIAL,
        self::SECRETS,
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
            self::ARTIFACTS_IMPORTS => UserDataPathService::appPath('private/imports'),
            self::ARTIFACTS_MAIL => UserDataPathService::appPath('inbox'),
            self::ARTIFACTS_DROP => UserDataPathService::appPath('inbox-drop'),
            self::BACKUPS => UserDataPathService::backupsPath(),
            self::SECRETS => UserDataPathService::secretsPath(),
            self::LOGS => UserDataPathService::logsDirectory(),
            self::KEY_MATERIAL => UserDataPathService::appPath('sync'),
            self::EXPORT_STAGING => UserDataPathService::appPath('tmp-backups'),
            self::MIGRATION_EXTRACTS => UserDataPathService::appPath('migration-extracts'),
            self::OPEN_BANKING_TLS => UserDataPathService::appPath('open-banking-tls'),
        ];
    }

    /**
     * @return array<string, list<string>> location key => absolute paths this account owns
     */
    public static function accountScoped(int $userId): array
    {
        $resolved = [];

        foreach (self::ACCOUNT_SCOPED as $key => $templates) {
            $resolved[$key] = array_map(
                static fn (string $template): string => UserDataPathService::appPath(sprintf($template, $userId)),
                $templates,
            );
        }

        return $resolved;
    }

    /**
     * @return array<string, list<string>> location key => absolute paths that go with the last account
     */
    public static function deviceWide(): array
    {
        $resolved = [];

        foreach (self::DEVICE_WIDE as $key => $paths) {
            $resolved[$key] = array_map(
                static fn (string $path): string => UserDataPathService::appPath($path),
                $paths,
            );
        }

        return $resolved;
    }

    /**
     * @return array<string, string> location key => resolved absolute path
     */
    public static function withoutAnAccountScope(): array
    {
        return array_intersect_key(self::all(), array_flip(self::NO_ACCOUNT_SCOPE));
    }

    /**
     * @return array<string, string> location key => resolved absolute path
     */
    public static function notRemovedByAFilePurge(): array
    {
        return array_intersect_key(self::all(), array_flip(self::NOT_REMOVED_BY_A_FILE_PURGE));
    }

    // Answered as location keys rather than paths, because the caller asks it
    // of both the account-scoped set and the device-wide one.
    /**
     * @return list<string>
     */
    public static function keyMaterialLocations(): array
    {
        return self::KEY_MATERIAL_LOCATIONS;
    }

    // The source documents the reader handed the application. These live
    // outside the backup, which is why the export bundles them alongside it
    // rather than trusting the snapshot to carry them.
    /**
     * @return array<string, string> location key => resolved absolute path
     */
    public static function artifacts(): array
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
