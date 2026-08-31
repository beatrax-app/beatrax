<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Migration\Internal\Http\Livewire\NewMigration;
use Modules\Migration\Internal\Parsers\Support\ArchiveReaderFactory;
use Modules\Migration\Internal\Parsers\Support\ZipExtractor;
use Modules\Migration\Tests\Support\MigrationFixturePaths;
use Modules\Migration\Tests\Support\ThrowingArchiveReader;
use Tests\Helpers\UploadIsolation;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->user = User::create([
        'username' => 'migration-archive-capability-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
});

// The mimes:zip rule sniffs content, so every case here has to travel as real
// archive bytes rather than as a fake upload with a .zip name.
function migrationUploadOf(string $zipPath, string $clientName): UploadedFile
{
    return UploadedFile::fake()->createWithContent($clientName, (string) file_get_contents($zipPath));
}

// A stored entry whose declared compression method is rewritten to 12 (bzip2):
// well-formed, readable by ext-zip, and outside what the built-in reader can
// inflate. Stored bytes keep the payload free of the header signatures the
// offsets below are found by.
function migrationArchiveWithUnsupportedMethod(): string
{
    $path = sys_get_temp_dir().'/migration-unsupported-method-'.uniqid('', true).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('Register.csv', str_repeat('date,payee,amount', 40));
    $zip->setCompressionName('Register.csv', ZipArchive::CM_STORE);
    $zip->close();

    $raw = (string) file_get_contents($path);
    $local = strpos($raw, "PK\x03\x04");
    $central = strpos($raw, "PK\x01\x02");
    expect($local)->not->toBeFalse();
    expect($central)->not->toBeFalse();

    $raw = substr_replace($raw, pack('v', 12), (int) $local + 8, 2);
    $raw = substr_replace($raw, pack('v', 12), (int) $central + 10, 2);
    file_put_contents($path, $raw);

    return $path;
}

function migrationBindExtractorWithoutZipExtension(): void
{
    app()->bind(ZipExtractor::class, fn (): ZipExtractor => new ZipExtractor(
        readers: new ArchiveReaderFactory(zipExtensionAvailable: false),
    ));
}

it('MigrationArchiveCapability: a phone with no ext-zip still reads the committed nYNAB export', function (): void {
    migrationBindExtractorWithoutZipExtension();

    Livewire::actingAs($this->user)
        ->test(NewMigration::class)
        ->set('sourceProduct', 'nynab')
        ->set('file', migrationUploadOf(MigrationFixturePaths::nynabZip('v1'), 'nynab-export.zip'))
        ->call('submit')
        ->assertHasNoErrors(['file'])
        ->assertSet('uploadError', null);
});

it('MigrationArchiveCapability: an archive this build cannot open names the capability, not the file', function (): void {
    migrationBindExtractorWithoutZipExtension();
    $path = migrationArchiveWithUnsupportedMethod();

    Livewire::actingAs($this->user)
        ->test(NewMigration::class)
        ->set('sourceProduct', 'nynab')
        ->set('file', migrationUploadOf($path, 'nynab-export.zip'))
        ->call('submit')
        ->assertSet('uploadError', Lang::get('migration::new.errors.archive_reader_unavailable'));

    @unlink($path);
});

it('MigrationArchiveCapability: an internal Error names itself, not the reader file', function (): void {
    app()->bind(ZipExtractor::class, fn (): ZipExtractor => new ZipExtractor(
        readers: new ArchiveReaderFactory(
            reader: new ThrowingArchiveReader(new Error('Class "ZipArchive" not found')),
        ),
    ));

    Livewire::actingAs($this->user)
        ->test(NewMigration::class)
        ->set('sourceProduct', 'nynab')
        ->set('file', migrationUploadOf(MigrationFixturePaths::nynabZip('v1'), 'nynab-export.zip'))
        ->call('submit')
        ->assertSet('uploadError', Lang::get('migration::new.errors.internal_detail', ['code' => 'Error']));
});

it('MigrationArchiveCapability: a genuinely unreadable export still says so', function (): void {
    Livewire::actingAs($this->user)
        ->test(NewMigration::class)
        ->set('sourceProduct', 'nynab')
        ->set('file', migrationUploadOf(MigrationFixturePaths::corruptZip(), 'nynab-export.zip'))
        ->call('submit')
        ->assertSet('uploadError', Lang::get('migration::new.errors.unrecognised'));
});
