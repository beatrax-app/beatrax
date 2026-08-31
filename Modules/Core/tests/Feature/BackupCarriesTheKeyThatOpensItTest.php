<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Config;
use Modules\Core\Internal\Backup\BackupKeyMaterial;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Http\Livewire\EncryptedBackupDownload;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\RestoreEncryptedBackup;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Modules\Sync\Public\Services\PortableKeyMaterial;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// A user with encryption at rest keeps their notes, descriptions,
// counterparty names and IBANs as ciphertext in the database, and the only
// keys that open them are a file BESIDE it. An archive of the database alone
// restores onto a fresh install as a ledger nothing can read — the exact
// shape of the update that once shipped a keyless database, recorded in
// .docs/features/core/durable-user-data-paths.md.

beforeEach(function (): void {
    $this->keyMaterial = new PortableKeyMaterial;
    $this->base = sys_get_temp_dir().'/bkm-'.bin2hex(random_bytes(5));
    $this->live = $this->base.'-live.sqlite';
    $this->leftovers = [];
});

afterEach(function (): void {
    Config::set('database.default', 'sqlite_testing');
    app(DatabaseManager::class)->purge('sqlite');

    /** @var list<string> $leftovers */
    $leftovers = $this->leftovers;
    foreach ([...$leftovers, $this->live] as $path) {
        @unlink((string) $path);
    }

    /** @var PortableKeyMaterial $keyMaterial */
    $keyMaterial = $this->keyMaterial;
    foreach ((array) glob($keyMaterial->keyringDirectory().'/*') as $stale) {
        @unlink((string) $stale);
    }
});

function bkmSealedLedgerAt(string $path, int $userId, int $epoch): void
{
    $pdo = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE sync_encryption_state (user_id INTEGER, current_epoch INTEGER)');
    $pdo->exec("INSERT INTO sync_encryption_state (user_id, current_epoch) VALUES ({$userId}, {$epoch})");
}

function bkmPointLiveAt(string $path): void
{
    Config::set('database.default', 'sqlite');
    Config::set('database.connections.sqlite.database', $path);
    app(DatabaseManager::class)->purge('sqlite');
}

// The component takes its collaborators as method parameters (Livewire
// components may not constructor-inject), and the Livewire test harness sends
// the BinaryFileResponse — which deletes the file. Calling download() directly
// is what keeps the archive on disk long enough to restore it.
function bkmDownloadArchive(): string
{
    $component = new EncryptedBackupDownload;
    $component->passphrase = 'a-good-passphrase';
    $component->confirmPassphrase = 'a-good-passphrase';

    $response = $component->download(
        app(DatabaseManager::class),
        app(Repository::class),
        app(FileEncryptor::class),
        app(Clock::class),
        app(ResponseFactory::class),
        app(ShareSheetExport::class),
        app(BackupKeyMaterial::class),
    );

    expect($component->error)->toBe('');
    expect($response)->toBeInstanceOf(BinaryFileResponse::class);

    return $response->getFile()->getPathname();
}

it('restores the key that opens the ledger it restored, not just the ciphertext', function (): void {
    /** @var PortableKeyMaterial $keyMaterial */
    $keyMaterial = $this->keyMaterial;
    $keyring = $keyMaterial->keyringPath(1);
    @mkdir(dirname($keyring), 0700, true);
    file_put_contents($keyring, 'the-only-copy-of-epoch-771122');

    bkmSealedLedgerAt($this->live, 1, 771122);
    bkmPointLiveAt($this->live);

    $archive = bkmDownloadArchive();
    $this->leftovers = [$archive];

    // A fresh install: the database arrives, the keyring does not exist here.
    unlink($keyring);

    $snapshot = app(RestoreEncryptedBackup::class)($archive, 'a-good-passphrase');
    $this->leftovers = [$archive, $snapshot];

    expect(is_file($keyring))->toBeTrue()
        ->and(file_get_contents($keyring))->toBe('the-only-copy-of-epoch-771122')
        ->and(fileperms($keyring) & 0o077)->toBe(0);
});

// Key material is never overwritten by a restore: the file already here may
// hold an epoch the incoming database does not name, and a restore is not the
// moment to discover that.
it('sets the keyring already on this machine aside rather than destroying it', function (): void {
    /** @var PortableKeyMaterial $keyMaterial */
    $keyMaterial = $this->keyMaterial;
    $keyring = $keyMaterial->keyringPath(1);
    @mkdir(dirname($keyring), 0700, true);
    file_put_contents($keyring, 'archive-keyring');

    bkmSealedLedgerAt($this->live, 1, 771122);
    bkmPointLiveAt($this->live);

    $archive = bkmDownloadArchive();
    $this->leftovers = [$archive];

    file_put_contents($keyring, 'the-keyring-this-machine-already-had');

    $snapshot = app(RestoreEncryptedBackup::class)($archive, 'a-good-passphrase');
    $this->leftovers = [$archive, $snapshot];

    $setAside = (array) glob($keyring.'.pre-restore-*');

    expect(file_get_contents($keyring))->toBe('archive-keyring')
        ->and($setAside)->toHaveCount(1)
        ->and(file_get_contents((string) $setAside[0]))->toBe('the-keyring-this-machine-already-had');
});

// The carrier is a courier, not a column. A live database that kept it would
// hold every user's wrapped epoch keys in a table any query can read.
it('leaves no key material in the database it restored', function (): void {
    /** @var PortableKeyMaterial $keyMaterial */
    $keyMaterial = $this->keyMaterial;
    $keyring = $keyMaterial->keyringPath(1);
    @mkdir(dirname($keyring), 0700, true);
    file_put_contents($keyring, 'the-only-copy-of-epoch-771122');

    bkmSealedLedgerAt($this->live, 1, 771122);
    bkmPointLiveAt($this->live);

    $archive = bkmDownloadArchive();
    $this->leftovers = [$archive];

    $snapshot = app(RestoreEncryptedBackup::class)($archive, 'a-good-passphrase');
    $this->leftovers = [$archive, $snapshot];

    $tables = (new PDO('sqlite:'.$this->live))
        ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
        ->fetchAll(PDO::FETCH_COLUMN);

    expect($tables)->not->toContain(BackupKeyMaterial::TABLE)
        ->and($tables)->toContain('sync_encryption_state');
});

// An archive written before the keyring travelled inside it still restores.
// Refusing one would strand every backup a reader already holds.
it('restores an archive that carries no keyring at all', function (): void {
    $plain = $this->base.'-legacy.sqlite';
    bkmSealedLedgerAt($plain, 1, 771122);
    $archive = $plain.'.enc';
    (new BackupEncryptor)->encrypt($plain, $archive, 'a-good-passphrase');

    bkmSealedLedgerAt($this->live, 1, 5);
    bkmPointLiveAt($this->live);

    $snapshot = app(RestoreEncryptedBackup::class)($archive, 'a-good-passphrase');
    $this->leftovers = [$plain, $archive, $snapshot];

    $restored = (new PDO('sqlite:'.$this->live))
        ->query('SELECT current_epoch FROM sync_encryption_state')
        ->fetchColumn();

    expect((int) $restored)->toBe(771122);
});
