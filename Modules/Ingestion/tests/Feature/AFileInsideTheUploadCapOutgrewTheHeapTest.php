<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Exceptions\ReadCeilingExceededException;
use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Internal\Support\SourceFileCeilings;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\HeaderSniffer;

// The upload cap bounds the FILE. It bounds nothing about what the file costs
// to hold, and three readers cost a different multiple of it. Each case below
// is a file the wizard's own 10 MB rule accepts and the phone's 128 MB heap
// does not survive: an exhausted heap is E_ERROR, so there is no exception to
// catch, no log line, and no wizard left to render the failure on.

beforeEach(function (): void {
    $this->sniffer = $this->app->make(HeaderSniffer::class);
});

function fileTheCapAcceptsAt(string $extension, string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'ceiling-').$extension;
    file_put_contents($path, $body);
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });

    return $path;
}

function n26HeaderRowWithExtraColumns(int $extra): string
{
    return 'Booking Date,Partner Name,Amount (EUR),Payment Reference,Value Date'
        .str_repeat(',x', $extra);
}

function n26DataRowWithExtraColumns(int $extra): string
{
    return '2026-02-05,ACME,-12.34,ref,2026-02-05'.str_repeat(',1', $extra);
}

it('refuses a header row too wide for one fgetcsv call to hold', function (): void {
    $path = fileTheCapAcceptsAt('.csv', n26HeaderRowWithExtraColumns(40_000)."\n".n26DataRowWithExtraColumns(40_000)."\n");

    expect(filesize($path))->toBeLessThan(10 * 1024 * 1024);

    expect(fn () => $this->sniffer->sniff($path, CsvPresetRegistry::N26))
        ->toThrow(ReadCeilingExceededException::class);
});

// The header is the width league/csv reports; fgetcsv allocates the width the
// LINE has. A narrow header over one enormous row read as an ordinary file
// right up to the allocation that ended the process.
it('refuses a data row too wide for one fgetcsv call to hold, under an ordinary header', function (): void {
    $path = fileTheCapAcceptsAt('.csv', n26HeaderRowWithExtraColumns(0)."\n".n26DataRowWithExtraColumns(40_000)."\n");

    expect(fn () => $this->sniffer->sniff($path, CsvPresetRegistry::N26))
        ->toThrow(ReadCeilingExceededException::class);
});

it('reads a CSV whose longest line sits under the ceiling', function (): void {
    $wide = SourceFileCeilings::MAX_CSV_LINE_BYTES - 1_024;
    $filler = str_repeat('a', $wide);
    $path = fileTheCapAcceptsAt('.csv', n26HeaderRowWithExtraColumns(0)."\n"
        .'2026-02-05,ACME,-12.34,'.$filler.",2026-02-05\n");

    expect($this->sniffer->sniff($path, CsvPresetRegistry::N26)->format)->toBe(CsvPresetRegistry::N26);
});

// genkgo builds the whole statement before the adapter yields a row, so the
// entries are what the read costs -- not the bytes. 62,497 of them fitted in
// 9.5 MB and died; 3,676 of them fitted in the same 9.5 MB and did not.
it('refuses a CAMT.053 statement booking more entries than the heap holds', function (): void {
    $entry = '<Ntry><Amt Ccy="EUR">1.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>'
        .'<BookgDt><Dt>2026-02-05</Dt></BookgDt><ValDt><Dt>2026-02-05</Dt></ValDt></Ntry>';

    $path = fileTheCapAcceptsAt('.xml', camtAround(str_repeat($entry, SourceFileCeilings::MAX_CAMT_ENTRIES + 1)));

    expect(filesize($path))->toBeLessThan(10 * 1024 * 1024);

    expect(fn () => $this->sniffer->sniff($path, 'camt053'))
        ->toThrow(ReadCeilingExceededException::class);
});

// <Ntry is also the opening of <NtryDtls> and <NtryRef>, which every real entry
// carries. Counted as entries they would refuse a statement for its own detail
// blocks -- eleven of them here against a ceiling of twenty thousand.
it('counts booked entries and not the detail blocks inside them', function (): void {
    $entry = '<Ntry><Amt Ccy="EUR">1.00</Amt><NtryRef>R</NtryRef>'
        .'<NtryDtls><TxDtls><Refs><EndToEndId>E</EndToEndId></Refs></TxDtls></NtryDtls></Ntry>';

    $path = fileTheCapAcceptsAt('.xml', camtAround(str_repeat($entry, 10)));

    expect($this->sniffer->sniff($path, 'camt053')->format)->toBe('camt053');
});

it('reads the shipped ASN statement, which books 229 of the 20,000 entries allowed', function (): void {
    expect($this->sniffer->sniff(base_path('tests/fixtures/asn-camt053-sample-1.xml'), 'camt053')->format)
        ->toBe('camt053');
});

function camtAround(string $entries): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt>'
        .'<GrpHdr><MsgId>X</MsgId><CreDtTm>2026-05-12T21:12:27+02:00</CreDtTm></GrpHdr>'
        .'<Stmt><Id>S</Id><CreDtTm>2026-05-12T21:12:27+02:00</CreDtTm>'
        .'<Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>'
        .$entries
        .'</Stmt></BkToCstmrStmt></Document>';
}

// A ceiling is not the reader that decides a file exists. HeaderSniffer::sniff()
// refuses an unreadable path before any arm runs, so a ceiling that cannot open
// one has nothing to add and says nothing rather than raising a second, worse
// answer to a question already answered.
it('says nothing about a file it cannot open, which the sniff already refused', function (): void {
    $missing = sys_get_temp_dir().'/ceiling-no-such-file-'.uniqid().'.csv';

    SourceFileCeilings::refuseLongCsvLine($missing);
    SourceFileCeilings::refuseCamtEntryCount($missing);

    expect(fn () => $this->sniffer->sniff($missing, CsvPresetRegistry::N26))
        ->toThrow(SniffMismatchException::class);
});
