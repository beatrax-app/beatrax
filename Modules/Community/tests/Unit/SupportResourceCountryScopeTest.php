<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Community\Public\Services\SupportResourceProvider;

/*
 * The support map used to be keyed on merchant NAME alone, with files read in
 * sorted order — so when two countries carried the same brand, the
 * alphabetically-later file silently replaced the earlier one for EVERY user.
 * A Spanish user asking how to cancel Sanitas got the Swiss health insurer's
 * route. The corpus worked around it by forbidding duplicate names outright,
 * which cost real coverage rather than fixing the lookup.
 */

function supportCorpusFixture(string $root): void
{
    foreach (['ch' => 'Swiss', 'es' => 'Spanish', 'international' => 'Global'] as $code => $flavour) {
        $dir = $root.'/support';
        @mkdir($dir, 0777, true);
        file_put_contents($dir."/{$code}.yaml", <<<YAML
            entries:
              - name: Sanitas
                type: merchant
                cancel_url: "https://example.test/{$code}/cancel"
                notes: "{$flavour} Sanitas"
            YAML);
    }

    // Only the shared file carries this one, so every country must find it.
    file_put_contents($root.'/support/international.yaml', <<<'YAML'
        entries:
          - name: Sanitas
            type: merchant
            cancel_url: "https://example.test/international/cancel"
            notes: "Global Sanitas"
          - name: Spotify
            type: merchant
            cancel_url: "https://example.test/spotify/cancel"
            notes: "Shared brand"
        YAML);
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/beatrax-support-'.bin2hex(random_bytes(4));
    supportCorpusFixture($this->root);
    app(Repository::class)->set('community.corpus.root', $this->root);
});

it('prefers the resource from the country the user files taxes in', function (): void {
    /** @var SupportResourceProvider $provider */
    $provider = app(SupportResourceProvider::class);

    expect($provider->forCounterparty('Sanitas', 'merchant', 'es')?->notes)->toBe('Spanish Sanitas');
});

it('gives a different country a different resource for the same brand', function (): void {
    // The whole point: before the fix both of these returned the same row,
    // because one file had overwritten the other at load time.
    /** @var SupportResourceProvider $provider */
    $provider = app(SupportResourceProvider::class);

    expect($provider->forCounterparty('Sanitas', 'merchant', 'ch')?->notes)->toBe('Swiss Sanitas')
        ->and($provider->forCounterparty('Sanitas', 'merchant', 'es')?->notes)->toBe('Spanish Sanitas');
});

it('falls back to the shared file for a country that has no entry of its own', function (): void {
    /** @var SupportResourceProvider $provider */
    $provider = app(SupportResourceProvider::class);

    expect($provider->forCounterparty('Spotify', 'merchant', 'es')?->notes)->toBe('Shared brand');
});

it('prefers the shared file over an unrelated country when the user has none', function (): void {
    /** @var SupportResourceProvider $provider */
    $provider = app(SupportResourceProvider::class);

    // 'de' has no file at all here, so the shared entry must win over ch/es.
    expect($provider->forCounterparty('Sanitas', 'merchant', 'de')?->notes)->toBe('Global Sanitas');
});

it('still finds a resource when the user has set no country', function (): void {
    // Unset country keeps the old behaviour — search everything — rather than
    // showing nothing to a user who never picked a tax country.
    /** @var SupportResourceProvider $provider */
    $provider = app(SupportResourceProvider::class);

    expect($provider->forCounterparty('Sanitas', 'merchant', null))->not->toBeNull();
});
