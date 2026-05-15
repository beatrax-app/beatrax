<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Repo-wide anonymisation-sweep guard. Reads the committed ICS PDF
 * fixtures and asserts no PII-shaped strings can creep back in. Green
 * by design — the file's job is to fail the build the moment any
 * future contributor pastes a raw ICS export into the fixture
 * directory.
 *
 * Case 5 (`->group('integration')`) shells out to `pdftotext` via
 * `Symfony\Component\Process\Process` to round-trip the tiny synthetic
 * PDF; CI hosts without poppler installed can exclude the integration
 * group via `vendor/bin/pest --exclude-group=integration`.
 */
$fixtureTxt = __DIR__.'/../../Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt';
$fixtureTinyPdf = __DIR__.'/../../Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf';

it('the redacted ICS text fixture contains zero 12-digit-or-longer runs', function () use ($fixtureTxt): void {
    $contents = file_get_contents($fixtureTxt);
    if ($contents === false) {
        throw new RuntimeException("Could not read ICS text fixture at {$fixtureTxt}");
    }

    $hits = preg_match_all('/[0-9]{12,}/', $contents);

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

    $hits = [];
    preg_match_all('/\b[A-Z]{2}[0-9]{2}[A-Z0-9]{10,}\b/', $contents, $hits);

    foreach ($hits[0] as $match) {
        expect($match)->toBe('NL00BANK0000000000',
            'Committed ICS text fixture must only contain the deterministic '
            .'NL00BANK0000000000 placeholder, never a real IBAN-shaped token.'
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

    expect(preg_match('/\*\*\*\*-\*\*\*\*-\*\*\*\*-/', $contents))->toBe(1,
        'Committed ICS text fixture must contain the canonical card-number '
        .'placeholder ****-****-****-XXXX.'
    );
})->group('phase-3');

it('the tiny synthetic ICS PDF, after pdftotext extraction, contains zero PII-shaped strings', function () use ($fixtureTinyPdf): void {
    $process = new Process([
        'pdftotext',
        '-layout',
        '-enc', 'UTF-8',
        '-eol', 'unix',
        '-nopgbrk',
        $fixtureTinyPdf,
        '-',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue(
        'pdftotext must extract the tiny synthetic PDF cleanly. Stderr: '
        .$process->getErrorOutput()
    );

    $extracted = $process->getOutput();

    expect(preg_match_all('/[0-9]{12,}/', $extracted))->toBe(0,
        'pdftotext output for the tiny synthetic PDF must contain zero '
        .'12+ contiguous digit runs.'
    );

    $hits = [];
    preg_match_all('/\b[A-Z]{2}[0-9]{2}[A-Z0-9]{10,}\b/', $extracted, $hits);
    foreach ($hits[0] as $match) {
        expect($match)->toBe('NL00BANK0000000000',
            'pdftotext output for the tiny synthetic PDF must only contain the '
            .'deterministic NL00BANK0000000000 placeholder, never a real IBAN-'
            .'shaped token.'
        );
    }
})->group('phase-3')->group('integration');
