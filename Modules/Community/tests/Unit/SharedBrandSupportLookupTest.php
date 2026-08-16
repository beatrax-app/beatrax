<?php

declare(strict_types=1);

use Modules\Community\Public\Services\SupportResourceProvider;

/*
 * The shipped corpus, not a fixture.
 *
 * Before the lookup was country-scoped, two countries carrying the same brand
 * meant the alphabetically-later file silently replaced the earlier one for
 * every user. The corpus worked around it twice over: brands were dropped
 * outright, and the survivors were renamed to "Verisure Suomi" and "Verisure
 * Italia" to keep the keys apart.
 *
 * Both workarounds cost coverage. The rename cost the most quietly — a key is
 * matched as a word prefix of the counterparty, so "verisure suomi" only ever
 * matched a statement that literally said both words, and a plain VERISURE
 * charge in Finland found nothing at all.
 *
 * The scoping made the collision safe, so these assert the shipped data
 * actually carries the entries and resolves each to its own country. A
 * fixture cannot show that: the point is the corpus, not the algorithm.
 */

/**
 * @return array{0: string, 1: string}
 */
function supportNotesFor(string $name, string $type, string $country): array
{
    /** @var SupportResourceProvider $provider */
    $provider = app(SupportResourceProvider::class);
    $resource = $provider->forCounterparty($name, $type, $country);

    expect($resource)->not->toBeNull("{$name} ({$type}) must resolve for {$country}");

    return [$resource->name, (string) $resource->notes];
}

it('resolves a shared brand to the country the user files taxes in', function (string $country, string $marker): void {
    [, $notes] = supportNotesFor('Verisure', 'merchant', $country);

    expect($notes)->toContain($marker);
})->with([
    // Each marker is a phone number or address only that country's entry
    // carries, so a cross-country leak cannot pass by coincidence.
    'Belgium' => ['be', '0800 93 000'],
    'Sweden' => ['se', '020-7 24 365'],
    'Denmark' => ['dk', '70 24 73 65'],
    'Finland' => ['fi', '010 217 9000'],
    'Italy' => ['it', '800.999.848'],
    'Spain' => ['es', '900 909 139'],
]);

// The rename is the point: a statement line rarely carries the country word,
// and the key has to match what the bank actually printed.
it('matches a bare brand line, not only one naming the country', function (string $line): void {
    [, $notes] = supportNotesFor($line, 'merchant', 'fi');

    expect($notes)->toContain('010 217 9000');
})->with([
    'bare brand' => ['Verisure'],
    'with a legal form' => ['VERISURE OY'],
    'with a payment suffix' => ['Verisure Oy Helsinki'],
]);

// The collision that motivated the scoping in the first place: a Swiss health
// insurer and a Spanish one, neither of which may answer for the other.
it('keeps the two Sanitas insurers apart', function (): void {
    [, $swiss] = supportNotesFor('Sanitas', 'merchant', 'ch');
    [, $spanish] = supportNotesFor('Sanitas', 'merchant', 'es');

    expect($swiss)->toContain('+41 844 150 150')
        ->and($spanish)->not->toContain('+41 844 150 150')
        ->and($spanish)->toContain('seguro de salud');
});

// NAV is Norway's welfare administration and Hungary's tax authority — the
// same three letters for two entirely unrelated bodies.
it('keeps the two NAV authorities apart', function (): void {
    [, $hungarian] = supportNotesFor('NAV', 'government', 'hu');
    [, $norwegian] = supportNotesFor('NAV', 'government', 'no');

    expect($hungarian)->toContain('1819')
        ->and($norwegian)->toContain('55 55 33 33')
        ->and($hungarian)->not->toContain('55 55 33 33');
});
