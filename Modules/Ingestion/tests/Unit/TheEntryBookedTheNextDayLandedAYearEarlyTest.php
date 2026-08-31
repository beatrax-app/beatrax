<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Mt940Tag61Parser;

beforeEach(function (): void {
    $this->parser = $this->app->make(Mt940Tag61Parser::class);
});

// :61: dates the value with a year and the entry without one, so the entry's
// year is inferred from the two months. Reading "entry month later than value
// month" as last year is right for a December entry under a January value date
// and wrong for the far commoner month-end value booked the next working day,
// which it moved back a whole calendar year.
it('resolves the entry year from the distance between the two months', function (string $line, string $value, string $entry): void {
    $parsed = $this->parser->parse($line);

    expect($parsed->valueDate->toDateString())->toBe($value);
    expect($parsed->entryDate?->toDateString())->toBe($entry);
})->with([
    'month-end value booked the next day' => ['2601310201D100,00NTRFREF-EOM', '2026-01-31', '2026-02-01'],
    'year-end value booked in the new year' => ['2512300102D100,00NTRFREF-EOY', '2025-12-30', '2026-01-02'],
    'new-year value carrying the old year entry' => ['2601021231C100,00NTRFREF-XMAS', '2026-01-02', '2025-12-31'],
    'entry the day before the value' => ['2604020401C100,00NTRFREF-SAME', '2026-04-02', '2026-04-01'],
]);
