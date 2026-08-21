<?php

declare(strict_types=1);

use Modules\Community\Public\Services\CorpusPatternMatcher;

// containsToken asserts a word boundary only on the edges where the needle's
// own edge is a word character. Two needle shapes fall through that rule and
// silently become a bare substring search — the exact behaviour the token
// anchoring exists to remove. Neither is reachable from the shipped corpus
// (all 6,751 literal patterns start alphanumeric and none carries a combining
// mark); both are reachable from a user-typed alias.

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
