<?php

declare(strict_types=1);

use Modules\Community\Public\Services\CorpusPatternMatcher;
use Psr\Log\NullLogger;

function matcher(): CorpusPatternMatcher
{
    return new CorpusPatternMatcher(new NullLogger);
}

it('matches a literal pattern as a case-insensitive substring', function (): void {
    expect(matcher()->matches('ALBERT HEIJN', 'CCV*ALBERT HEIJN AMSTERDAM 1234'))->toBeTrue();
    expect(matcher()->matches('albert heijn', 'CCV*ALBERT HEIJN AMSTERDAM'))->toBeTrue();
    expect(matcher()->matches('JUMBO', 'ALBERT HEIJN'))->toBeFalse();
});

it('matches a regex: pattern anchored against the whole haystack', function (): void {
    expect(matcher()->matches('regex:^ALBERT HEIJN \d+$', 'ALBERT HEIJN 1234'))->toBeTrue();
    expect(matcher()->matches('regex:^ALBERT HEIJN \d+$', 'MIJN ALBERT HEIJN 1234'))->toBeFalse();
    expect(matcher()->matches('regex:RUNDFUNK|ARD ZDF', 'BEITRAGSSERVICE ARD ZDF DEUTSCHLANDRADIO'))->toBeTrue();
});

it('treats an invalid regex as a non-match instead of throwing', function (): void {
    expect(matcher()->matches('regex:[unterminated', 'anything'))->toBeFalse();
});

it('rejects an over-long regex body as a non-match without running it', function (): void {
    // The length cap is the deterministic ReDoS guard: a body past the cap is
    // refused before it ever compiles or runs against the haystack.
    $body = str_repeat('a', CorpusPatternMatcher::MAX_REGEX_BODY_LENGTH + 1);

    expect(matcher()->matches('regex:'.$body, str_repeat('a', 1000)))->toBeFalse();
});

it('does not hang on a catastrophic-backtracking regex, returning a non-match', function (): void {
    // (a+)+$ against a long non-matching run backtracks exponentially, but
    // pcre.backtrack_limit bounds it: the match bails to a non-match fast
    // rather than hanging the matcher. The generous ceiling still fails loud
    // if the guard/limit ever regresses to an unbounded run.
    $start = microtime(true);
    $result = matcher()->matches('regex:(a+)+$', str_repeat('a', 40).'!');

    expect($result)->toBeFalse()
        ->and(microtime(true) - $start)->toBeLessThan(2.0);
});

it('reports whether a pattern is a regex', function (): void {
    expect(matcher()->isRegex('regex:^foo'))->toBeTrue();
    expect(matcher()->isRegex('ALBERT HEIJN'))->toBeFalse();
});

it('never matches an empty pattern or haystack', function (): void {
    expect(matcher()->matches('', 'ALBERT HEIJN'))->toBeFalse();
    expect(matcher()->matches('ALBERT HEIJN', ''))->toBeFalse();
    expect(matcher()->matches('regex:', 'ALBERT HEIJN'))->toBeFalse();
});
