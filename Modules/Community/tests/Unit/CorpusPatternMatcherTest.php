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

it('reports whether a pattern is a regex', function (): void {
    expect(matcher()->isRegex('regex:^foo'))->toBeTrue();
    expect(matcher()->isRegex('ALBERT HEIJN'))->toBeFalse();
});

it('never matches an empty pattern or haystack', function (): void {
    expect(matcher()->matches('', 'ALBERT HEIJN'))->toBeFalse();
    expect(matcher()->matches('ALBERT HEIJN', ''))->toBeFalse();
    expect(matcher()->matches('regex:', 'ALBERT HEIJN'))->toBeFalse();
});
