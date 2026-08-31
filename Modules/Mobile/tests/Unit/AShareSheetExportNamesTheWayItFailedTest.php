<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;

// The seam every download surface reaches the reader through on a shell whose
// WebView drops one. It has exactly three answers and none of them is silence:
// the sheet opened, this shell has no sheet, or the handover failed. A caller
// that cannot tell those apart cannot tell the reader either, which is how
// "↓ CSV exporteren" became a button that did nothing at all on the SM-S928B.

final class ShareSheetExportSpy extends ShareSheetExport
{
    /** @var list<array{string, string, string}> */
    public array $shared = [];

    public function __construct(private readonly bool $registersShareFile = true, private readonly bool $shareSucceeds = true) {}

    public function isAvailable(): bool
    {
        return true;
    }

    protected function canShareFiles(): bool
    {
        return $this->registersShareFile;
    }

    protected function share(string $shareTitle, string $shareMessage, string $path): bool
    {
        $this->shared[] = [$shareTitle, $shareMessage, $path];

        return $this->shareSucceeds;
    }
}

function shareSheetExportScratchDirectory(): string
{
    return UserDataPathService::appPath('exports');
}

afterEach(function (): void {
    unset($_SERVER['NATIVEPHP_PLATFORM']);

    foreach (glob(shareSheetExportScratchDirectory().'/share-sheet-export-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

it('answers that a download needs replacing only where the shell drops one', function (): void {
    $bridge = new ShareSheetExport;

    expect($bridge->replacesWebViewDownload())->toBeFalse();

    $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';
    expect($bridge->replacesWebViewDownload())->toBeFalse();

    $_SERVER['NATIVEPHP_PLATFORM'] = 'android';
    expect($bridge->replacesWebViewDownload())->toBeTrue();

    // A shell NativePHP names and MobilePlatform does not model. Guessing that
    // it saves downloads is the guess whose cost is a file that never arrives.
    $_SERVER['NATIVEPHP_PLATFORM'] = 'harmonyos';
    expect($bridge->replacesWebViewDownload())->toBeTrue();
});

it('writes the payload private to the app and hands that path to the sheet', function (): void {
    $bridge = new ShareSheetExportSpy;

    $outcome = $bridge->export('share-sheet-export-payload.csv', "date,amount\n2026-08-29,12.34\n");

    expect($outcome)->toBe(FileExportOutcome::Shared)
        ->and($bridge->shared)->toHaveCount(1);

    $path = $bridge->shared[0][2];

    expect($path)->toBe(shareSheetExportScratchDirectory().DIRECTORY_SEPARATOR.'share-sheet-export-payload.csv')
        ->and((string) file_get_contents($path))->toBe("date,amount\n2026-08-29,12.34\n")
        ->and(substr(sprintf('%o', (int) fileperms($path)), -3))->toBe('600');
});

it('says the shell has no sheet rather than reporting a save it did not make', function (): void {
    $bridge = new ShareSheetExportSpy(registersShareFile: false);

    expect($bridge->export('share-sheet-export-refused.csv', 'x'))->toBe(FileExportOutcome::Unsupported)
        ->and($bridge->shared)->toBe([]);
});

it('says the handover failed when the share call refuses', function (): void {
    $bridge = new ShareSheetExportSpy(shareSucceeds: false);

    expect($bridge->export('share-sheet-export-refused-call.csv', 'x'))->toBe(FileExportOutcome::Failed);
});

it('moves a payload already on disk instead of leaving a second copy behind', function (): void {
    $bridge = new ShareSheetExportSpy;

    $source = UserDataPathService::appPath('share-sheet-export-source.sqlite.enc');
    file_put_contents($source, 'encrypted-snapshot');

    $outcome = $bridge->exportFile($source, 'share-sheet-export-backup.sqlite.enc');

    expect($outcome)->toBe(FileExportOutcome::Shared)
        ->and($source)->not->toBeFile()
        ->and((string) file_get_contents($bridge->shared[0][2]))->toBe('encrypted-snapshot');
});

it('reports a failure rather than a share when the payload was never written', function (): void {
    $bridge = new ShareSheetExportSpy;

    expect($bridge->exportFile(UserDataPathService::appPath('no-such-share-sheet-export'), 'share-sheet-export-absent.enc'))
        ->toBe(FileExportOutcome::Failed)
        ->and($bridge->shared)->toBe([]);
});

it('gives every outcome a sentence a reader can act on', function (): void {
    foreach (FileExportOutcome::cases() as $outcome) {
        expect($outcome->message())
            ->not->toBe('mobile::export.'.$outcome->value)
            ->and(strlen($outcome->message()))->toBeGreaterThan(20);
    }
});

// The staged file cannot be deleted after the handover — the OS reads it on
// the reader's behalf once the call has returned — so nothing ever did, and a
// shared export stayed. On this surface that is a whole encrypted database
// per download, in a directory the reader cannot see or empty.
it('does not keep every export it has ever staged', function (): void {
    $directory = shareSheetExportScratchDirectory();
    @mkdir($directory, 0700, true);

    $yesterday = $directory.'/share-sheet-export-yesterday.enc';
    file_put_contents($yesterday, 'a whole database');
    touch($yesterday, time() - 86400);

    $sheet = new ShareSheetExportSpy;

    expect($sheet->export('share-sheet-export-today.txt', 'today'))->toBe(FileExportOutcome::Shared)
        ->and(is_file($yesterday))->toBeFalse()
        ->and(is_file($directory.'/share-sheet-export-today.txt'))->toBeTrue();
});

// A sheet still reading the file it was just handed must not lose it, so the
// sweep is by age and never touches what this session staged.
it('keeps an export the sheet may still be reading', function (): void {
    $directory = shareSheetExportScratchDirectory();
    @mkdir($directory, 0700, true);

    $sheet = new ShareSheetExportSpy;
    $sheet->export('share-sheet-export-first.txt', 'first');
    $sheet->export('share-sheet-export-second.txt', 'second');

    expect(is_file($directory.'/share-sheet-export-first.txt'))->toBeTrue()
        ->and(is_file($directory.'/share-sheet-export-second.txt'))->toBeTrue();
});
