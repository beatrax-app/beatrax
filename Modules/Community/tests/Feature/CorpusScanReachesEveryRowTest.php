<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Services\CommunityCorpusQuery;

// The lookups used to run under LIMIT 1000 (substring) and LIMIT 500 (regex)
// ordered by id, which truncates the corpus in bundled-file order rather than
// sampling it: every pattern past the cut silently returned null, and eu.yaml —
// the most-used brands in the corpus — sat entirely beyond it.

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

    // Insertion order is file-sort order and first match wins — removing the cap
    // must not disturb that, or a broader pattern later in the file starts winning.
    seedCorpusRow($db, 0, 'ALBERT HEIJN', 'albert heijn', 'Albert Heijn');
    seedCorpusRow($db, 1, 'ALBERT', 'albert', 'Albert');

    /** @var CommunityCorpusQuery $corpus */
    $corpus = app(CommunityCorpusQuery::class);

    expect($corpus->lookupGeneralized('ALBERT HEIJN 1234'))->toBe('Albert Heijn');
});
