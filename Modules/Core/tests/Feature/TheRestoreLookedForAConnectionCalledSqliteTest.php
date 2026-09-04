<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\RestoreEncryptedBackup;
use Tests\Helpers\CheapKdfCost;

// The desktop shell registers its database under the connection name
// `nativephp` and makes that the default; the connection literally called
// `sqlite` is left pointing at an empty file the app never opens. Restore
// asked for that literal name, so on the one platform the feature ships for
// it answered "Restore is only available on the SQLite build" — and had the
// guard passed, it would have written the backup over the empty file and left
// the real ledger alone. Every existing test set the default to `sqlite`,
// which is the one arrangement the desktop never has.

function shellSqlite(string $path, string $marker): void
{
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE marker (val TEXT)');
    $pdo->exec("INSERT INTO marker (val) VALUES ('".$marker."')");
}

function shellMarker(string $path): string
{
    return (string) (new PDO('sqlite:'.$path))->query('SELECT val FROM marker')->fetchColumn();
}

it('restores into the database the shell actually opened', function (): void {
    $base = sys_get_temp_dir().'/shell-'.bin2hex(random_bytes(5));
    $live = $base.'-live.sqlite';
    $decoy = $base.'-decoy.sqlite';
    $plain = $base.'-backup.sqlite';
    $enc = $base.'-backup.sqlite.enc';

    shellSqlite($live, 'ORIGINAL');
    shellSqlite($decoy, 'NEVER-OPENED');
    shellSqlite($plain, 'RESTORED');
    (new BackupEncryptor(new CheapKdfCost))->encrypt($plain, $enc, 'pw');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // The arrangement the desktop boots into, verbatim.
    Config::set('database.connections.nativephp', [
        'driver' => 'sqlite',
        'database' => $live,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    Config::set('database.connections.sqlite.database', $decoy);
    Config::set('database.default', 'nativephp');
    $db->purge('nativephp');

    try {
        $snapshot = app(RestoreEncryptedBackup::class)($enc, 'pw');
    } finally {
        Config::set('database.default', 'sqlite_testing');
        $db->purge('nativephp');
    }

    expect(shellMarker($live))->toBe('RESTORED')
        ->and(shellMarker($decoy))->toBe('NEVER-OPENED')
        ->and(shellMarker($snapshot))->toBe('ORIGINAL');

    foreach ([$live, $decoy, $plain, $enc, $snapshot] as $f) {
        @unlink($f);
    }
});

it('still refuses when the default connection is not SQLite at all', function (): void {
    $base = sys_get_temp_dir().'/shell-'.bin2hex(random_bytes(5));
    $plain = $base.'-backup.sqlite';
    $enc = $base.'-backup.sqlite.enc';
    shellSqlite($plain, 'RESTORED');
    (new BackupEncryptor(new CheapKdfCost))->encrypt($plain, $enc, 'pw');

    Config::set('database.default', 'pgsql');

    try {
        expect(fn () => app(RestoreEncryptedBackup::class)($enc, 'pw'))
            ->toThrow(RuntimeException::class);
    } finally {
        Config::set('database.default', 'sqlite_testing');
    }

    foreach ([$plain, $enc] as $f) {
        @unlink($f);
    }
});
