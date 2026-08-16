<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Services\CommunityCorpusQuery;

/*
 * The corpus lookups used to run under `LIMIT 1000` (substring) and
 * `LIMIT 500` (regex), ordered by id. Ordered that way the cap does not
 * sample the corpus, it truncates it in bundled-file order: once the corpus
 * grew past those counts every later pattern became unmatchable, and nothing
 * reported it — the lookup simply returned null and the descriptor stayed
 * raw. eu.yaml sat entirely beyond the cut, so the most-used brands in the
 * whole corpus were the ones that stopped resolving.
 */

function seedCorpusRow(DatabaseManager $db, int $ordinal, string $pattern, string $generalized, string $name): void
{
    $db->connection()->table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => $pattern,
        'generalized_pattern' => $generalized,
        'name' => $name,
        'contributor' => 'bundled',
        'created_at' => '2026-08-15T10:00:00Z',
        'updated_at' => '2026-08-15T10:00:00Z',
    ]);
}

it('matches a substring row that sits far beyond the old scan cap', function (): void {
    $db = app(DatabaseManager::class);

    for ($i = 0; $i < 1200; $i++) {
        seedCorpusRow($db, $i, "FILLER{$i}", "filler{$i}", "Filler {$i}");
    }

    // Row 1201: past the old LIMIT 1000 and therefore previously invisible.
    seedCorpusRow($db, 1200, 'LIDL', 'lidl', 'Lidl');

    /** @var CommunityCorpusQuery $corpus */
    $corpus = app(CommunityCorpusQuery::class);

    expect($corpus->lookupGeneralized('CARD PAYMENT LIDL 1234 EINDHOVEN'))->toBe('Lidl');
});

it('matches a regex row that sits far beyond the old regex cap', function (): void {
    $db = app(DatabaseManager::class);

    for ($i = 0; $i < 600; $i++) {
        seedCorpusRow($db, $i, 'regex:FILLERPATTERN'.$i, '', "Filler {$i}");
    }

    seedCorpusRow($db, 600, 'regex:^AMZN MKTP', '', 'Amazon');

    /** @var CommunityCorpusQuery $corpus */
    $corpus = app(CommunityCorpusQuery::class);

    expect($corpus->lookupRegex('AMZN MKTP NL*RT4L92'))->toBe('Amazon');
});

it('still answers with the earliest matching row when several could match', function (): void {
    $db = app(DatabaseManager::class);

    // Insertion order is file-sort order, and first match wins. Removing the
    // cap must not disturb that: it is what lets a specific pattern placed
    // earlier win over a broader one later.
    seedCorpusRow($db, 0, 'ALBERT HEIJN', 'albert heijn', 'Albert Heijn');
    seedCorpusRow($db, 1, 'ALBERT', 'albert', 'Albert');

    /** @var CommunityCorpusQuery $corpus */
    $corpus = app(CommunityCorpusQuery::class);

    expect($corpus->lookupGeneralized('ALBERT HEIJN 1234'))->toBe('Albert Heijn');
});
