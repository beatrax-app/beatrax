<?php

declare(strict_types=1);

use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\RestoreEncryptedBackup;
use Tests\Helpers\LiveSqliteConnection;

// SQLite here runs in WAL mode, so the live file is only half the database:
// the `-wal` sidecar holds every page written since the last checkpoint. That
// sidecar is only folded back in and unlinked when the LAST connection to the
// file closes, and a restore never has that. `php artisan down` refuses HTTP
// requests; it does not close the desktop server's own handle, and it does not
// stop the sync daemon or the queue worker at all.
//
// Copy the source over the main file with a sidecar still beside it and the
// next reader recovers the old write-ahead log on top of the restored pages.
// The restore reports success, the post-swap integrity check passes, and not
// one byte of the backup survives.

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/held-'.bin2hex(random_bytes(6));
    @mkdir($this->dir, 0700, true);
    $this->live = $this->dir.'/live.sqlite';
    $this->source = $this->dir.'/source.sqlite';
});

afterEach(function (): void {
    LiveSqliteConnection::restore($this->app);

    /** @var string $dir */
    $dir = $this->dir;
    foreach ((array) glob($dir.'/*') as $entry) {
        @unlink((string) $entry);
    }
    @rmdir($dir);
});

// Held open for the whole restore, which is what a second process is: the
// sidecar cannot be checkpointed away underneath it.
function heldWalDatabase(string $path): PDO
{
    $held = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $held->exec('PRAGMA journal_mode = WAL');
    $held->exec('CREATE TABLE marker (val TEXT)');
    $held->exec("INSERT INTO marker (val) VALUES ('ORIGINAL')");

    // Enough pages that SQLite has not auto-checkpointed them back into the
    // main file, so the sidecar genuinely holds the newest state.
    for ($row = 0; $row < 500; $row++) {
        $held->exec("INSERT INTO marker (val) VALUES ('pad-{$row}')");
    }

    expect(is_file($path.'-wal'))->toBeTrue('The fixture did not produce a write-ahead log to restore over.');

    return $held;
}

function markerDatabase(string $path, string $marker): void
{
    $pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE marker (val TEXT)');
    $pdo->exec("INSERT INTO marker (val) VALUES ('{$marker}')");
}

/**
 * @return list<string>
 */
function markersIn(string $path): array
{
    /** @var list<string> $rows */
    $rows = (new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))
        ->query('SELECT val FROM marker')
        ->fetchAll(PDO::FETCH_COLUMN);

    return $rows;
}

it('db:restore actually restores when a second reader still holds the database', function (): void {
    /** @var string $live */
    $live = $this->live;
    /** @var string $source */
    $source = $this->source;

    $held = heldWalDatabase($live);
    markerDatabase($source, 'RESTORED');

    LiveSqliteConnection::pointAt($this->app, $live);

    $this->artisan('db:restore', ['path' => $source, '--confirm' => true, '--force-maintenance' => true])
        ->assertExitCode(0);

    // The reader that was holding it goes away, exactly as the app is closed
    // after a restore. Whatever it left behind is what the next launch reads.
    unset($held);

    expect(markersIn($live))->toBe(['RESTORED']);
});

it('an encrypted restore survives the same held reader', function (): void {
    /** @var string $live */
    $live = $this->live;
    /** @var string $source */
    $source = $this->source;

    $held = heldWalDatabase($live);
    markerDatabase($source, 'RESTORED');
    (new BackupEncryptor)->encrypt($source, $source.'.enc', 'a-good-passphrase');

    LiveSqliteConnection::pointAt($this->app, $live);

    app(RestoreEncryptedBackup::class)($source.'.enc', 'a-good-passphrase');

    unset($held);

    expect(markersIn($live))->toBe(['RESTORED']);
});
