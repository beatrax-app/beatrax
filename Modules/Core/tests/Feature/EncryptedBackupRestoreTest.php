<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\EncryptedBackupRestore;
use Modules\Core\Models\User;
use Modules\Core\Public\Exceptions\BackupFormatException;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\RestoreEncryptedBackup;

beforeEach(function (): void {
    Storage::fake('livewire-tmp');
    $this->user = User::query()->create([
        'username' => 'restore-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('renders the restore section', function (): void {
    Livewire::test(EncryptedBackupRestore::class)
        ->assertOk()
        ->assertSee('Restore from a backup');
});

it('refuses without the typed confirmation phrase', function (): void {
    Livewire::test(EncryptedBackupRestore::class)
        ->set('backup', UploadedFile::fake()->create('backup.enc', 4))
        ->set('passphrase', 'secret')
        ->set('confirmation', 'nope')
        ->call('restore')
        ->assertSet('snapshotPath', '')
        ->assertSet('error', 'Type RESTORE to confirm — this replaces your current data.');
});

it('refuses without an uploaded file', function (): void {
    Livewire::test(EncryptedBackupRestore::class)
        ->set('passphrase', 'secret')
        ->set('confirmation', 'RESTORE')
        ->call('restore')
        ->assertSet('error', 'Choose an encrypted backup file (.enc) to restore.');
});

it('refuses without a passphrase', function (): void {
    Livewire::test(EncryptedBackupRestore::class)
        ->set('backup', UploadedFile::fake()->create('backup.enc', 4))
        ->set('confirmation', 'RESTORE')
        ->set('passphrase', '')
        ->call('restore')
        ->assertSet('error', 'Enter the passphrase the backup was encrypted with.');
});

it('passes a fully-gated request to the SQLite-guarded restore service', function (): void {
    // The gate is satisfied, so the call reaches RestoreEncryptedBackup, whose own
    // guard refuses because the test connection is not 'sqlite'. The destructive
    // swap is never reached, and the wiring and the guard are both shown.
    Livewire::test(EncryptedBackupRestore::class)
        ->set('backup', UploadedFile::fake()->create('backup.enc', 4))
        ->set('passphrase', 'secret')
        ->set('confirmation', 'RESTORE')
        ->call('restore')
        ->assertSet('snapshotPath', '')
        ->assertSet('error', 'Restore is only available on the SQLite build.');
});

function rbMakeSqlite(string $path, string $marker): void
{
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE marker (val TEXT)');
    $pdo->exec("INSERT INTO marker (val) VALUES ('".$marker."')");
}

function rbReadMarker(string $path): string
{
    return (string) (new PDO('sqlite:'.$path))->query('SELECT val FROM marker')->fetchColumn();
}

it('decrypts, integrity-checks, snapshots, then swaps the live SQLite file', function (): void {
    $base = sys_get_temp_dir().'/rb-'.bin2hex(random_bytes(5));
    $live = $base.'-live.sqlite';
    $backupPlain = $base.'-backup.sqlite';
    $enc = $base.'-backup.sqlite.enc';
    rbMakeSqlite($live, 'ORIGINAL');
    rbMakeSqlite($backupPlain, 'RESTORED');
    (new BackupEncryptor)->encrypt($backupPlain, $enc, 'pw');

    // Point ONLY the 'sqlite' connection at the temp live file; the test's own
    // sqlite_testing connection (RefreshDatabase) is untouched.
    $db = app(DatabaseManager::class);
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $live);
    $db->purge('sqlite');

    try {
        $snapshot = app(RestoreEncryptedBackup::class)($enc, 'pw');
    } finally {
        Config::set('database.default', 'sqlite_testing');
        $db->purge('sqlite');
    }

    expect(rbReadMarker($live))->toBe('RESTORED');
    expect(is_file($snapshot))->toBeTrue();
    expect(rbReadMarker($snapshot))->toBe('ORIGINAL');

    foreach ([$live, $backupPlain, $enc, $snapshot] as $f) {
        @unlink($f);
    }
});

it('refuses to swap when the passphrase is wrong — the live file is untouched', function (): void {
    $base = sys_get_temp_dir().'/rb-'.bin2hex(random_bytes(5));
    $live = $base.'-live.sqlite';
    $enc = $base.'-backup.sqlite.enc';
    rbMakeSqlite($live, 'ORIGINAL');
    rbMakeSqlite($base.'-backup.sqlite', 'RESTORED');
    (new BackupEncryptor)->encrypt($base.'-backup.sqlite', $enc, 'right-pw');

    $db = app(DatabaseManager::class);
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $live);
    $db->purge('sqlite');

    try {
        expect(fn () => app(RestoreEncryptedBackup::class)($enc, 'wrong-pw'))
            ->toThrow(RuntimeException::class);
    } finally {
        Config::set('database.default', 'sqlite_testing');
        $db->purge('sqlite');
    }

    expect(rbReadMarker($live))->toBe('ORIGINAL'); // never swapped — decrypt failed before the swap

    foreach ([$live, $base.'-backup.sqlite', $enc] as $f) {
        @unlink($f);
    }
});

// A payload that decrypts but is not a restorable database raises
// BackupFormatException rather than a decryption failure, because the passphrase
// was right and offering to retype it sends the user down the wrong path. The
// live file survives either way: the swap follows integrity_check, never leads.

it('refuses a payload that decrypts but will not open as a database', function (): void {
    $base = sys_get_temp_dir().'/rb-'.bin2hex(random_bytes(5));
    $live = $base.'-live.sqlite';
    $notADatabase = $base.'-payload.txt';
    $enc = $base.'-payload.enc';
    rbMakeSqlite($live, 'ORIGINAL');
    file_put_contents($notADatabase, 'this is not a SQLite file, it is a note');
    (new BackupEncryptor)->encrypt($notADatabase, $enc, 'pw');

    $db = app(DatabaseManager::class);
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $live);
    $db->purge('sqlite');

    try {
        expect(fn () => app(RestoreEncryptedBackup::class)($enc, 'pw'))
            ->toThrow(BackupFormatException::class, 'not a readable database');
    } finally {
        Config::set('database.default', 'sqlite_testing');
        $db->purge('sqlite');
    }

    expect(rbReadMarker($live))->toBe('ORIGINAL');

    foreach ([$live, $notADatabase, $enc] as $f) {
        @unlink($f);
    }
});
