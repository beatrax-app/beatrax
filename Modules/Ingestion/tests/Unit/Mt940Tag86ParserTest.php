<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Mt940Tag86Parser;

beforeEach(function (): void {
    $this->parser = $this->app->make(Mt940Tag86Parser::class);
});

it('parses a structured GVC 005 SEPA direct-debit narrative', function (): void {
    $content = '005?00DD?20EREF+INVOICE-2026-04?32SPOTIFY AB?31NL68BANK0000000001';

    $parsed = $this->parser->parse($content);

    expect($parsed->gvcCode)->toBe('005');
    expect($parsed->gvcKeywords['EREF'])->toBe('INVOICE-2026-04');
    expect($parsed->counterpartyName)->toBe('SPOTIFY AB');
    expect($parsed->counterpartyIban)->toBe('NL68BANK0000000001');
})->group('phase-2');

it('concatenates ?32 and ?33 into a single counterparty name', function (): void {
    $content = '100?32ALBERT HEIJN AMSTER?33DAM HOOFDDORPLEIN?31NL41BANK0000000002';

    $parsed = $this->parser->parse($content);

    expect($parsed->counterpartyName)->toBe('ALBERT HEIJN AMSTERDAM HOOFDDORPLEIN');
})->group('phase-2');

it('extracts an EREF value verbatim including the literal NOTPROVIDED placeholder', function (): void {
    $content = '100?20EREF+NOTPROVIDED?32STARBUCKS';

    $parsed = $this->parser->parse($content);

    // Promoting "NOTPROVIDED" to a null sourceRef is the adapter's job, not
    // this parser's.
    expect($parsed->gvcKeywords['EREF'])->toBe('NOTPROVIDED');
})->group('phase-2');

it('extracts every GVC keyword from the purpose subfields', function (): void {
    $content = '005?20EREF+REF-A+MREF+MAND-B+CRED+CRED-C+SVWZ+Subscription April+KREF+KREF-D+PURP+OTHR'
        .'?21BIC ABNANL2A+IBAN+NL00BANK?32SPOTIFY';

    $parsed = $this->parser->parse($content);

    expect($parsed->gvcKeywords['EREF'])->toBe('REF-A');
    expect($parsed->gvcKeywords['MREF'])->toBe('MAND-B');
    expect($parsed->gvcKeywords['CRED'])->toBe('CRED-C');
    expect($parsed->gvcKeywords['SVWZ'])->toBe('Subscription April');
    expect($parsed->gvcKeywords['KREF'])->toBe('KREF-D');
    expect($parsed->gvcKeywords['PURP'])->toBe('OTHR');
    expect($parsed->gvcKeywords['BIC'])->toBe('ABNANL2A');
    expect($parsed->gvcKeywords['IBAN'])->toBe('NL00BANK');
})->group('phase-2');

it('treats an unstructured narrative (no leading GVC code) as raw description', function (): void {
    $content = 'Free-form narrative without ?NN markers, just plain text.';

    $parsed = $this->parser->parse($content);

    expect($parsed->gvcCode)->toBeNull();
    expect($parsed->description)->toContain('Free-form');
    expect($parsed->counterpartyName)->toBeNull();
    expect($parsed->counterpartyIban)->toBeNull();
})->group('phase-2');

it('reassembles a multi-line :86: into a single Mt940Narrative', function (): void {
    // The lexer joins continuation lines with `\n`. The parser must scan
    // ?NN codes across line boundaries.
    $content = "005?20EREF+INVOICE-2026-04\n?32SPOTIFY AB?31NL68BANK0000000001";

    $parsed = $this->parser->parse($content);

    expect($parsed->counterpartyName)->toBe('SPOTIFY AB');
    expect($parsed->counterpartyIban)->toBe('NL68BANK0000000001');
})->group('phase-2');
