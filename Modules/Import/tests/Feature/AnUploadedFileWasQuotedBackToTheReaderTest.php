<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Tests\Helpers\UploadIsolation;

// Two seams put a refusal in front of the reader and into the daily log, and
// both were quoting the upload back. SniffMismatchException is marked as naming
// no user data, which is exactly what lets ImportPipeline render its message on
// the preview and write it under `exception_message`; Symfony's YAML
// ParseException quotes the offending source line into its own message.
const QUOTED_BACK_IBAN = 'NL91ABNA0417164300';

beforeEach(function (): void {
    UploadIsolation::isolate();

    $this->reader = User::query()->create([
        'username' => 'quoted-back',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

function quotedBackCsv(string $firstLine): string
{
    $path = sys_get_temp_dir().'/quoted-back-'.bin2hex(random_bytes(6)).'.csv';
    file_put_contents($path, $firstLine."\n");
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });

    return $path;
}

it('does not quote the first line of the file back when the header does not match', function (): void {
    // Twenty cells, so the column-count arm passes and the signature arm is
    // the one that answers. A headerless export puts a data row here: for ASN
    // that is the posted date and the reader's own IBAN.
    $row = ['02-02-2026', QUOTED_BACK_IBAN, ...array_fill(0, 18, 'x')];
    $path = quotedBackCsv(implode(',', $row));

    /** @var HeaderSniffer $sniffer */
    $sniffer = app(HeaderSniffer::class);

    try {
        $sniffer->sniff($path, CsvPresetRegistry::ASN);
        $message = '';
    } catch (SniffMismatchException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBe('', 'the sniffer accepted a file that is not this export, so nothing was tested');
    expect($message)->not->toContain(QUOTED_BACK_IBAN)
        ->and($message)->not->toContain('02-02-2026');

    // The control: a refusal that says nothing at all passes every assertion
    // above and leaves the reader with no idea which column was wrong.
    expect($message)->toContain('Datum');
});

it('does not put the absolute path of the staged file in the refusal', function (): void {
    $missing = sys_get_temp_dir().'/quoted-back-'.QUOTED_BACK_IBAN.'-2026-07.csv';

    /** @var HeaderSniffer $sniffer */
    $sniffer = app(HeaderSniffer::class);

    try {
        $sniffer->sniff($missing, CsvPresetRegistry::ASN);
        $message = '';
    } catch (SniffMismatchException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBe('')
        ->and($message)->not->toContain($missing)
        ->and($message)->not->toContain(QUOTED_BACK_IBAN);
});

it('does not write the line of the alias file that would not parse into the log', function (): void {
    $records = [];
    Log::listen(static function (object $record) use (&$records): void {
        $records[] = $record;
    });

    // Symfony reports this one as `Indentation problem at line 4 (near " …")`,
    // and the near-clause is the file's own bytes.
    $body = "entries:\n  - pattern: ALBERT HEIJN\n    name: Albert Heijn\n   iban: ".QUOTED_BACK_IBAN."\n";

    Livewire::actingAs($this->reader)
        ->test(AliasesSettingsPage::class)
        ->set('importFile', UploadedFile::fake()->createWithContent('aliases.yaml', $body))
        ->call('parseUpload');

    $written = '';
    foreach ($records as $record) {
        $written .= json_encode([$record->message, $record->context], JSON_THROW_ON_ERROR);
    }

    expect($written)->not->toBe('', 'nothing was logged at all, so this asserts about a silence rather than a redaction');
    expect($written)->not->toContain(QUOTED_BACK_IBAN)
        ->and($written)->not->toContain('Indentation problem');
});
