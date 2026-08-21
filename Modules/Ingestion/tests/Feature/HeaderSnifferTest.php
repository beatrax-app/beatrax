<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Asn\AsnCsvHeaderProfile;
use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Dto\SniffResult;
use Modules\Ingestion\Public\Services\HeaderSniffer;

beforeEach(function (): void {
    $this->sniffer = $this->app->make(HeaderSniffer::class);
});

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
            ->toThrow(SniffMismatchException::class, 'Expected 19 or 20 columns');
    } finally {
        @unlink($tmp);
    }
});

it('accepts the 19-column ASN variant (no trailing Categorie column)', function (): void {
    // Real ASN exports also ship a 19-column shape ending at
    // `Afschriftnummer`, which the gold fixture reproduces minus `Categorie`.
    $body = file_get_contents(base_path('tests/fixtures/asn-sample-1.csv'));
    expect($body)->toBeString();
    /** @var string $body */
    $lines = explode("\n", $body);
    foreach ($lines as $i => $line) {
        if ($line === '') {
            continue;
        }
        // Not CSV-aware, but the fixture has no comma inside its final field.
        $lastComma = strrpos($line, ',');
        if ($lastComma !== false) {
            $lines[$i] = substr($line, 0, $lastComma);
        }
    }
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-asn-19col-').'.csv';
    file_put_contents($tmp, implode("\n", $lines));

    try {
        $result = $this->sniffer->sniff($tmp, AsnCsvHeaderProfile::FORMAT);
        expect($result->format)->toBe(AsnCsvHeaderProfile::FORMAT);
        expect($result->columnCount)->toBe(19);
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
        'asn-no-such-format',
    ))->toThrow(SniffMismatchException::class, 'Unsupported sniff target');
});

it('accepts the anonymised ASN CAMT.053 001.02 fixture and returns format=camt053', function (): void {
    $result = $this->sniffer->sniff(
        base_path('tests/fixtures/asn-camt053-sample-1.xml'),
        'camt053',
    );

    expect($result)->toBeInstanceOf(SniffResult::class);
    expect($result->format)->toBe('camt053');
    expect($result->encoding)->toBe('UTF-8');
})->group('phase-2');

it('accepts a minimal 001.02 CAMT.053 fragment', function (): void {
    $tmp = writeTempXml('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt/></Document>');

    $result = $this->sniffer->sniff($tmp, 'camt053');

    expect($result->format)->toBe('camt053');
})->group('phase-2');

it('accepts a minimal 001.03 CAMT.053 fragment', function (): void {
    $tmp = writeTempXml('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.03"><BkToCstmrStmt/></Document>');

    $result = $this->sniffer->sniff($tmp, 'camt053');

    expect($result->format)->toBe('camt053');
})->group('phase-2');

it('accepts a minimal 001.08 CAMT.053 fragment', function (): void {
    $tmp = writeTempXml('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"><BkToCstmrStmt/></Document>');

    $result = $this->sniffer->sniff($tmp, 'camt053');

    expect($result->format)->toBe('camt053');
})->group('phase-2');

it('rejects a CAMT.052 file (wrong family) with a user-readable message', function (): void {
    $tmp = writeTempXml('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.08"><BkToCstmrAcctRpt/></Document>');

    expect(fn () => $this->sniffer->sniff($tmp, 'camt053'))
        ->toThrow(SniffMismatchException::class, 'CAMT.053');
})->group('phase-2');

it('rejects a non-XML payload declared as camt053', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'asn-csv-as-xml-').'.xml';
    file_put_contents($tmp, "Datum,Je rekening,Tegenrekening\n01-04-2026,NL57ASNB0123456789,...\n");

    try {
        expect(fn () => $this->sniffer->sniff($tmp, 'camt053'))
            ->toThrow(SniffMismatchException::class);
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('rejects an XML payload with a non-xml extension declared as camt053', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'camt-no-ext-').'.txt';
    file_put_contents($tmp, '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"/>');

    try {
        expect(fn () => $this->sniffer->sniff($tmp, 'camt053'))
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

    $result = $this->sniffer->sniff($tmp, 'camt053');

    expect($result->format)->toBe('camt053');
})->group('phase-2');

it('accepts an ASN MT940 .sta file', function (): void {
    $result = $this->sniffer->sniff(
        base_path('tests/fixtures/asn-mt940-sample-1.sta'),
        'mt940',
    );

    expect($result->format)->toBe('mt940');
})->group('phase-2');

it('accepts an MT940 file with a leading SWIFT block-1 envelope', function (): void {
    $body = '{1:F01ASNBNL50XXXX0000000000}{2:O9400000000ASNBNL50XXXX00000000000000000000N}{3:{108:MT940}}{4:'
        ."\n:20:STMT-2026-04\n:25:NL57ASNB0123456789\n:28C:1/1\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRF NONREF\n-}";
    $tmp = tempnam(sys_get_temp_dir(), 'mt940-envelope-').'.sta';
    file_put_contents($tmp, $body);

    try {
        $result = $this->sniffer->sniff($tmp, 'mt940');
        expect($result->format)->toBe('mt940');
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('rejects a CSV file declared as mt940', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'csv-as-mt940-').'.sta';
    file_put_contents($tmp, "Datum,Je rekening\n01-04-2026,NL57ASNB0123456789\n");

    try {
        expect(fn () => $this->sniffer->sniff($tmp, 'mt940'))
            ->toThrow(SniffMismatchException::class, 'MT940');
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('rejects an XML file declared as mt940', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'xml-as-mt940-').'.sta';
    file_put_contents($tmp, '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"/>');

    try {
        expect(fn () => $this->sniffer->sniff($tmp, 'mt940'))
            ->toThrow(SniffMismatchException::class);
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('rejects an MT940 file with a wrong extension declared as mt940', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'mt940-no-ext-').'.xml';
    file_put_contents($tmp, ":20:STMT-2026-04\n:25:NL57ASNB0123456789\n");

    try {
        expect(fn () => $this->sniffer->sniff($tmp, 'mt940'))
            ->toThrow(SniffMismatchException::class, 'MT940');
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');
