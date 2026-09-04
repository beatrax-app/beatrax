<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Ingestion\Internal\Adapters\Ics\PdfTextExtractor;

// The `integration` case shells out to the real pdftotext; a host without
// poppler runs the rest via `vendor/bin/pest --exclude-group=integration`.
$fixtureTxt = __DIR__.'/../../Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt';
$fixtureTinyPdf = __DIR__.'/../../Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf';

it('the redacted ICS text fixture contains zero 12-digit-or-longer runs', function () use ($fixtureTxt): void {
    $contents = file_get_contents($fixtureTxt);
    if ($contents === false) {
        throw new RuntimeException("Could not read ICS text fixture at {$fixtureTxt}");
    }

    $hits = PatternScan::count('/[0-9]{12,}/', $contents);

    expect($hits)->toBe(0,
        'Committed ICS text fixture must contain zero 12+ contiguous digit runs '
        .'(re-run scripts/anonymize_ics_text.php on the raw export to refresh the fixture).'
    );
})->group('phase-3');

it('the redacted ICS text fixture contains zero IBAN-shaped tokens other than the deterministic placeholder', function () use ($fixtureTxt): void {
    $contents = file_get_contents($fixtureTxt);
    if ($contents === false) {
        throw new RuntimeException("Could not read ICS text fixture at {$fixtureTxt}");
    }

    $hits = PatternScan::all('/\b[A-Z]{2}[0-9]{2}[A-Z0-9]{10,}\b/', $contents);

    foreach ($hits[0] as $match) {
        expect($match)->toBe('NL95BANK0000000000',
            'Committed ICS text fixture must only contain the deterministic '
            .'NL95BANK0000000000 placeholder, never a real IBAN-shaped token.'
        );
    }
})->group('phase-3');

it('the redacted ICS text fixture contains the KAARTHOUDER placeholder', function () use ($fixtureTxt): void {
    $contents = file_get_contents($fixtureTxt);
    if ($contents === false) {
        throw new RuntimeException("Could not read ICS text fixture at {$fixtureTxt}");
    }

    expect(str_contains($contents, 'KAARTHOUDER'))->toBeTrue(
        'Committed ICS text fixture must contain the literal KAARTHOUDER '
        .'placeholder so the parser\'s name-stripping pass can be black-box '
        .'tested against the fixture.'
    );
})->group('phase-3');

it('the redacted ICS text fixture contains a card-number placeholder', function () use ($fixtureTxt): void {
    $contents = file_get_contents($fixtureTxt);
    if ($contents === false) {
        throw new RuntimeException("Could not read ICS text fixture at {$fixtureTxt}");
    }

    expect(PatternScan::matches('/\*\*\*\*-\*\*\*\*-\*\*\*\*-/', $contents))->toBeTrue(
        'Committed ICS text fixture must contain the canonical card-number '
        .'placeholder ****-****-****-XXXX.'
    );
})->group('phase-3');

it('the tiny synthetic ICS PDF, after pdftotext extraction, contains zero PII-shaped strings', function () use ($fixtureTinyPdf): void {
    // Round-trip the fixture through the project's PdfTextExtractor so
    // the assertion exercises the exact flag set the ingestion path
    // uses — any future change to those flags is automatically reflected
    // here without a manual sync.
    $extractor = new PdfTextExtractor;
    $extracted = $extractor->extract($fixtureTinyPdf);

    expect(PatternScan::count('/[0-9]{12,}/', $extracted))->toBe(0,
        'pdftotext output for the tiny synthetic PDF must contain zero '
        .'12+ contiguous digit runs.'
    );

    $hits = PatternScan::all('/\b[A-Z]{2}[0-9]{2}[A-Z0-9]{10,}\b/', $extracted);
    foreach ($hits[0] as $match) {
        expect($match)->toBe('NL95BANK0000000000',
            'pdftotext output for the tiny synthetic PDF must only contain the '
            .'deterministic NL95BANK0000000000 placeholder, never a real IBAN-'
            .'shaped token.'
        );
    }
})->group('phase-3')->group('integration');
