<?php

declare(strict_types=1);

use Modules\Community\Public\Services\SupportResourceProvider;

// Asserts the shipped corpus, not a fixture: the point is that the data carries
// these entries and resolves each to its own country. Before country scoping,
// collisions were worked around by renaming — and since a key matches as a word
// prefix, "Verisure Suomi" never matched a bare VERISURE charge in Finland.

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

it('matches a bare brand line, not only one naming the country', function (string $line): void {
    [, $notes] = supportNotesFor($line, 'merchant', 'fi');

    expect($notes)->toContain('010 217 9000');
})->with([
    'bare brand' => ['Verisure'],
    'with a legal form' => ['VERISURE OY'],
    'with a payment suffix' => ['Verisure Oy Helsinki'],
]);

// Sanitas is a Swiss health insurer and, separately, a Spanish one.
it('keeps the two Sanitas insurers apart', function (): void {
    [, $swiss] = supportNotesFor('Sanitas', 'merchant', 'ch');
    [, $spanish] = supportNotesFor('Sanitas', 'merchant', 'es');

    expect($swiss)->toContain('+41 844 150 150')
        ->and($spanish)->not->toContain('+41 844 150 150')
        ->and($spanish)->toContain('seguro de salud');
});

// NAV is Norway's welfare administration and, separately, Hungary's tax authority.
it('keeps the two NAV authorities apart', function (): void {
    [, $hungarian] = supportNotesFor('NAV', 'government', 'hu');
    [, $norwegian] = supportNotesFor('NAV', 'government', 'no');

    expect($hungarian)->toContain('1819')
        ->and($norwegian)->toContain('55 55 33 33')
        ->and($hungarian)->not->toContain('55 55 33 33');
});
