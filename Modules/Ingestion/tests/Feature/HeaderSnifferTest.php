<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Asn\AsnCsvHeaderProfile;
use Modules\Ingestion\Public\Dto\SniffResult;
use Modules\Ingestion\Public\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Services\HeaderSniffer;

beforeEach(function (): void {
    $this->sniffer = $this->app->make(HeaderSniffer::class);
});

/**
 * Writes `$prefix.$body` to a fresh tempnam-keyed `.xml` file and registers a
 * shutdown cleanup so the temp file is removed when the PHP process exits.
 * Used by the CAMT.053 sniff cases below.
 */
function writeTempXml(string $body, string $prefix = ''): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-camt-').'.xml';
    file_put_contents($tmp, $prefix.$body);
    register_shutdown_function(static function () use ($tmp): void {
        @unlink($tmp);
    });

    return $tmp;
}

it('accepts the real anonymized ASN fixture and reports its profile', function (): void {
    $result = $this->sniffer->sniff(
        base_path('tests/fixtures/asn-sample-1.csv'),
        AsnCsvHeaderProfile::FORMAT,
    );

    expect($result)->toBeInstanceOf(SniffResult::class);
    expect($result->format)->toBe(AsnCsvHeaderProfile::FORMAT);
    expect($result->delimiter)->toBe(AsnCsvHeaderProfile::DELIMITER);
    expect($result->hasHeader)->toBe(AsnCsvHeaderProfile::HAS_HEADER);
    expect($result->encoding)->toBe(AsnCsvHeaderProfile::SOURCE_ENCODING);
    expect($result->columnCount)->toBe(AsnCsvHeaderProfile::EXPECTED_COLUMN_COUNT);
});

it('rejects a non-CSV extension with a user-readable message', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-bad-ext-').'.txt';
    file_put_contents($tmp, "not a csv\n");

    try {
        expect(fn () => $this->sniffer->sniff($tmp, AsnCsvHeaderProfile::FORMAT))
            ->toThrow(SniffMismatchException::class, "doesn't look like a CSV");
    } finally {
        @unlink($tmp);
    }
});

it('rejects a CSV with the wrong column count', function (): void {
    // Pretend ING or Rabobank — same delimiter, different (smaller) layout.
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-bad-cols-').'.csv';
    file_put_contents($tmp, "a,b,c,d,e\n01-01-2026,X,Y,Z,W\n");

    try {
        expect(fn () => $this->sniffer->sniff($tmp, AsnCsvHeaderProfile::FORMAT))
            ->toThrow(SniffMismatchException::class, 'Expected '.AsnCsvHeaderProfile::EXPECTED_COLUMN_COUNT);
    } finally {
        @unlink($tmp);
    }
});

it('rejects an unreadable / non-existent file', function (): void {
    expect(fn () => $this->sniffer->sniff('/no/such/path-xyz.csv', AsnCsvHeaderProfile::FORMAT))
        ->toThrow(SniffMismatchException::class);
});

it('rejects an unknown declared format', function (): void {
    expect(fn () => $this->sniffer->sniff(
        base_path('tests/fixtures/asn-sample-1.csv'),
        'asn-mt940',
    ))->toThrow(SniffMismatchException::class, 'Unsupported sniff target');
});

it('accepts the anonymised ASN CAMT.053 001.02 fixture and returns format=asn-camt053', function (): void {
    $result = $this->sniffer->sniff(
        base_path('tests/fixtures/asn-camt053-sample-1.xml'),
        'asn-camt053',
    );

    expect($result)->toBeInstanceOf(SniffResult::class);
    expect($result->format)->toBe('asn-camt053');
    expect($result->encoding)->toBe('UTF-8');
})->group('phase-2');

it('accepts a minimal 001.02 CAMT.053 fragment', function (): void {
    $tmp = writeTempXml('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt/></Document>');

    $result = $this->sniffer->sniff($tmp, 'asn-camt053');

    expect($result->format)->toBe('asn-camt053');
})->group('phase-2');

it('accepts a minimal 001.03 CAMT.053 fragment', function (): void {
    $tmp = writeTempXml('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.03"><BkToCstmrStmt/></Document>');

    $result = $this->sniffer->sniff($tmp, 'asn-camt053');

    expect($result->format)->toBe('asn-camt053');
})->group('phase-2');

it('accepts a minimal 001.08 CAMT.053 fragment', function (): void {
    $tmp = writeTempXml('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"><BkToCstmrStmt/></Document>');

    $result = $this->sniffer->sniff($tmp, 'asn-camt053');

    expect($result->format)->toBe('asn-camt053');
})->group('phase-2');

it('rejects a CAMT.052 file (wrong family) with a user-readable message', function (): void {
    $tmp = writeTempXml('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.08"><BkToCstmrAcctRpt/></Document>');

    expect(fn () => $this->sniffer->sniff($tmp, 'asn-camt053'))
        ->toThrow(SniffMismatchException::class, 'CAMT.053');
})->group('phase-2');

it('rejects a non-XML payload declared as asn-camt053', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'asn-csv-as-xml-').'.xml';
    file_put_contents($tmp, "Datum,Je rekening,Tegenrekening\n01-04-2026,NL00ASNB0123456789,...\n");

    try {
        expect(fn () => $this->sniffer->sniff($tmp, 'asn-camt053'))
            ->toThrow(SniffMismatchException::class);
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('rejects an XML payload with a non-xml extension declared as asn-camt053', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'camt-no-ext-').'.txt';
    file_put_contents($tmp, '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"/>');

    try {
        expect(fn () => $this->sniffer->sniff($tmp, 'asn-camt053'))
            ->toThrow(SniffMismatchException::class, 'XML');
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('accepts a CAMT.053 XML with a leading UTF-8 BOM + XML declaration + comments', function (): void {
    $tmp = writeTempXml(
        '<?xml version="1.0" encoding="UTF-8"?>'
        ."\n<!-- exported by ASN -->\n"
        .'<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"><BkToCstmrStmt/></Document>',
        prefix: "\xEF\xBB\xBF",
    );

    $result = $this->sniffer->sniff($tmp, 'asn-camt053');

    expect($result->format)->toBe('asn-camt053');
})->group('phase-2');
