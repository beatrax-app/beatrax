<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Services\CommunityCorpusQuery;

// merchants/eu.yaml carries the cross-border brands and its header states a row
// there is answered to every country at once. CorpusLoader stamps those rows
// with the region it reads off the filename, which matches no country, so the
// region filter used to delete the entire list the moment a reader named theirs.
function seedRegionScopedCorpusRow(DatabaseManager $db, string $pattern, string $name, ?string $region): void
{
    $db->connection()->table('community_merchant_mappings')->insert([
        'user_id' => null,
        'pattern' => mb_strtoupper($pattern),
        'generalized_pattern' => mb_strtolower($pattern),
        'name' => $name,
        'region' => $region,
        'contributor' => 'bundled',
        'created_at' => '2026-08-15T10:00:00Z',
        'updated_at' => '2026-08-15T10:00:00Z',
    ]);
}

it('answers a pan-European row to a reader who has named their country', function (): void {
    $db = app(DatabaseManager::class);
    seedRegionScopedCorpusRow($db, 'NETFLIX.COM', 'Netflix', 'EU');
    seedRegionScopedCorpusRow($db, 'ALBERT HEIJN', 'Albert Heijn', 'NL');
    seedRegionScopedCorpusRow($db, 'CARREFOUR', 'Carrefour', 'FR');

    $corpus = app(CommunityCorpusQuery::class);

    expect($corpus->lookupGeneralized('SEPA IDEAL NETFLIX.COM 4989', 'nl'))->toBe('Netflix');
    expect($corpus->lookupExact('NETFLIX.COM', 'nl'))->toBe('Netflix');
    expect($corpus->lookupGeneralized('CARD PAYMENT ALBERT HEIJN 1042', 'nl'))->toBe('Albert Heijn');
    // A different country's national row stays out of it.
    expect($corpus->lookupGeneralized('CARREFOUR CITY PARIS', 'nl'))->toBeNull();
});

it('answers a pan-European row to a reader with no country set', function (): void {
    $db = app(DatabaseManager::class);
    seedRegionScopedCorpusRow($db, 'SPOTIFY', 'Spotify', 'EU');

    expect(app(CommunityCorpusQuery::class)->lookupGeneralized('SPOTIFY P0A1B2C3', null))->toBe('Spotify');
});
