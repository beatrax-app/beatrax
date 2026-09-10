<?php

declare(strict_types=1);

use Modules\Community\Public\Services\CorpusPatternMatcher;

// containsToken asserts a word boundary only on the edges where the needle's
// own edge is a word character. Three needle shapes fall through that rule and
// silently become a bare substring search — the exact behaviour the token
// anchoring exists to remove. None is reachable from the shipped corpus (all
// 9,168 literal patterns start alphanumeric and none carries a combining
// mark); all three are reachable from a user-typed alias.

it('refuses a needle with no word character at all rather than matching every hyphen', function (): void {
    expect(CorpusPatternMatcher::containsToken('bol.com bestelling', '.'))->toBeFalse()
        ->and(CorpusPatternMatcher::containsToken('ns-groep reizen', '-'))->toBeFalse()
        ->and(CorpusPatternMatcher::containsToken('albert heijn 1234', '...'))->toBeFalse();
});

it('still matches a needle whose edges are punctuation but whose body is not', function (): void {
    expect(CorpusPatternMatcher::containsToken('betaling amazon.nl 12', 'amazon.'))->toBeTrue()
        ->and(CorpusPatternMatcher::containsToken('abonnement canal+ maand', 'canal+'))->toBeTrue()
        ->and(CorpusPatternMatcher::containsToken('faktura zagreb d.d. 90', 'd.d.'))->toBeTrue();
});

it('asserts a trailing boundary after a needle that ends in a combining mark', function (): void {
    $composed = "cafe\u{0301}";

    expect(CorpusPatternMatcher::containsToken("betaling {$composed}teria centraal", $composed))->toBeFalse()
        ->and(CorpusPatternMatcher::containsToken("betaling {$composed} centraal", $composed))->toBeTrue();
});

// `-a-` is three characters, so MerchantAliasPattern's floor lets it be saved,
// and with punctuation at both edges it asserted nothing at all: it renamed
// every description carrying those three characters anywhere inside a word.
it('keeps a needle punctuated at both edges from matching inside a longer run', function (): void {
    expect(CorpusPatternMatcher::containsToken('reservering super-a-market 12', '-a-'))->toBeFalse()
        ->and(CorpusPatternMatcher::containsToken('reservering -a- 12', '-a-'))->toBeTrue()
        ->and(CorpusPatternMatcher::containsToken('ccv*albert heijn*1042', '*albert heijn*'))->toBeFalse()
        ->and(CorpusPatternMatcher::containsToken('ccv *albert heijn* 1042', '*albert heijn*'))->toBeTrue();
});

it('compiles no needle to a pattern that asserts nothing at either edge', function (string $needle): void {
    $compiled = (string) CorpusPatternMatcher::compileToken($needle);

    $asserts = str_contains($compiled, '(?<![\p{L}\p{N}])') || str_contains($compiled, '(?![\p{L}\p{N}])');

    expect($asserts)->toBeTrue('`'.$needle.'` compiled to '.$compiled.', which is a bare substring search.');
})->with(['-a-', '.nl.', '(ah)', '*albert heijn*', '&co&', 'amazon.', '*albert', 'd.d.', 'kpn']);
