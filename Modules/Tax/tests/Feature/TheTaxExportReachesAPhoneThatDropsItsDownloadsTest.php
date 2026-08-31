<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Modules\Tax\Internal\Http\Livewire\TaxPage;

uses(RefreshDatabase::class);

// Both export buttons on /tax answered with a StreamedResponse and asked
// nothing about the shell they were answering into. The Android WebView
// registers no DownloadListener, so the round-trip ran, the CSV was built, and
// the reader got no file, no error and an unchanged page.

final class TaxExportShareSheet extends ShareSheetExport
{
    /** @var array<string, string> */
    public array $handed = [];

    public function __construct(private readonly bool $dropsDownloads = true) {}

    public function replacesWebViewDownload(): bool
    {
        return $this->dropsDownloads;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function export(
        string $filename,
        string $contents,
        ?string $shareTitle = null,
        ?string $shareMessage = null,
    ): FileExportOutcome {
        $this->handed[$filename] = $contents;

        return FileExportOutcome::Shared;
    }
}

function taxExportReader(): User
{
    return User::query()->create([
        'username' => 'tax-export-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('hands both tax exports to the share sheet on a shell that drops downloads', function (): void {
    $sheet = new TaxExportShareSheet;
    $this->app->instance(ShareSheetExport::class, $sheet);

    $page = Livewire::actingAs(taxExportReader())->test(TaxPage::class);
    $year = $page->instance()->year;

    $page->call('exportCsv')->assertSet('flashMessage', FileExportOutcome::Shared->message());
    $page->call('exportPdf')->assertSet('flashMessage', FileExportOutcome::Shared->message());

    expect(array_keys($sheet->handed))->toBe(["beatrax-tax-{$year}.csv", "beatrax-tax-{$year}.pdf"])
        ->and($sheet->handed["beatrax-tax-{$year}.csv"])->not->toBe('')
        ->and($sheet->handed["beatrax-tax-{$year}.pdf"])->not->toBe('');
});

it('leaves the download alone where the WebView saves it', function (): void {
    $sheet = new TaxExportShareSheet(dropsDownloads: false);
    $this->app->instance(ShareSheetExport::class, $sheet);

    Livewire::actingAs(taxExportReader())->test(TaxPage::class)
        ->call('exportCsv')
        ->assertSet('flashMessage', '');

    expect($sheet->handed)->toBe([]);
});

it('tells the reader when the shell has no way to take the file', function (): void {
    $sheet = new class extends ShareSheetExport
    {
        public function replacesWebViewDownload(): bool
        {
            return true;
        }

        public function isAvailable(): bool
        {
            return false;
        }
    };
    $this->app->instance(ShareSheetExport::class, $sheet);

    Livewire::actingAs(taxExportReader())->test(TaxPage::class)
        ->call('exportCsv')
        ->assertSet('flashMessage', FileExportOutcome::Unsupported->message());
});
