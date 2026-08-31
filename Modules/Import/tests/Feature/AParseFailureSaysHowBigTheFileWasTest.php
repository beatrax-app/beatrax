<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

// A device round could not tell whether the phone's failing 7 MB import arrived
// empty or died on its content: both surface to the reader as an unreadable
// export, and the log said only which exception class was thrown.
it('records how many bytes it opened and how many rows it got through', function (): void {
    $captured = [];
    Log::listen(function ($message) use (&$captured): void {
        if (str_starts_with((string) $message->message, 'ImportPipeline: parse failed.')) {
            $captured[] = $message->context;
        }
    });

    $lines = file(base_path('tests/fixtures/asn-sample-1.csv'));
    $cells = str_getcsv(rtrim((string) $lines[1], "\r\n"), ',', '"', '');
    $good = implode(',', $cells);
    $cells[10] = 'not-a-number';
    $bad = implode(',', $cells);

    $path = sys_get_temp_dir().'/beatrax-'.bin2hex(random_bytes(6)).'.csv';
    file_put_contents($path, rtrim((string) $lines[0], "\r\n")."\n".$good."\n".$bad."\n");

    app(RunsImports::class)->runFromUpload(
        $path, 'asn-csv', $this->fixtureUser, basename($path), BankCsvFormatHint::Asn,
    );

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['source_bytes'] ?? null)->toBe(filesize($path))
        ->and($captured[0]['rows_read'] ?? null)->toBe(1);
});
