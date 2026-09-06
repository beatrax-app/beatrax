<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Internal\Backup\BackupKeyMaterial;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Http\Livewire\EncryptedBackupDownload;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\OwnerOnlyPath;
use Modules\Core\Public\Support\PatternScan;
use Modules\Core\Tests\Support\BackupShareSheet;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;

// Driven on the SM-S928B: passphrase typed, "Download encrypted backup"
// tapped, and the Livewire POST ran for 583ms — VACUUM INTO, Argon2id,
// XChaCha20 — before returning a BinaryFileResponse with
// deleteFileAfterSend(). The Android WebView has no download listener, so the
// response went nowhere and the file it had just written was deleted behind it.
//
// The screen's answer to that was to withhold the whole feature and send the
// reader to the desktop app, while still offering RESTORE on the same screen:
// back up nowhere, restore from anywhere. "Cannot be saved" was only ever true
// of the WebView download route. The OS share sheet is a route the phone does
// have, so the feature comes back and only a shell with no sheet at all is
// told there is nothing here for it.

// Stands in for the VACUUM INTO the transactional test harness refuses
// ("cannot VACUUM from within a transaction"), so the component's own decision
// about where the finished file goes is what this reaches. It produces a real
// SQLite file, not a marker string: the keyring the archive has to carry is
// written INTO the snapshot, and a stand-in nothing can open would only prove
// the component works on a snapshot VACUUM INTO never makes.
function backupSnapshotDatabase(): DatabaseManager
{
    $connection = Mockery::mock(Connection::class);
    $connection->allows('statement')->andReturnUsing(static function (string $sql): bool {
        $found = PatternScan::first("/VACUUM INTO '(.+)'/", $sql);
        expect($found)->toHaveCount(2);
        $snapshot = new PDO('sqlite:'.$found[1], options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $snapshot->exec('CREATE TABLE marker (val TEXT)');
        $snapshot->exec("INSERT INTO marker (val) VALUES ('plaintext-snapshot')");

        return true;
    });

    $db = Mockery::mock(DatabaseManager::class);
    $db->allows('connection')->andReturn($connection);

    return $db;
}

function backupPassphraseEncryptor(): FileEncryptor
{
    return new class implements FileEncryptor
    {
        public function encrypt(string $plainPath, string $encPath, string $passphrase): void
        {
            file_put_contents($encPath, 'encrypted:'.(string) file_get_contents($plainPath));
        }

        public function encryptWithKey(string $plainPath, string $encPath, string $key): void {}

        public function decrypt(string $encPath, string $plainPath, string $passphrase): void {}

        /** @return array{0: int, 1: int} */
        public function kdfParams(string $encPath): array
        {
            return [1, 1];
        }
    };
}

function backupDownloadFor(EncryptedBackupDownload $component, ShareSheetExport $shareSheet): mixed
{
    $clock = Mockery::mock(Clock::class);
    $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 8, 29, 16, 20, 0));

    return $component->download(
        backupSnapshotDatabase(),
        app(Repository::class),
        backupPassphraseEncryptor(),
        $clock,
        app(ResponseFactory::class),
        $shareSheet,
        app(BackupKeyMaterial::class),
        app(OwnerOnlyPath::class),
    );
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'backup-platform',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('offers the backup on a phone that drops downloads but can hand a file to the OS', function (): void {
    $this->app->instance(ShareSheetExport::class, new BackupShareSheet);

    Livewire::test(EncryptedBackupDownload::class)
        ->assertSeeHtml('id="backup-passphrase"');
});

it('withholds it only where the shell has no way to take a file at all', function (): void {
    $this->app->instance(ShareSheetExport::class, new BackupShareSheet(available: false));

    Livewire::test(EncryptedBackupDownload::class)
        ->assertDontSeeHtml('id="backup-passphrase"')
        ->assertSee('cannot pass a file');
});

it('offers it on a shell that does save what the WebView downloads', function (): void {
    $this->app->instance(ShareSheetExport::class, new BackupShareSheet(dropsDownloads: false, available: false));

    Livewire::test(EncryptedBackupDownload::class)
        ->assertSeeHtml('id="backup-passphrase"');
});

it('offers it off a phone entirely', function (): void {
    Livewire::test(EncryptedBackupDownload::class)
        ->assertSeeHtml('id="backup-passphrase"');
});

it('hands the encrypted backup to the share sheet instead of a response nothing receives', function (): void {
    $sheet = new BackupShareSheet;

    $component = Livewire::test(EncryptedBackupDownload::class)
        ->set('passphrase', 'a-good-passphrase')
        ->set('confirmPassphrase', 'a-good-passphrase')
        ->instance();

    $response = backupDownloadFor($component, $sheet);

    expect($response)->toBeNull()
        ->and($sheet->handed)->toHaveCount(1)
        ->and($sheet->handed[0][0])->toBe('beatrax-backup-2026-08-29-162000.sqlite.enc')
        ->and($sheet->handed[0][1])->toStartWith('encrypted:SQLite format 3')
        ->and($component->notice)->toBe(FileExportOutcome::Shared->message())
        ->and($component->error)->toBe('');
});

it('says why when the handover fails, and keeps no encrypted copy in the container', function (): void {
    $sheet = new BackupShareSheet(outcome: FileExportOutcome::Failed);

    $component = Livewire::test(EncryptedBackupDownload::class)
        ->set('passphrase', 'a-good-passphrase')
        ->set('confirmPassphrase', 'a-good-passphrase')
        ->instance();

    expect(backupDownloadFor($component, $sheet))->toBeNull()
        ->and($component->error)->toBe(FileExportOutcome::Failed->message())
        ->and($component->notice)->toBe('')
        ->and(glob(UserDataPathService::appPath('tmp-backups').'/*.enc') ?: [])->toBe([]);
});

// Six exports hand a file to the OS share sheet and four of them are plaintext:
// a tax CSV, a report CSV, an alias file. All six carried one sentence — "save
// this file somewhere you can find it again" — which is true of a file and says
// nothing about who else can read it. The default now warns, because a forgotten
// flag then over-warns rather than reassuring about a readable file.
it('hands an encrypted backup a sentence about its passphrase, not the plaintext warning', function (): void {
    $sheet = new BackupShareSheet;

    $component = Livewire::test(EncryptedBackupDownload::class)
        ->set('passphrase', 'a-good-passphrase')
        ->set('confirmPassphrase', 'a-good-passphrase')
        ->instance();

    backupDownloadFor($component, $sheet);

    expect($sheet->handedMessages)->toHaveCount(1)
        ->and($sheet->handedMessages[0])->toBe(Lang::get('mobile::export.share_message_encrypted'))
        ->and($sheet->handedMessages[0])->not->toBe(Lang::get('mobile::export.share_message'));
});

// The other half of the pair, and the one that matters more: the sentence a
// plaintext export gets by saying nothing has to be the one that warns.
it('keeps the default share sentence a warning rather than a reassurance', function (): void {
    $default = Lang::get('mobile::export.share_message');
    $encrypted = Lang::get('mobile::export.share_message_encrypted');

    expect($default)->not->toBe($encrypted, 'both sentences resolve the same, so an encrypted export and a readable one tell the reader the same thing')
        ->and(str_contains($default, 'not encrypted'))->toBeTrue(
            'the default sentence no longer says the file is readable, and it is what every export that passes no message hands the reader.',
        );
});
