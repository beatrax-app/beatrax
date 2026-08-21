<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Services\CommunityCorpusQuery;
use Modules\Community\Public\Services\CorpusPatternMatcher;

// The scan compiles each needle once at load and matches the compiled pattern
// per description. containsToken() is the reference it has to keep agreeing
// with, row for row and in the same order, because this decision is which
// merchant a bank line is attributed to.
function seedGeneralizedRow(DatabaseManager $db, string $needle, string $name): void
{
    $db->connection()->table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => mb_strtoupper($needle === '' ? $name : $needle),
        'generalized_pattern' => $needle,
        'name' => $name,
        'contributor' => 'bundled',
        'created_at' => '2026-08-15T10:00:00Z',
        'updated_at' => '2026-08-15T10:00:00Z',
    ]);
}

/**
 * @param  list<array{needle: string, name: string}>  $rows
 */
function scanRowsWithContainsToken(array $rows, string $description): ?string
{
    usort(
        $rows,
        static fn (array $left, array $right): int => mb_strlen($right['needle']) <=> mb_strlen($left['needle']),
    );

    foreach ($rows as $row) {
        if (CorpusPatternMatcher::containsToken($description, $row['needle'])) {
            return $row['name'];
        }
    }

    return null;
}

it('resolves every description exactly as a containsToken scan over the same rows', function (): void {
    $db = app(DatabaseManager::class);

    $rows = [
        ['needle' => 'albert', 'name' => 'Albert'],
        ['needle' => 'albert heijn', 'name' => 'Albert Heijn'],
        ['needle' => 'obi', 'name' => 'OBI'],
        ['needle' => 'kpn', 'name' => 'KPN'],
        ['needle' => 'amazon.', 'name' => 'Amazon'],
        ['needle' => 'canal+', 'name' => 'Canal+'],
        ['needle' => 'café', 'name' => 'Café'],
        ['needle' => "cafe\u{0301}", 'name' => 'Cafe Decomposed'],
        ['needle' => 'пятёрочка', 'name' => 'Pyaterochka'],
        ['needle' => '微信支付', 'name' => 'WeChat Pay'],
        ['needle' => 'كارفور', 'name' => 'Carrefour'],
        ['needle' => '(a+)+$', 'name' => 'Literal Quantifier Shop'],
        ['needle' => '#1 shop', 'name' => 'Hash Shop'],
        ['needle' => 'a|b', 'name' => 'Alternation Shop'],
        ['needle' => '24seven', 'name' => 'Twenty Four Seven'],
        // Needles that can never match anything: the scan drops them at load,
        // and the reference answers false for them on every description, so the
        // two only agree if dropping them is genuinely inert.
        ['needle' => '.', 'name' => 'Dot'],
        ['needle' => '-', 'name' => 'Hyphen'],
        ['needle' => '...', 'name' => 'Ellipsis'],
        ['needle' => '🍕', 'name' => 'Pizza'],
        ['needle' => "kp\xC3\x28n", 'name' => 'Broken Encoding'],
    ];

    foreach ($rows as $row) {
        seedGeneralizedRow($db, $row['needle'], $row['name']);
    }

    /** @var CommunityCorpusQuery $corpus */
    $corpus = app(CommunityCorpusQuery::class);

    $descriptions = [
        'ALBERT HEIJN 1042 AMSTERDAM',
        'ALBERT 998 PRAHA',
        'Europese incasso internet en mobiel',
        'BOUWMARKT OBI ZWOLLE',
        'INCASSO KPN',
        'PARKING GARAGE CENTRUM',
        'AMAZON.NL BESTELLING',
        'AMAZONE REIZEN',
        'CANAL+ ABONNEMENT',
        'Café Zürich',
        "betaling cafe\u{0301}teria centraal",
        "betaling cafe\u{0301} centraal",
        'ОПЛАТА ПЯТЁРОЧКА 1234',
        'ОПЛАТА ПЯТЁРОЧКАМАРКЕТ',
        '支付 微信支付 1234',
        '支付 微信支付宝 1234',
        'دفع كارفور ١٢٣',
        'XX (A+)+$ YY',
        'BETALING #1 SHOP HAARLEM',
        'PAY A|B SHOP',
        'CCV 24SEVEN SHOP',
        'bol.com bestelling',
        'ns-groep reizen',
        'PAY 🍕 SHOP',
        'GEEN ENKELE MATCH HIER',
        '',
    ];

    foreach ($descriptions as $description) {
        expect($corpus->lookupGeneralized($description))
            ->toBe(scanRowsWithContainsToken($rows, $description), "description: {$description}");
    }
});

it('still hands a description to the most specific needle that matches it', function (): void {
    $db = app(DatabaseManager::class);

    seedGeneralizedRow($db, 'albert heijn', 'Albert Heijn');
    seedGeneralizedRow($db, 'albert', 'Albert');

    /** @var CommunityCorpusQuery $corpus */
    $corpus = app(CommunityCorpusQuery::class);

    expect($corpus->lookupGeneralized('ALBERT HEIJN 1042'))->toBe('Albert Heijn')
        ->and($corpus->lookupGeneralized('ALBERT 998'))->toBe('Albert');
});
