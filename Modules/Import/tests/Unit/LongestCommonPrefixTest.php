<?php

declare(strict_types=1);

use Modules\Import\Internal\Services\LongestCommonPrefix;

it('returns the lowercased trimmed shared prefix when one exists', function (array $patterns, string $expected): void {
    $svc = new LongestCommonPrefix;
    expect($svc->compute($patterns))->toBe($expected);
})->with([
    'albert heijn variants share an 11-char prefix' => [
        ['Albert Heijn 1245', 'Albert Heijn 0917'],
        'albert heijn',
    ],
    'three variants reduce to the shorter shared prefix' => [
        ['ALBERT HEIJN', 'ALBERT HEIJN STORE', 'ALBERT H'],
        'albert h',
    ],
    'unrelated tokens collapse to empty (LCP < 4 chars)' => [
        ['shell', 'spotify'],
        '',
    ],
    'three-char identical pair is rejected by min-length guard' => [
        ['abc', 'abc'],
        '',
    ],
    'emoji prefix is preserved when shared and ≥ 4 chars' => [
        ['🏦 abcde', '🏦 abcdf'],
        '🏦 abcd',
    ],
    'empty first input returns empty' => [
        ['', 'abcdefg'],
        '',
    ],
    'empty subsequent input returns empty' => [
        ['abcdefg', ''],
        '',
    ],
    'identical multi-char strings return the full lower-cased value' => [
        ['Albertus', 'Albertus'],
        'albertus',
    ],
    'leading whitespace is trimmed off the returned prefix' => [
        ['  spotify premium 1', '  spotify premium 2'],
        'spotify premium',
    ],
]);

it('throws InvalidArgumentException when fewer than two patterns are supplied', function (array $patterns): void {
    $svc = new LongestCommonPrefix;
    $svc->compute($patterns);
})
    ->with([
        'size 0' => [[]],
        'size 1' => [['solo-pattern-string']],
    ])
    ->throws(InvalidArgumentException::class);
