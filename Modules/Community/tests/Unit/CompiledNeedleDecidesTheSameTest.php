<?php

declare(strict_types=1);

use Modules\Community\Public\Services\CorpusPatternMatcher;

// The needle-only work now happens once per corpus load instead of once per
// description, and this file is the guard on that trade: a matcher that is
// faster but decides differently silently re-attributes bank lines to the wrong
// merchant. Every case below is answered twice and the two must agree.
function needleByNeedleAnswer(string $haystack, string $needle): bool
{
    if ($needle === '' || $haystack === '') {
        return false;
    }

    if (! mb_check_encoding($haystack, 'UTF-8') || ! mb_check_encoding($needle, 'UTF-8')) {
        return false;
    }

    if (preg_match('/[\p{L}\p{N}]/u', $needle) !== 1) {
        return false;
    }

    $isWordEdge = static fn (string $character): bool => preg_match('/^[\p{L}\p{N}\p{M}]$/u', $character) === 1;

    $before = $isWordEdge(mb_substr($needle, 0, 1)) ? '(?<![\p{L}\p{N}])' : '';
    $after = $isWordEdge(mb_substr($needle, -1)) ? '(?![\p{L}\p{N}])' : '';

    return preg_match('#'.$before.preg_quote($needle, '#').$after.'#iu', $haystack) === 1;
}

$nfd = "cafe\u{0301}";

dataset('token cases', [
    'token at the very start' => ['KPN BV', 'KPN'],
    'token at the very end' => ['INCASSO KPN', 'KPN'],
    'token is the whole haystack' => ['LIDL', 'lidl'],
    'needle longer than the haystack' => ['KPN', 'KPN BV'],
    'case differs both ways' => ['albert heijn 1042', 'ALBERT HEIJN'],
    'substring of a longer word' => ['Europese incasso internet en mobiel', 'OBI'],
    'substring at a word start' => ['PARKING GARAGE CENTRUM', 'ING'],
    'substring mid-word' => ['Nordwind Media BV', 'RDW'],
    'bounded by punctuation' => ['CCV*ALBERT HEIJN 1234', 'ALBERT HEIJN'],
    'needle carrying an inner space' => ['ALBERT HEIJN 1042', 'heijn '],
    'needle opening with a digit' => ['CCV 24SEVEN SHOP', '24seven'],

    'accented, composed' => ['Café Zürich', 'café'],
    'accented, decomposed, inside a longer word' => ["betaling {$nfd}teria centraal", $nfd],
    'accented, decomposed, standing alone' => ["betaling {$nfd} centraal", $nfd],
    'combining mark at the needle start' => ["betaling \u{0301}abc centraal", "\u{0301}abc"],
    'cyrillic, standing alone' => ['ОПЛАТА ПЯТЁРОЧКА 1234', 'пятёрочка'],
    'cyrillic, inside a longer word' => ['ОПЛАТА ПЯТЁРОЧКАМАРКЕТ', 'пятёрочка'],
    'greek, case-folded' => ['ΑΓΟΡΑ ΚΩΤΣΟΒΟΛΟΣ ΑΘΗΝΑ', 'κωτσοβολος'],
    'han, standing alone' => ['支付 微信支付 1234', '微信支付'],
    'han, inside a longer run' => ['支付 微信支付宝 1234', '微信支付'],
    'arabic, standing alone' => ['دفع كارفور ١٢٣', 'كارفور'],
    'turkish dotted capital' => ['İSTANBUL MARKET', 'istanbul'],

    'needle ending in a dot' => ['AMAZON.NL BESTELLING', 'AMAZON.'],
    'needle ending in a plus' => ['CANAL+ ABONNEMENT', 'CANAL+'],
    'needle starting with a star' => ['*ALBERT HEIJN 1042', '*ALBERT'],
    'needle carrying the pattern delimiter' => ['BETALING #1 SHOP HAARLEM', '#1 shop'],
    'needle carrying a backslash' => ['A\\B STORE 12', 'a\\b'],
    'needle carrying parentheses' => ['STORE (NL) 12', '(nl)'],
    'needle carrying alternation' => ['PAY A|B SHOP', 'a|b'],
    'needle carrying a character class' => ['PAY [NS] TRAIN', '[ns]'],
    'needle carrying a quantifier' => ['PAY {2} SHOP', '{2}'],
    'needle that reads as a catastrophic pattern' => ['XX (A+)+$ YY', '(a+)+$'],
    'needle carrying a newline' => ["ALBERT\nHEIJN 1042", "albert\nheijn"],

    'punctuation-only dot' => ['bol.com bestelling', '.'],
    'punctuation-only hyphen' => ['ns-groep reizen', '-'],
    'punctuation-only ellipsis' => ['albert heijn 1234', '...'],
    'punctuation-only plus' => ['a+b', '+'],
    'symbol-only needle' => ['PAY 🍕 SHOP', '🍕'],

    'empty needle' => ['KPN BV', ''],
    'empty haystack' => ['', 'kpn'],
    'both empty' => ['', ''],
    'invalid utf-8 needle' => ['KPN BV', "kp\xC3\x28n"],
    'invalid utf-8 haystack' => ["KPN \xFF\xFE BV", 'kpn'],
    'invalid utf-8 on both sides' => ["KPN \xFF\xFE BV", "kp\xC3\x28n"],
]);

it('answers a token the same needle-by-needle and precompiled', function (string $haystack, string $needle): void {
    $expected = needleByNeedleAnswer($haystack, $needle);

    $compiled = CorpusPatternMatcher::compileToken($needle);
    $precompiled = $compiled !== null && CorpusPatternMatcher::matchesCompiled($compiled, $haystack);

    expect(CorpusPatternMatcher::containsToken($haystack, $needle))->toBe($expected)
        ->and($precompiled)->toBe($expected);
})->with('token cases');

// Agreement between two implementations is worth nothing if both answer "no" to
// everything, so the fixture is pinned to real answers at both poles.
it('pins the answers the two implementations have to agree on', function (): void {
    expect(CorpusPatternMatcher::containsToken('INCASSO KPN', 'KPN'))->toBeTrue()
        ->and(CorpusPatternMatcher::containsToken('Europese incasso internet en mobiel', 'OBI'))->toBeFalse()
        ->and(CorpusPatternMatcher::containsToken('支付 微信支付 1234', '微信支付'))->toBeTrue()
        ->and(CorpusPatternMatcher::containsToken('支付 微信支付宝 1234', '微信支付'))->toBeFalse()
        ->and(CorpusPatternMatcher::containsToken('XX (A+)+$ YY', '(a+)+$'))->toBeTrue()
        ->and(CorpusPatternMatcher::containsToken('bol.com bestelling', '.'))->toBeFalse();
});

it('compiles a needle once to a pattern that keeps answering', function (): void {
    $compiled = CorpusPatternMatcher::compileToken('albert heijn');

    expect($compiled)->toBeString()
        ->and(CorpusPatternMatcher::matchesCompiled((string) $compiled, 'CCV*ALBERT HEIJN 1042'))->toBeTrue()
        ->and(CorpusPatternMatcher::matchesCompiled((string) $compiled, 'ALBERT HEIJNSTRAAT 4'))->toBeFalse()
        ->and(CorpusPatternMatcher::matchesCompiled((string) $compiled, 'JUMBO 12'))->toBeFalse();
});

// A needle that can never match any haystack compiles to null, which is what
// lets the corpus scan drop the row at load rather than testing it against
// every description for the life of the process.
it('refuses to compile a needle that could never match', function (string $needle): void {
    expect(CorpusPatternMatcher::compileToken($needle))->toBeNull();
})->with([
    'empty' => [''],
    'a lone dot' => ['.'],
    'a lone hyphen' => ['-'],
    'an ellipsis' => ['...'],
    'an emoji' => ['🍕'],
    'invalid utf-8' => ["kp\xC3\x28n"],
]);
