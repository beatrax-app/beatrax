<?php

declare(strict_types=1);

use Modules\Import\Public\Services\UploadFilename;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;

it('folds every path-traversal character out of the stem', function (): void {
    expect(UploadFilename::sanitise('../../etc/passwd.csv', '.csv'))->toBe('passwd.csv');
});

it('collapses a run of unsafe characters into one underscore', function (): void {
    expect(UploadFilename::sanitise('my  weird&&&name.csv', '.csv'))->toBe('my_weird_name.csv');
});

it('folds an all-unsafe stem to a single underscore rather than to the fallback', function (): void {
    expect(UploadFilename::sanitise('€€€.csv', '.csv'))->toBe('_.csv');
});

it('falls back to "upload" only when the stem is empty', function (): void {
    expect(UploadFilename::sanitise('.csv', '.csv'))->toBe('upload.csv');
    expect(UploadFilename::sanitise('', '.csv'))->toBe('upload.csv');
});

it('takes the extension from the declared format, not from the uploaded name', function (): void {
    expect(UploadFilename::sanitise('statement.csv', '.xml'))->toBe('statement.xml');
});

it('keeps hyphens and underscores, which are already filesystem-safe', function (): void {
    expect(UploadFilename::sanitise('asn-2025_01.csv', '.csv'))->toBe('asn-2025_01.csv');
});

it('maps every upload format the wizard accepts to its stored extension', function (): void {
    expect([
        CsvPresetRegistry::ASN => UploadFilename::extensionFor(CsvPresetRegistry::ASN),
        SourceFormat::Camt053->value => UploadFilename::extensionFor(SourceFormat::Camt053->value),
        SourceFormat::Mt940->value => UploadFilename::extensionFor(SourceFormat::Mt940->value),
        'ics-pdf' => UploadFilename::extensionFor('ics-pdf'),
        'paypal-csv' => UploadFilename::extensionFor('paypal-csv'),
        'eml' => UploadFilename::extensionFor('eml'),
        'mbox' => UploadFilename::extensionFor('mbox'),
        'n26-csv' => UploadFilename::extensionFor('n26-csv'),
        'revolut-csv' => UploadFilename::extensionFor('revolut-csv'),
        'ing-nl-csv' => UploadFilename::extensionFor('ing-nl-csv'),
    ])->toBe([
        'asn-csv' => '.csv',
        'camt053' => '.xml',
        'mt940' => '.sta',
        'ics-pdf' => '.pdf',
        'paypal-csv' => '.csv',
        'eml' => '.eml',
        'mbox' => '.mbox',
        'n26-csv' => '.csv',
        'revolut-csv' => '.csv',
        'ing-nl-csv' => '.csv',
    ]);
});

it('gives the bank step the same three extensions its own match gave it', function (): void {
    // Named rather than taken from cases(): the enum also carries the formats
    // the other upload steps accept, only these four reach the bank step, and
    // the ING one is a CSV preset rather than an enum case.
    $bankStepFormats = [
        CsvPresetRegistry::ASN,
        CsvPresetRegistry::ING_NL,
        SourceFormat::Camt053->value,
        SourceFormat::Mt940->value,
    ];

    $viaSeam = [];
    $viaOldMatch = [];
    foreach ($bankStepFormats as $format) {
        $viaSeam[$format] = UploadFilename::extensionFor($format);
        $viaOldMatch[$format] = match ($format) {
            'camt053' => '.xml',
            'mt940' => '.sta',
            default => '.csv',
        };
    }

    expect($viaSeam)->toBe($viaOldMatch);
});

it('stores an unknown format as CSV rather than guessing from the name', function (): void {
    expect(UploadFilename::extensionFor('not-a-format'))->toBe('.csv');
});
