<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Internal\Backup\ArchiveWriterFactory;
use Modules\Core\Internal\Backup\BackupKeyMaterial;
use Modules\Core\Internal\Backup\ExportEverythingArchive;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Http\Livewire\ExportEverythingDownload;
use Modules\Core\Public\Support\OwnerOnlyPath;
use Modules\Core\Public\Support\PatternScan;
use Modules\Core\Tests\Support\ExportEverythingShareSheet;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// The export has the same two roads out as the encrypted backup: a
// BinaryFileResponse where the shell saves what its WebView downloads, and the
// OS share sheet where it does not. A phone can be the only device a household
// owns, so the road that works there is not the fallback.

// Stands in for the VACUUM INTO the transactional harness refuses ("cannot
// VACUUM from within a transaction"), so what this reaches is the component's
// own decision about where the finished archive goes. It writes a real SQLite
// file, because the keyring the archive carries is packed INTO the snapshot.
function exportEverythingSnapshotDatabase(): DatabaseManager
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

function exportEverythingRefusingDatabase(): DatabaseManager
{
    $connection = Mockery::mock(Connection::class);
    $connection->allows('statement')->andThrow(new BackupIoException('The snapshot could not be taken.'));

    $db = Mockery::mock(DatabaseManager::class);
    $db->allows('connection')->andReturn($connection);

    return $db;
}

function exportEverythingEncryptor(): FileEncryptor
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

function exportEverythingArchiveWith(DatabaseManager $db): ExportEverythingArchive
{
    return new ExportEverythingArchive(
        $db,
        exportEverythingEncryptor(),
        app(BackupKeyMaterial::class),
        app(OwnerOnlyPath::class),
        new ArchiveWriterFactory,
    );
}

function exportEverythingRun(
    ExportEverythingDownload $component,
    ShareSheetExport $shareSheet,
    ?DatabaseManager $db = null,
): mixed {
    $clock = Mockery::mock(Clock::class);
    $clock->allows('now')->andReturn(CarbonImmutable::create(2026, 9, 4, 12, 0, 0));

    return $component->export(
        app(Repository::class),
        $clock,
        app(ResponseFactory::class),
        $shareSheet,
        exportEverythingArchiveWith($db ?? exportEverythingSnapshotDatabase()),
    );
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'export-platform',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('hands the archive to the share sheet instead of a response nothing receives', function (): void {
    $sheet = new ExportEverythingShareSheet;

    $component = Livewire::test(ExportEverythingDownload::class)
        ->set('passphrase', 'a-good-passphrase')
        ->set('confirmPassphrase', 'a-good-passphrase')
        ->instance();

    $returned = exportEverythingRun($component, $sheet);

    expect($returned)->toBeNull()
        ->and($sheet->handed)->toHaveCount(1)
        ->and($sheet->handed[0])->toBe('beatrax-export-2026-09-04-120000.zip');
});

it('returns a downloadable response where the shell saves what it downloads', function (): void {
    $sheet = new ExportEverythingShareSheet(dropsDownloads: false, available: false);

    $component = Livewire::test(ExportEverythingDownload::class)
        ->set('passphrase', 'a-good-passphrase')
        ->set('confirmPassphrase', 'a-good-passphrase')
        ->instance();

    $returned = exportEverythingRun($component, $sheet);

    expect($returned)->toBeInstanceOf(BinaryFileResponse::class)
        ->and($sheet->handed)->toBe([]);
});

it('takes the archive with it when the handover is refused', function (): void {
    $sheet = new ExportEverythingShareSheet(outcome: FileExportOutcome::Failed);

    $component = Livewire::test(ExportEverythingDownload::class)
        ->set('passphrase', 'a-good-passphrase')
        ->set('confirmPassphrase', 'a-good-passphrase')
        ->instance();

    $returned = exportEverythingRun($component, $sheet);

    expect($returned)->toBeNull()
        ->and($sheet->handed)->toHaveCount(1)
        ->and($component->error)->not->toBe('');
});

it('says the export failed rather than handing back a half-written archive', function (): void {
    $sheet = new ExportEverythingShareSheet;

    $component = Livewire::test(ExportEverythingDownload::class)
        ->set('passphrase', 'a-good-passphrase')
        ->set('confirmPassphrase', 'a-good-passphrase')
        ->instance();

    $returned = exportEverythingRun($component, $sheet, exportEverythingRefusingDatabase());

    expect($returned)->toBeNull()
        ->and($component->error)->toContain('BackupIoException')
        ->and($sheet->handed)->toBe([]);
});

it('forgets the passphrase once the archive is built', function (): void {
    $sheet = new ExportEverythingShareSheet;

    $component = Livewire::test(ExportEverythingDownload::class)
        ->set('passphrase', 'a-good-passphrase')
        ->set('confirmPassphrase', 'a-good-passphrase')
        ->instance();

    exportEverythingRun($component, $sheet);

    expect($component->passphrase)->toBe('')
        ->and($component->confirmPassphrase)->toBe('');
});

it('withholds the export only where the shell can take a file no way at all', function (): void {
    $this->app->instance(ShareSheetExport::class, new ExportEverythingShareSheet(available: false));

    Livewire::test(ExportEverythingDownload::class)
        ->assertDontSeeHtml('id="export-everything-passphrase"')
        ->assertSee('cannot pass a file');
});
