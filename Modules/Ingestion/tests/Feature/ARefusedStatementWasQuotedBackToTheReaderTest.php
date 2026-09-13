<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\HeaderSniffer;

// SniffMismatchException is marked as naming no user data, and that marking is
// exactly what lets ImportPipeline render its message on the preview screen and
// write it into the daily log under `exception_message` — a key the shipped
// log channel's redact processor does not cover.
const QUOTED_BACK_IBAN = 'NL91ABNA0417164300';

function quotedBackCsv(string $firstLine): string
{
    $path = sys_get_temp_dir().'/quoted-back-'.bin2hex(random_bytes(6)).'.csv';
    file_put_contents($path, $firstLine."\n");
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });

    return $path;
}

function quotedBackRefusal(string $path): string
{
    /** @var HeaderSniffer $sniffer */
    $sniffer = app(HeaderSniffer::class);

    try {
        $sniffer->sniff($path, CsvPresetRegistry::ASN);

        return '';
    } catch (SniffMismatchException $e) {
        return $e->getMessage();
    }
}

it('does not quote the first line of the file back when the header does not match', function (): void {
    // Twenty cells, so the column-count arm passes and the signature arm is
    // the one that answers. A headerless export puts a data row here: for ASN
    // that is the posted date and the reader's own IBAN.
    $row = ['02-02-2026', QUOTED_BACK_IBAN, ...array_fill(0, 18, 'x')];
    $message = quotedBackRefusal(quotedBackCsv(implode(',', $row)));

    expect($message)->not->toBe('', 'the sniffer accepted a file that is not this export, so nothing was tested');
    expect($message)->not->toContain(QUOTED_BACK_IBAN)
        ->and($message)->not->toContain('02-02-2026');

    // The control: a refusal that says nothing at all passes every assertion
    // above and leaves the reader with no idea which column was wrong.
    expect($message)->toContain('Datum');
});

it('does not put the absolute path of the staged file in the refusal', function (): void {
    $missing = sys_get_temp_dir().'/quoted-back-'.QUOTED_BACK_IBAN.'-2026-07.csv';
    $message = quotedBackRefusal($missing);

    expect($message)->not->toBe('')
        ->and($message)->not->toContain($missing)
        ->and($message)->not->toContain(QUOTED_BACK_IBAN);
});
