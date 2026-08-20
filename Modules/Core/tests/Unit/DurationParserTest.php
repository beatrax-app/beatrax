<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Internal\Console\Support\DurationParser;

it('subtracts the parsed duration from "now"', function (string $input, string $expectedShift): void {
    $now = CarbonImmutable::parse('2026-05-20 09:00:00');
    $parser = new DurationParser;

    $result = $parser->subFromNow($input, $now);

    expect($result->toIso8601String())->toBe(
        CarbonImmutable::parse('2026-05-20 09:00:00 '.$expectedShift)->toIso8601String(),
    );
})->with([
    '30 days' => ['30d', '-30 days'],
    '7 days' => ['7d', '-7 days'],
    '12 hours' => ['12h', '-12 hours'],
    '2 weeks' => ['2w', '-2 weeks'],
    'uppercase D' => ['30D', '-30 days'],
    'uppercase W' => ['1W', '-1 week'],
]);

it('rejects invalid duration strings with InvalidArgumentException naming the regex', function (string $input): void {
    $parser = new DurationParser;
    $now = CarbonImmutable::parse('2026-05-20 09:00:00');

    expect(fn () => $parser->subFromNow($input, $now))
        ->toThrow(
            InvalidArgumentException::class,
            "Duration must match /^\\d+[dhw]\$/ (e.g. '30d', '7d', '12h', '2w'). Got: '".$input."'.",
        );
})->with([
    'minutes token (ambiguous)' => ['30m'],
    'seconds token' => ['30s'],
    'non-numeric' => ['abc'],
    'empty string' => [''],
    'decimal' => ['1.5d'],
    'negative' => ['-5d'],
    'trailing space' => ['30d '],
    'leading space' => [' 30d'],
    'missing unit' => ['30'],
    'too many digits and unit chars' => ['30dd'],
]);

it('rejects a zero-amount duration with a dedicated message that mentions the catastrophic-prune risk', function (string $input): void {
    $parser = new DurationParser;
    $now = CarbonImmutable::parse('2026-05-20 09:00:00');

    expect(fn () => $parser->subFromNow($input, $now))
        ->toThrow(
            InvalidArgumentException::class,
            "Duration amount must be a positive integer (zero would delete every row). Got: '".$input."'.",
        );
})->with([
    'zero days' => ['0d'],
    'zero hours' => ['0h'],
    'zero weeks' => ['0w'],
    'zero days uppercase' => ['0D'],
    'multi-digit zero' => ['00d'],
]);
