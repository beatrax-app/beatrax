<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;
use Modules\Reports\Tests\Support\ReportExportShareSheet;

uses(RefreshDatabase::class);

// Driven on the SM-S928B: "↓ CSV exporteren" on /reports ran the round-trip
// (BRIDGE_TOTAL [/livewire-.../update] 27ms in logcat) and then nothing —
// nothing in /sdcard/Download, nothing in the app container, an unchanged page
// and an empty toast container. Both the builder's action and the /reports/export
// route answered with a StreamedResponse the Android WebView has nowhere to put.

function reportExportReader(): User
{
    return User::query()->create([
        'username' => 'report-export-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('hands the builder CSV to the share sheet on a shell that drops downloads', function (): void {
    $sheet = new ReportExportShareSheet;
    $this->app->instance(ShareSheetExport::class, $sheet);

    Livewire::actingAs(reportExportReader())->test(ReportBuilder::class)
        ->call('export')
        ->assertSet('flashMessage', FileExportOutcome::Shared->message());

    expect($sheet->handed)->toHaveCount(1)
        ->and(array_key_first($sheet->handed))->toStartWith('beatrax-report-')
        ->and(array_key_first($sheet->handed))->toEndWith('.csv');
});

it('leaves the builder download alone where the WebView saves it', function (): void {
    $sheet = new ReportExportShareSheet(dropsDownloads: false);
    $this->app->instance(ShareSheetExport::class, $sheet);

    Livewire::actingAs(reportExportReader())->test(ReportBuilder::class)
        ->call('export')
        ->assertSet('flashMessage', '');

    expect($sheet->handed)->toBe([]);
});

it('answers the export route with the reason instead of a file nothing receives', function (): void {
    $sheet = new ReportExportShareSheet;
    $this->app->instance(ShareSheetExport::class, $sheet);

    $this->actingAs(reportExportReader())
        ->get('/reports/export?dim=account&metric=spend&period=this_month')
        ->assertSuccessful()
        ->assertSee(FileExportOutcome::Shared->message());

    expect($sheet->handed)->toHaveCount(1);
});

it('keeps streaming the export route where the WebView saves what it is sent', function (): void {
    $sheet = new ReportExportShareSheet(dropsDownloads: false);
    $this->app->instance(ShareSheetExport::class, $sheet);

    $this->actingAs(reportExportReader())
        ->get('/reports/export?dim=account&metric=spend&period=this_month')
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect($sheet->handed)->toBe([]);
});
