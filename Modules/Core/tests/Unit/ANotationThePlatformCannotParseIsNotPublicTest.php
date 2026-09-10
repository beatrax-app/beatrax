<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\ExternalUrlRefusal;
use Modules\Core\Public\Support\ExternalUrl;
use Modules\Core\Public\Support\PublicHost;

// The first spelling of this rule fell through to "contains a dot", so every
// notation FILTER_VALIDATE_IP cannot parse was answered public. On the desktop
// shell a link is not a browser tab: target=_blank opens another window of this
// application, same preload, sandbox false.

it('refuses a loopback address written in a notation the parser does not take', function (string $host): void {
    expect(PublicHost::names($host))->toBeFalse();

    expect(ExternalUrl::refusalFor('https://'.$host.'/support'))
        ->toBe(ExternalUrlRefusal::HostIsNotPublic);
})->with([
    'zero-padded octal' => '0177.0.0.1',
    'two-part dotted' => '127.1',
    'hexadecimal' => '0x7f.0x0.0x0.0x1',
    'decimal integer' => '2130706433',
]);

it('refuses a private range written plainly', function (string $host): void {
    expect(PublicHost::names($host))->toBeFalse();
})->with(['10.0.0.1', '192.168.1.20', '172.16.4.4', '127.0.0.1', '169.254.169.254', '::1']);

it('refuses a suffix a resolver answers from the local network', function (string $host): void {
    expect(PublicHost::names($host))->toBeFalse();
})->with([
    'printer.local',
    'files.internal',
    'nas.home.arpa',
    'wiki.lan',
    'portal.intranet',
    'sso.corp',
    'vault.private',
    'thing.invalid',
    'localhost',
    'nodots',
]);

// The positive control. Without it a predicate that refused everything would
// satisfy every case above, and every merchant support link would die.
it('admits an ordinary public name', function (string $host): void {
    expect(PublicHost::names($host))->toBeTrue();
})->with([
    'example.com',
    'support.bol.com',
    'a-very-long-subdomain.example.co.uk',
    'xn--80ak6aa92e.com',
    // A single trailing dot is a legal absolute name and normalises away.
    'example.com.',
]);

// The one place the two callers deliberately differ, stated rather than left to
// be rediscovered: a merchant's contact page is never a bare address, and a
// bank's SCA host may be.
it('lets a routable literal past the shared rule and refuses it as a merchant link', function (): void {
    expect(PublicHost::names('93.184.216.34'))->toBeTrue();

    expect(ExternalUrl::refusalFor('https://93.184.216.34/'))
        ->toBe(ExternalUrlRefusal::HostIsNotPublic);
});

// Both callers read one predicate now. A second copy is how the corrected one
// and the uncorrected one lived side by side.
it('is the only place either caller spells the rule', function (): void {
    foreach ([
        'Modules/Core/Public/Support/ExternalUrl.php',
        'Modules/OpenBanking/Internal/Actions/StartBankConsent.php',
    ] as $relative) {
        $source = (string) file_get_contents(base_path($relative));

        expect($source)->toContain('PublicHost::names(');
        expect($source)->not->toContain('FILTER_FLAG_NO_RES_RANGE');
    }
});
