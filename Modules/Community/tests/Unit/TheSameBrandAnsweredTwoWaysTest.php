<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Community\Public\Services\SupportResourceProvider;

// A country-less lookup used to walk the country files in glob order and take
// the first hit, so the profile page (which passes the reader's country) and the
// savings insights (which pass none) answered the same brand two different ways
// in the same app: Sanitas resolved to the Swiss insurer with no country and to
// the Spanish one with `es`.

/**
 * @param  array<string, list<string>>  $byCountry  country code => brand names in that file
 */
function disputedSupportCorpus(array $byCountry): SupportResourceProvider
{
    $root = sys_get_temp_dir().'/beatrax-disputed-'.bin2hex(random_bytes(4));
    @mkdir($root.'/support', 0777, true);

    foreach ($byCountry as $code => $names) {
        $entries = '';
        foreach ($names as $name) {
            $entries .= <<<YAML
                  - name: {$name}
                    type: merchant
                    cancel_url: "https://example.test/{$code}/cancel"
                    notes: "{$code} {$name}"

                YAML;
        }
        file_put_contents($root."/support/{$code}.yaml", "entries:\n".$entries);
    }

    app(Repository::class)->set('community.corpus.root', $root);

    return app(SupportResourceProvider::class);
}

it('refuses to pick a country for a brand two of them answer differently', function (): void {
    $provider = disputedSupportCorpus(['ch' => ['Sanitas'], 'es' => ['Sanitas']]);

    expect($provider->forCounterparty('Sanitas', 'merchant', null))->toBeNull();
});

it('still answers when only one country carries the brand', function (): void {
    $provider = disputedSupportCorpus(['ch' => ['Sanitas'], 'nl' => ['Kruidvat']]);

    expect($provider->forCounterparty('Kruidvat', 'merchant', null)?->notes)->toBe('nl Kruidvat');
});

it('keeps answering the country the reader actually named', function (): void {
    $provider = disputedSupportCorpus(['ch' => ['Sanitas'], 'es' => ['Sanitas']]);

    expect($provider->forCounterparty('Sanitas', 'merchant', 'es')?->notes)->toBe('es Sanitas')
        ->and($provider->forCounterparty('Sanitas', 'merchant', 'ch')?->notes)->toBe('ch Sanitas');
});

it('refuses a disputed brand for a reader whose own country does not carry it', function (): void {
    $provider = disputedSupportCorpus(['ch' => ['Sanitas'], 'es' => ['Sanitas'], 'de' => ['Rewe']]);

    expect($provider->forCounterparty('Sanitas', 'merchant', 'de'))->toBeNull();
});

it('lets the shared file answer a brand the countries also carry', function (): void {
    $provider = disputedSupportCorpus([
        'ch' => ['Sanitas'],
        'es' => ['Sanitas'],
        'international' => ['Sanitas'],
    ]);

    expect($provider->forCounterparty('Sanitas', 'merchant', null)?->notes)->toBe('international Sanitas');
});

it('gives the shipped corpus one answer for a brand six countries sell', function (): void {
    /** @var SupportResourceProvider $provider */
    $provider = app(SupportResourceProvider::class);

    // Six national Verisure companies with six cancellation lines: the country
    // the reader named is the only thing that can choose between them.
    expect($provider->forCounterparty('Verisure', 'merchant', null))->toBeNull()
        ->and($provider->forCounterparty('Verisure', 'merchant', 'se')?->notes)->toContain('020-7 24 365');
});

it('still resolves a shipped brand only one country carries without a country', function (): void {
    /** @var SupportResourceProvider $provider */
    $provider = app(SupportResourceProvider::class);

    expect($provider->forCounterparty('Belastingdienst', 'government', null)?->name)->toBe('Belastingdienst');
});
