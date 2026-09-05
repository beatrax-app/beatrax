<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Community\Public\Services\SupportResourceProvider;
use Modules\Core\Public\Enums\ExternalUrlRefusal;

// The corpus is contributed, so a cancel_url is an outside party's string. Two
// Blade templates used to accept `http://` from it while this reader refused
// it, and a value this reader drops leaves a card that says nothing about the
// route it is holding.
function withheldCorpusProvider(string $cancelUrl): SupportResourceProvider
{
    $root = sys_get_temp_dir().'/beatrax-withheld-'.bin2hex(random_bytes(4));
    @mkdir($root.'/support', 0777, true);

    file_put_contents($root.'/support/nl.yaml', <<<YAML
        entries:
          - name: Voorbeeld
            type: merchant
            cancel_url: "{$cancelUrl}"
            support_url: "https://voorbeeld.test/help"
        YAML);

    app(Repository::class)->set('community.corpus.root', $root);

    return app(SupportResourceProvider::class);
}

it('withholds a corpus link the gate refuses and names why', function (string $cancelUrl, ExternalUrlRefusal $refusal): void {
    $resource = withheldCorpusProvider($cancelUrl)->forCounterparty('Voorbeeld', 'merchant', 'nl');

    expect($resource)->not->toBeNull()
        ->and($resource?->cancelUrl)->toBeNull()
        ->and($resource?->withheld)->toBe(['cancel_url' => $refusal])
        ->and($resource?->supportUrl)->toBe('https://voorbeeld.test/help');
})->with([
    'a plaintext address' => ['http://voorbeeld.test/opzeggen', ExternalUrlRefusal::NotHttps],
    'a script scheme' => ['javascript:alert(1)', ExternalUrlRefusal::NotHttps],
    'an authority that reads as another host' => ['https://voorbeeld.test@evil.test/', ExternalUrlRefusal::CarriesCredentials],
    'this machine' => ['https://localhost/opzeggen', ExternalUrlRefusal::HostIsNotPublic],
    'a port that is not the web' => ['https://voorbeeld.test:4000/opzeggen', ExternalUrlRefusal::NonDefaultPort],
]);

it('keeps the card renderable when every link it holds was withheld', function (): void {
    $root = sys_get_temp_dir().'/beatrax-withheld-all-'.bin2hex(random_bytes(4));
    @mkdir($root.'/support', 0777, true);
    file_put_contents($root.'/support/nl.yaml', <<<'YAML'
        entries:
          - name: Voorbeeld
            type: merchant
            cancel_url: "http://voorbeeld.test/opzeggen"
        YAML);
    app(Repository::class)->set('community.corpus.root', $root);

    $resource = app(SupportResourceProvider::class)->forCounterparty('Voorbeeld', 'merchant', 'nl');

    // hasAny() is what the profile page asks before it renders the card at all.
    // A resource holding only withheld routes used to answer false, so the one
    // surface that could have said so never appeared.
    expect($resource?->hasAny())->toBeTrue();
});

it('leaves a sound corpus link untouched', function (): void {
    $resource = withheldCorpusProvider('https://voorbeeld.test/opzeggen')->forCounterparty('Voorbeeld', 'merchant', 'nl');

    expect($resource?->cancelUrl)->toBe('https://voorbeeld.test/opzeggen')
        ->and($resource?->withheld)->toBe([]);
});
