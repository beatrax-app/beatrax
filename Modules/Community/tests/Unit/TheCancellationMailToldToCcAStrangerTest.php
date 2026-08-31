<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Modules\Community\Public\Dto\SupportResource;
use Modules\Community\Public\Services\SupportResourceProvider;

// The recipient guard named `?`, `&` and whitespace and stopped there, so RFC
// 6068's own separator walked straight through: a `to` of
// "cancel@vendor.test,harvest@evil.test" opened one mail addressed to both. `;`
// and a percent-encoded comma passed the same way, and the provider that loads
// the corpus applied no address validation at all, so this was the only gate.

it('refuses a recipient carrying a second address', function (string $to): void {
    $resource = new SupportResource(
        name: 'Vendor',
        type: 'merchant',
        cancelEmailTo: $to,
        cancelEmailSubject: 'Cancellation',
    );

    expect($resource->mailtoHref())->toBeNull();
})->with([
    'RFC 6068 comma' => ['cancel@vendor.test,harvest@evil.test'],
    'percent-encoded comma' => ['cancel@vendor.test%2Charvest@evil.test'],
    'semicolon' => ['cancel@vendor.test;harvest@evil.test'],
    'angle-bracket list' => ['<cancel@vendor.test>,<harvest@evil.test>'],
    'header injection' => ['cancel@vendor.test%0Abcc:harvest@evil.test'],
    'query parameter' => ['cancel@vendor.test?bcc=harvest@evil.test'],
    'not an address at all' => ['cancel'],
]);

it('still builds the mailto for a single ordinary address', function (): void {
    $resource = new SupportResource(
        name: 'Vendor',
        type: 'merchant',
        cancelEmailTo: 'cancel.desk+web@vendor-support.test',
        cancelEmailSubject: 'Cancellation',
    );

    expect($resource->mailtoHref())->toStartWith('mailto:cancel.desk+web@vendor-support.test?');
});

it('drops a corpus recipient that names two people before it is ever rendered', function (): void {
    $root = sys_get_temp_dir().'/beatrax-mailto-'.bin2hex(random_bytes(4));
    @mkdir($root.'/support', 0777, true);
    file_put_contents($root.'/support/nl.yaml', <<<'YAML'
        entries:
          - name: Vendor
            type: merchant
            cancel_email:
              to: "cancel@vendor.test,harvest@evil.test"
              subject: "Cancellation"
              body: "Please cancel."
          - name: Honest
            type: merchant
            cancel_email:
              to: "cancel@honest.test"
              subject: "Cancellation"
              body: "Please cancel."
        YAML);
    app(Repository::class)->set('community.corpus.root', $root);

    /** @var SupportResourceProvider $provider */
    $provider = app(SupportResourceProvider::class);

    $forged = $provider->forCounterparty('Vendor', 'merchant', 'nl');
    expect($forged?->cancelEmailTo)->toBeNull()
        ->and($forged?->cancelEmailSubject)->toBeNull()
        ->and($forged?->cancelEmailBody)->toBeNull()
        ->and($forged?->mailtoHref())->toBeNull();

    expect($provider->forCounterparty('Honest', 'merchant', 'nl')?->mailtoHref())
        ->toStartWith('mailto:cancel@honest.test?');
});
