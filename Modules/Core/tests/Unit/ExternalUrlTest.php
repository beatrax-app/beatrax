<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\ExternalUrlRefusal;
use Modules\Core\Public\Support\ExternalUrl;

it('accepts an absolute https URL on a public host', function (string $url): void {
    expect(ExternalUrl::refusalFor($url))->toBeNull();
})->with([
    'plain' => 'https://www.anwb.nl/lidmaatschap/opzeggen',
    'with a query' => 'https://example.com/cancel?plan=basic&from=app',
    'with a fragment' => 'https://example.com/help#cancelling',
    'the https port written out' => 'https://jimmobile.be:443/',
    'a host that is upper-cased' => 'https://EXAMPLE.COM/help',
    'a fully qualified name with a trailing dot' => 'https://example.com./help',
]);

it('refuses a scheme that is not https, including the ones a URL validator accepts', function (string $url): void {
    expect(ExternalUrl::refusalFor($url))->toBe(ExternalUrlRefusal::NotHttps);
})->with([
    'plaintext http' => 'http://example.com/cancel',
    'javascript' => "javascript:alert('x')",
    'javascript, capitalised' => "JavaScript:alert('x')",
    'data' => 'data:text/html,<script>alert(1)</script>',
    'file' => 'file:///etc/passwd',
    'protocol-relative' => '//example.com/cancel',
    'a bare host' => 'example.com/cancel',
    'the empty string' => '',
]);

it('refuses an https URL that is not a usable address', function (string $url): void {
    expect(ExternalUrl::refusalFor($url))->toBe(ExternalUrlRefusal::Malformed);
})->with([
    'no host at all' => 'https://',
    'a space in the path' => 'https://example.com/two words',
    'a carriage return' => "https://example.com/x\r\nSet-Cookie: a=b",
    'a newline' => "https://example.com/x\nmore",
]);

it('refuses a URL longer than a link can carry verbatim', function (): void {
    $tooLong = 'https://example.com/'.str_repeat('a', ExternalUrl::MAX_LENGTH);

    expect(ExternalUrl::refusalFor($tooLong))->toBe(ExternalUrlRefusal::Malformed);
});

it('accepts a URL exactly at the length ceiling', function (): void {
    $prefix = 'https://example.com/';
    $atLimit = $prefix.str_repeat('a', ExternalUrl::MAX_LENGTH - strlen($prefix));

    expect(mb_strlen($atLimit))->toBe(ExternalUrl::MAX_LENGTH)
        ->and(ExternalUrl::refusalFor($atLimit))->toBeNull();
});

it('refuses an authority carrying credentials, which reads as one host and resolves to another', function (string $url): void {
    expect(ExternalUrl::refusalFor($url))->toBe(ExternalUrlRefusal::CarriesCredentials);
})->with([
    'a host in the user field' => 'https://github.com@example.test/beatrax',
    'a user and a password' => 'https://user:secret@example.test/',
]);

it('refuses a host that only resolves on this machine or this network', function (string $url): void {
    expect(ExternalUrl::refusalFor($url))->toBe(ExternalUrlRefusal::HostIsNotPublic);
})->with([
    'loopback by name' => 'https://localhost/settings',
    'loopback by address' => 'https://127.0.0.1/settings',
    'a routable address literal' => 'https://93.184.216.34/',
    'a private address literal' => 'https://192.168.1.10/',
    'an mDNS name' => 'https://macbook.local/',
    'a localhost subdomain' => 'https://beatrax.localhost/',
    'a name with no dot in it' => 'https://intranet/help',
]);

it('refuses a port other than the one the web is served on', function (): void {
    expect(ExternalUrl::refusalFor('https://example.com:4000/settings'))
        ->toBe(ExternalUrlRefusal::NonDefaultPort);
});

it('answers an allow-list only once the URL itself is sound', function (): void {
    expect(ExternalUrl::refusalFor('https://github.com/beatrax-app/beatrax', ['github.com']))->toBeNull()
        ->and(ExternalUrl::refusalFor('https://GITHUB.COM/beatrax-app', ['github.com']))->toBeNull()
        ->and(ExternalUrl::refusalFor('https://gitlab.com/beatrax-app', ['github.com']))
        ->toBe(ExternalUrlRefusal::HostNotAllowListed);
});

it('names the cause the checks before it have not already ruled out', function (): void {
    // An allow-listed caller handed a plaintext URL is told about the scheme,
    // never about the list: the list is a question the scheme check never got
    // far enough to ask, and an answer naming it would send a reader looking
    // in the wrong place.
    expect(ExternalUrl::refusalFor('http://github.com/beatrax-app', ['github.com']))
        ->toBe(ExternalUrlRefusal::NotHttps)
        ->and(ExternalUrl::refusalFor('https://localhost/x', ['localhost']))
        ->toBe(ExternalUrlRefusal::HostIsNotPublic);
});

it('agrees with itself about what it accepts', function (): void {
    expect(ExternalUrl::accepts('https://example.com/help'))->toBeTrue()
        ->and(ExternalUrl::accepts('http://example.com/help'))->toBeFalse();
});
