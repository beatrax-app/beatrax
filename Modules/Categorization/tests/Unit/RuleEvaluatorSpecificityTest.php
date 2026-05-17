<?php

declare(strict_types=1);

use Modules\Categorization\Internal\Services\RuleEvaluator;

it('scores equals at 100', function (): void {
    expect(invokeScore('equals', 'spotify premium', 'SPOTIFY PREMIUM'))->toBe(100);
});

it('scores equals 0 when target differs', function (): void {
    expect(invokeScore('equals', 'spotify premium', 'SPOTIFY'))->toBe(0);
});

it('scores starts_with as 50 + len(value)', function (): void {
    // value="Spotify" (7 chars) on target "Spotify Premium"
    expect(invokeScore('starts_with', 'Spotify Premium', 'Spotify'))->toBe(57);
});

it('scores starts_with 0 when value is not a prefix', function (): void {
    expect(invokeScore('starts_with', 'Spotify Premium', 'Premium'))->toBe(0);
});

it('scores contains as 10 + len(value)', function (): void {
    // value="spotify" (7 chars) anywhere in target
    expect(invokeScore('contains', 'I love Spotify daily', 'spotify'))->toBe(17);
});

it('scores contains 0 when value not found', function (): void {
    expect(invokeScore('contains', 'Netflix bill', 'spotify'))->toBe(0);
});

it('contains longer literal beats contains shorter literal', function (): void {
    expect(invokeScore('contains', 'Spotify Premium', 'Spotify'))->toBe(17);
    expect(invokeScore('contains', 'Spotify Premium', 'Spo'))->toBe(13);
});

it('case-insensitive on equals via mb_strtolower', function (): void {
    expect(invokeScore('equals', 'SPOTIFY PREMIUM', 'spotify premium'))->toBe(100);
});

it('case-insensitive on starts_with via mb_strtolower', function (): void {
    expect(invokeScore('starts_with', 'spotify Premium', 'SPOTIFY'))->toBe(57);
});

it('case-insensitive on contains via mb_strtolower', function (): void {
    expect(invokeScore('contains', 'SPOTIFY PREMIUM', 'spotify'))->toBe(17);
});

it('scores unknown match operator as 0', function (): void {
    expect(invokeScore('regex', 'Spotify', 'spotify'))->toBe(0);
});

it('unicode value length is counted via mb_strlen, not strlen', function (): void {
    // value="café" — mb_strlen=4 (one multi-byte char), strlen=5.
    // contains score for a value of mb_strlen 4 is 10 + 4 = 14.
    expect(invokeScore('contains', 'Le café noir', 'café'))->toBe(14);
});

/**
 * Reflection-only helper to call the private static scoreMatch
 * method. Keeps the public API of RuleEvaluator minimal while letting
 * the unit test pin every score arm directly.
 */
function invokeScore(string $match, string $target, string $value): int
{
    $ref = new ReflectionClass(RuleEvaluator::class);
    $method = $ref->getMethod('scoreMatch');

    /** @var int $result */
    $result = $method->invoke(null, $match, $target, $value);

    return $result;
}
