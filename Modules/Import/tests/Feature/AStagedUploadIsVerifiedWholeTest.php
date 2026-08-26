<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Factory as StorageFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Modules\Import\Internal\Exceptions\UploadStagingException;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

// A disk that reports success and writes less than it was given, which is what
// a device out of free space does: file_put_contents returns a byte count
// rather than false for a short write, and Flysystem only tests for false.
function diskThatWritesShort(int $keepBytes): void
{
    $root = sys_get_temp_dir().'/beatrax-short-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('writeStream')->andReturnUsing(static function (string $path, $stream) use ($root, $keepBytes): bool {
        $absolute = $root.'/'.$path;
        @mkdir(dirname($absolute), 0700, true);
        file_put_contents($absolute, substr((string) stream_get_contents($stream), 0, $keepBytes));

        return true;
    });
    $disk->shouldReceive('path')->andReturnUsing(static fn (string $path): string => $root.'/'.$path);
    $disk->shouldReceive('delete')->andReturn(true);

    $factory = Mockery::mock(StorageFactory::class);
    $factory->shouldReceive('disk')->andReturn($disk);

    test()->swap(StorageFactory::class, $factory);
}

it('refuses a staged copy that did not arrive whole', function (): void {
    $source = base_path('tests/fixtures/asn-sample-1.csv');
    diskThatWritesShort(1024);

    expect(fn (): mixed => app(RunsImports::class)->runFromUpload(
        $source, 'asn-csv', $this->fixtureUser, 'asn-sample-1.csv', BankCsvFormatHint::Asn,
    ))->toThrow(UploadStagingException::class);
});

it('accepts a staged copy that did', function (): void {
    $source = base_path('tests/fixtures/asn-sample-1.csv');
    diskThatWritesShort(PHP_INT_MAX);

    $preview = app(RunsImports::class)->runFromUpload(
        $source, 'asn-csv', $this->fixtureUser, 'asn-sample-1.csv', BankCsvFormatHint::Asn,
    );

    expect($preview->rows)->not->toBeEmpty();
});
