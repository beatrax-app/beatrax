<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Asn\AsnCsvHeaderProfile;
use Modules\Ingestion\Public\Dto\SniffResult;
use Modules\Ingestion\Public\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Services\HeaderSniffer;

beforeEach(function (): void {
    $this->sniffer = $this->app->make(HeaderSniffer::class);
});

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
