<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use Modules\Core\Internal\AutoUpdate\HttpPublisherManifestFetcher;
use Psr\Log\NullLogger;

/*
 * HttpPublisherManifestFetcher fetches the exact signed manifest bytes and
 * the detached signature the explicit-consent update listeners verify. It
 * must normalise electron-updater's base64 SHA512 to the hex verifyBinary()
 * compares, and it must fail to null on every malformed or missing input so
 * a broken feed can never reach the verification step as a bad expectation.
 */

function makeManifestFetcher(?string $feedUrl, string $platformFamily = 'Windows'): HttpPublisherManifestFetcher
{
    $config = new Repository(['auto_update' => ['manifest_feed_url' => $feedUrl]]);

    // Platform is injected so the manifest-name assertions do not depend on the
    // OS the suite runs on (Windows -> latest.yml keeps the base-case fakes
    // simple; the -mac/-linux cases assert the per-platform names explicitly).
    return new HttpPublisherManifestFetcher(app(HttpClient::class), $config, new NullLogger, $platformFamily);
}

function manifestYaml(string $version, string $sha512Base64): string
{
    return "version: {$version}\nsha512: {$sha512Base64}\nreleaseDate: '2026-08-16T00:00:00.000Z'\n";
}

it('parses a manifest and normalises the base64 sha512 to hex', function (): void {
    $digest = str_repeat("\x01", 64);
    $body = manifestYaml('1.2.3', base64_encode($digest));
    Http::fake([
        'https://feed.test/latest.yml' => Http::response($body, 200),
        'https://feed.test/latest.yml.sig' => Http::response(str_repeat('ab', 64), 200),
    ]);

    $result = makeManifestFetcher('https://feed.test')->fetch('stable');

    expect($result)->not->toBeNull()
        ->and($result['latest_version'])->toBe('1.2.3')
        ->and($result['sha512_hex'])->toBe(bin2hex($digest))
        ->and($result['signature'])->toBe(str_repeat("\xab", 64))
        ->and($result['body'])->toBe($body);
});

it('reads beta.yml for a non-stable channel', function (): void {
    $digest = str_repeat("\x02", 64);
    Http::fake([
        'https://feed.test/beta.yml' => Http::response(manifestYaml('2.0.0-rc.1', base64_encode($digest)), 200),
        'https://feed.test/beta.yml.sig' => Http::response(str_repeat('cd', 64), 200),
    ]);

    $result = makeManifestFetcher('https://feed.test')->fetch('preview');

    expect($result)->not->toBeNull()->and($result['latest_version'])->toBe('2.0.0-rc.1');
});

it('fetches the macOS manifest (latest-mac.yml) on a Darwin bundle', function (): void {
    $digest = str_repeat("\x03", 64);
    Http::fake([
        'https://feed.test/latest-mac.yml' => Http::response(manifestYaml('3.1.0', base64_encode($digest)), 200),
        'https://feed.test/latest-mac.yml.sig' => Http::response(str_repeat('ef', 64), 200),
        // A Windows-named manifest at the same feed must not be what a mac
        // bundle reads — verifyBinary() would get another OS's installer hash.
        'https://feed.test/latest.yml' => Http::response('version: 9.9.9', 200),
    ]);

    $result = makeManifestFetcher('https://feed.test', 'Darwin')->fetch('stable');

    expect($result)->not->toBeNull()->and($result['latest_version'])->toBe('3.1.0');
});

it('fetches the Linux manifest (latest-linux.yml) on a Linux bundle', function (): void {
    $digest = str_repeat("\x04", 64);
    Http::fake([
        'https://feed.test/latest-linux.yml' => Http::response(manifestYaml('3.2.0', base64_encode($digest)), 200),
        'https://feed.test/latest-linux.yml.sig' => Http::response(str_repeat('ab', 64), 200),
    ]);

    $result = makeManifestFetcher('https://feed.test', 'Linux')->fetch('stable');

    expect($result)->not->toBeNull()->and($result['latest_version'])->toBe('3.2.0');
});

it('fetches the macOS preview manifest (beta-mac.yml) on a Darwin bundle', function (): void {
    $digest = str_repeat("\x05", 64);
    Http::fake([
        'https://feed.test/beta-mac.yml' => Http::response(manifestYaml('3.3.0-rc.1', base64_encode($digest)), 200),
        'https://feed.test/beta-mac.yml.sig' => Http::response(str_repeat('cd', 64), 200),
    ]);

    $result = makeManifestFetcher('https://feed.test', 'Darwin')->fetch('preview');

    expect($result)->not->toBeNull()->and($result['latest_version'])->toBe('3.3.0-rc.1');
});

it('returns null when no feed url is configured', function (): void {
    expect(makeManifestFetcher(null)->fetch('stable'))->toBeNull();
});

it('returns null on a 404 manifest', function (): void {
    Http::fake(['https://feed.test/*' => Http::response('', 404)]);

    expect(makeManifestFetcher('https://feed.test')->fetch('stable'))->toBeNull();
});

it('returns null when the signature body is not hex', function (): void {
    $body = manifestYaml('1.2.3', base64_encode(str_repeat("\x01", 64)));
    Http::fake([
        'https://feed.test/latest.yml' => Http::response($body, 200),
        'https://feed.test/latest.yml.sig' => Http::response('nothexatall!!', 200),
    ]);

    expect(makeManifestFetcher('https://feed.test')->fetch('stable'))->toBeNull();
});

it('returns null when the decoded sha512 is not 64 bytes', function (): void {
    $body = manifestYaml('1.2.3', base64_encode('too-short'));
    Http::fake([
        'https://feed.test/latest.yml' => Http::response($body, 200),
        'https://feed.test/latest.yml.sig' => Http::response(str_repeat('ab', 64), 200),
    ]);

    expect(makeManifestFetcher('https://feed.test')->fetch('stable'))->toBeNull();
});
