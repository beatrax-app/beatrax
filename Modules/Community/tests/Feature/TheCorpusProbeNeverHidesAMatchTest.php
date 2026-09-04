<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Services\CommunityCorpusQuery;

// The generalized tier skips a corpus row whose needle the description does not
// contain literally, which is sound because the compiled pattern is a quoted
// literal between two lookarounds. Sound has three edges, and each of these
// rows is one a naive stripos filter would wrongly skip.

function corpusProbeRow(DatabaseManager $db, string $pattern, string $name): void
{
    $db->connection()->table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => mb_strtoupper($pattern),
        'generalized_pattern' => mb_strtolower($pattern),
        'name' => $name,
        'region' => null,
        'contributor' => 'bundled',
        'created_at' => '2026-09-04T10:00:00Z',
        'updated_at' => '2026-09-04T10:00:00Z',
    ]);
}

// Caseless UTF-8 matching folds exactly two codepoints onto an ASCII letter —
// checked against every codepoint of the BMP and SMP, not read off a table —
// and a byte-wise search folds neither.
it('still finds a name a long s or a kelvin sign spells', function (): void {
    $db = app(DatabaseManager::class);
    corpusProbeRow($db, 'STANDARD BANK', 'Standard Bank');
    corpusProbeRow($db, 'KELVIN COOLING', 'Kelvin Cooling');

    $corpus = app(CommunityCorpusQuery::class);

    expect($corpus->lookupGeneralized("\u{017F}tandard bank amsterdam", null))->toBe('Standard Bank')
        ->and($corpus->lookupGeneralized("\u{212A}elvin cooling bv", null))->toBe('Kelvin Cooling');
});

// A needle outside ASCII cannot be pre-filtered by a byte-wise search at all:
// stripos folds A-Z and nothing else, so it would miss its own needle spelled
// in capitals.
it('still finds a name whose needle is not ascii', function (): void {
    corpusProbeRow(app(DatabaseManager::class), 'CAFÉ CENTRAAL', 'Café Centraal');

    expect(app(CommunityCorpusQuery::class)->lookupGeneralized('PIN CAFÉ CENTRAAL 1042', null))
        ->toBe('Café Centraal');
});

it('still refuses a token that only appears inside a longer word', function (): void {
    $db = app(DatabaseManager::class);
    corpusProbeRow($db, 'OBI', 'OBI Baumarkt');
    corpusProbeRow($db, 'ALBERT HEIJN', 'Albert Heijn');

    $corpus = app(CommunityCorpusQuery::class);

    expect($corpus->lookupGeneralized('MOBIEL ABONNEMENT', null))->toBeNull()
        ->and($corpus->lookupGeneralized('CARD PAYMENT ALBERT HEIJN 1042', null))->toBe('Albert Heijn')
        ->and($corpus->lookupGeneralized('', null))->toBeNull();
});
