<?php

declare(strict_types=1);

use Modules\Import\Public\Services\PatternGeneralizer;

it('returns the generalized pattern for the given raw description', function (string $raw, string $expected): void {
    $generalizer = new PatternGeneralizer;

    expect($generalizer->generalize($raw))->toBe($expected);
})->with([
    'pin-tail at end is stripped' => ['BCK*SHELL PIETER NIEUW *0123', 'bck*shell pieter nieuw'],
    'terminal id token T-prefixed is stripped' => ['ALBERT HEIJN 1245 T07438', 'albert heijn'],
    'sepa kenmerk noise prefix dropped' => ['iDEAL Bestelling KENMERK:1234', 'ideal bestelling'],
    'sepa eref noise prefix dropped' => ['SEPA Incasso EREF:CONTRACT-2026', 'sepa incasso'],
    'amount and date fragment stripped' => ['Spotify 12,99 2026-05-20', 'spotify'],
    'amount with dot separator stripped' => ['Albert Heijn 12.50', 'albert heijn'],
    'empty input returns empty string' => ['', ''],
    'all-noise input collapses to empty string' => ['T07438 1245 *0123', ''],
    'mixed tokens preserve real words' => ['EUR 100,00 betaling', 'eur betaling'],
]);

it('collapses consecutive whitespace into single spaces in the output', function (): void {
    $generalizer = new PatternGeneralizer;

    expect($generalizer->generalize("BCK*SHELL    PIETER\tNIEUW *0123"))
        ->toBe('bck*shell pieter nieuw');
});
