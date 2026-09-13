<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Community\Public\Services\SupportResourceProvider;

// The country bucket was keyed on the entry's word key alone while a lookup also
// filters on type, so two entries in one country file that share a name kept
// only the last one read — a merchant entry and a government entry of the same
// name silently deleted each other at load, before any lookup ran.

function typedSupportCorpus(): SupportResourceProvider
{
    $root = sys_get_temp_dir().'/beatrax-typed-support-'.bin2hex(random_bytes(4));
    @mkdir($root.'/support', 0777, true);

    file_put_contents($root.'/support/nl.yaml', <<<'YAML'
        entries:
          - name: Sanitas
            type: merchant
            cancel_url: "https://example.test/nl/cancel"
            notes: "the insurer"
          - name: Sanitas
            type: government
            help_url: "https://example.test/nl/help"
            notes: "the health authority"
        YAML);

    app(Repository::class)->set('community.corpus.root', $root);

    return app(SupportResourceProvider::class);
}

it('keeps both entries a country files under one name', function (): void {
    $provider = typedSupportCorpus();

    expect($provider->forCounterparty('Sanitas', 'merchant', 'nl')?->notes)->toBe('the insurer')
        ->and($provider->forCounterparty('Sanitas', 'government', 'nl')?->notes)->toBe('the health authority');
});

it('still prefers the most specific key when a name is filed twice', function (): void {
    $root = sys_get_temp_dir().'/beatrax-typed-support-'.bin2hex(random_bytes(4));
    @mkdir($root.'/support', 0777, true);
    file_put_contents($root.'/support/nl.yaml', <<<'YAML'
        entries:
          - name: Albert Heijn
            type: merchant
            notes: "the shop"
          - name: Albert Heijn
            type: government
            notes: "not a shop"
          - name: Albert Heijn Premium
            type: merchant
            notes: "the tier"
        YAML);
    app(Repository::class)->set('community.corpus.root', $root);

    /** @var SupportResourceProvider $provider */
    $provider = app(SupportResourceProvider::class);

    expect($provider->forCounterparty('Albert Heijn Premium', 'merchant', 'nl')?->notes)->toBe('the tier')
        ->and($provider->forCounterparty('Albert Heijn', 'merchant', 'nl')?->notes)->toBe('the shop');
});
