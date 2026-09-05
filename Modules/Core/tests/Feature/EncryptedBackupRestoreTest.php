<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Core\Internal\Backup\BackupContentsUnreadableException;
use Modules\Core\Models\User;
use Modules\Core\Public\Http\Livewire\EncryptedBackupRestore;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\RestoreEncryptedBackup;
use Modules\Core\Public\Support\Lang;
use Tests\Helpers\CheapKdfCost;

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
        ->assertSet('error', 'Choose what to restore from: the .enc backup file, or the .zip the one-click export wrote.');
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
        ->assertSet('error', Lang::get('core::backup.errors.restore_not_supported'));
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
    (new BackupEncryptor(new CheapKdfCost))->encrypt($backupPlain, $enc, 'pw');

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
    (new BackupEncryptor(new CheapKdfCost))->encrypt($base.'-backup.sqlite', $enc, 'right-pw');

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
    (new BackupEncryptor(new CheapKdfCost))->encrypt($notADatabase, $enc, 'pw');

    $db = app(DatabaseManager::class);
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $live);
    $db->purge('sqlite');

    try {
        expect(fn () => app(RestoreEncryptedBackup::class)($enc, 'pw'))
            ->toThrow(BackupContentsUnreadableException::class, 'not a readable database');
    } finally {
        Config::set('database.default', 'sqlite_testing');
        $db->purge('sqlite');
    }

    expect(rbReadMarker($live))->toBe('ORIGINAL');

    foreach ([$live, $notADatabase, $enc] as $f) {
        @unlink($f);
    }
});

// Livewire uploads on a request of its own and drops the property when that
// request fails, so restore() never runs and the component looks untouched.
// On iOS a backup over 6.29 MB was refused by post_max_size and the reader saw
// only "choose a file" -- the message for a field they had already filled.
it('says the upload failed rather than telling the reader to choose the file they chose', function (): void {
    $user = User::query()->create([
        'username' => 'restore-upload-dropped',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $component = Livewire::actingAs($user)
        ->test(EncryptedBackupRestore::class)
        ->call('uploadFailed');

    expect($component->get('error'))->toBe(Lang::get('core::backup.errors.upload_failed'));
    expect($component->get('error'))->not->toBe(Lang::get('core::backup.errors.choose_file'));
});

it('wires the file input to that method, or nothing ever calls it', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Core/Resources/views/livewire/encrypted-backup-restore.blade.php'),
    );

    expect($blade)->toContain('livewire-upload-error');
    expect($blade)->toContain('uploadFailed()');
});

// The screen promised "You will be signed out, because your sign-in lives in
// the database too" and performed no sign-out at all. The session carries a
// user id, and after the swap that id names whoever the backup says it does --
// so the session would have carried on as a different person.
it('signs the reader out after a restore, because the identity was replaced too', function (): void {
    $base = sys_get_temp_dir().'/beatrax-signout-'.bin2hex(random_bytes(6));
    $live = $base.'-live.sqlite';
    $enc = $base.'-backup.sqlite.enc';
    rbMakeSqlite($live, 'ORIGINAL');
    rbMakeSqlite($base.'-backup.sqlite', 'RESTORED');
    (new BackupEncryptor(new CheapKdfCost))->encrypt($base.'-backup.sqlite', $enc, 'pw');

    $db = app(DatabaseManager::class);
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $live);
    $db->purge('sqlite');

    try {
        $component = Livewire::test(EncryptedBackupRestore::class)
            ->set('backup', UploadedFile::fake()->createWithContent('b.enc', (string) file_get_contents($enc)))
            ->set('passphrase', 'pw')
            ->set('confirmation', EncryptedBackupRestore::CONFIRM_PHRASE)
            ->call('restore');
    } finally {
        Config::set('database.default', 'sqlite_testing');
        $db->purge('sqlite');
    }

    $component->assertRedirect(route('login'));
    expect(auth()->check())->toBeFalse();

    foreach ([$live, $enc, $base.'-backup.sqlite'] as $f) {
        @unlink($f);
    }
});

// On the phone the restore succeeded and then every route answered
// `SQLSTATE[HY000]: General error: 10 disk I/O error` until the app was
// force-quit. -shm is a shared-memory index a live WAL connection holds
// mapped; unlinking it under one is what SQLite reports as code 10. The
// service purged the connection named in config and left any other name
// pointing at the same file holding it open.
it('drops every connection to the live file, not only the one named in config', function (): void {
    $base = sys_get_temp_dir().'/beatrax-twoconn-'.bin2hex(random_bytes(6));
    $live = $base.'-live.sqlite';
    $enc = $base.'-backup.sqlite.enc';
    rbMakeSqlite($live, 'ORIGINAL');
    rbMakeSqlite($base.'-backup.sqlite', 'RESTORED');
    (new BackupEncryptor(new CheapKdfCost))->encrypt($base.'-backup.sqlite', $enc, 'pw');

    $db = app(DatabaseManager::class);
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $live);

    // A SECOND name onto the same file, resolved and left open — the shape the
    // persistent runtime holds and the reason one purge was not enough.
    Config::set('database.connections.sqlite_second', array_merge(
        (array) Config::get('database.connections.sqlite'),
        ['database' => $live],
    ));
    $db->purge('sqlite');
    $db->connection('sqlite_second')->select('select 1');

    expect(array_keys($db->getConnections()))->toContain('sqlite_second');

    try {
        app(RestoreEncryptedBackup::class)($enc, 'pw');

        // Read BEFORE any cleanup. Purging it here as part of teardown would
        // assert the teardown rather than the service -- which is exactly what
        // an earlier version of this test did, and it passed against the old
        // single-name purge.
        $stillOpen = array_keys($db->getConnections());
    } finally {
        Config::set('database.default', 'sqlite_testing');
        $db->purge('sqlite');
        $db->purge('sqlite_second');
    }

    // Purged means removed from the manager's resolved set. Left behind, this
    // is the handle that was still mapping the -shm that just got unlinked.
    expect($stillOpen)->not->toContain('sqlite_second');

    foreach ([$live, $enc, $base.'-backup.sqlite'] as $f) {
        @unlink($f);
    }
});

// Replacing the file left a live interpreter holding state SQLite could not
// reconcile: a brand NEW connection running `PRAGMA journal_mode = WAL`
// reported code 11, `database disk image is malformed`, on a file whose own
// integrity_check passed when pulled off the phone.
//
// The inode is NOT what differs -- PHP's copy() truncates in place, so it
// survived either way, and macOS does not reproduce the iOS symptom at all.
// What this pins is the one thing that did change: the copy path unlinks -wal
// and -shm under a process that may have them mapped, and the backup API
// writes pages through SQLite and leaves that bookkeeping alone.
it('does not unlink the WAL sidecars a live connection has mapped', function (): void {
    $base = sys_get_temp_dir().'/beatrax-shm-'.bin2hex(random_bytes(6));
    $live = $base.'-live.sqlite';
    $enc = $base.'-backup.sqlite.enc';
    rbMakeSqlite($live, 'ORIGINAL');
    rbMakeSqlite($base.'-backup.sqlite', 'RESTORED');
    (new BackupEncryptor(new CheapKdfCost))->encrypt($base.'-backup.sqlite', $enc, 'pw');

    // A resident WAL connection is what creates -shm and keeps it mapped --
    // the state the phone's persistent interpreter is always in.
    $resident = new SQLite3($live, SQLITE3_OPEN_READWRITE);
    $resident->exec('PRAGMA journal_mode = WAL');
    $resident->exec("INSERT INTO marker (val) VALUES ('touch')");
    expect(file_exists($live.'-shm'))->toBeTrue();

    $db = app(DatabaseManager::class);
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $live);
    $db->purge('sqlite');

    try {
        app(RestoreEncryptedBackup::class)($enc, 'pw');
    } finally {
        Config::set('database.default', 'sqlite_testing');
        $db->purge('sqlite');
    }

    clearstatcache();
    $shmSurvived = file_exists($live.'-shm');
    $resident->close();

    expect($shmSurvived)->toBeTrue();
    expect(rbReadMarker($live))->toBe('RESTORED');

    foreach ([$live, $enc, $base.'-backup.sqlite'] as $f) {
        @unlink($f);
        @unlink($f.'-wal');
        @unlink($f.'-shm');
    }
})->skip(fn (): bool => ! method_exists(SQLite3::class, 'backup'), 'needs the sqlite3 backup API');
