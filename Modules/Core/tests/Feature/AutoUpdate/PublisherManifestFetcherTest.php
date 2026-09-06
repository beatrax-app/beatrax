<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use Modules\Core\Internal\AutoUpdate\HttpPublisherManifestFetcher;
use Modules\Core\Internal\Enums\OsFamily;
use Modules\Core\Public\Enums\UpdateChannel;
use Modules\Core\Public\Services\UpdateCheckPreference;
use Psr\Log\NullLogger;

// The manifest carries the expectation verifyBinary() checks a downloaded
// installer against, so every malformed or missing input has to fail to null
// rather than arrive there as a weakened one. electron-updater writes sha512 in
// base64; verifyBinary() compares hex.

function makeManifestFetcher(
    ?string $feedUrl,
    string $platformFamily = 'Windows',
    ?string $previewFeedUrl = null,
): HttpPublisherManifestFetcher {
    // Both channels default to the one origin so a case about parsing says
    // nothing about routing; the case that IS about routing passes them apart.
    $config = new Repository(['auto_update' => [
        'manifest_feed_url' => $feedUrl,
        'preview_feed_url' => $previewFeedUrl ?? $feedUrl,
    ]]);

    // Platform is injected so the manifest-name assertions do not depend on the
    // OS the suite runs on; the default keeps the base-case fakes on latest.yml.
    return new HttpPublisherManifestFetcher(
        app(HttpClient::class),
        $config,
        new NullLogger,
        app(UpdateCheckPreference::class),
        $platformFamily,
    );
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

    $result = makeManifestFetcher('https://feed.test')->fetch(UpdateChannel::Stable);

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

    $result = makeManifestFetcher('https://feed.test')->fetch(UpdateChannel::Preview);

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

    $result = makeManifestFetcher('https://feed.test', 'Darwin')->fetch(UpdateChannel::Stable);

    expect($result)->not->toBeNull()->and($result['latest_version'])->toBe('3.1.0');
});

it('fetches the Linux manifest (latest-linux.yml) on a Linux bundle', function (): void {
    $digest = str_repeat("\x04", 64);
    Http::fake([
        'https://feed.test/latest-linux.yml' => Http::response(manifestYaml('3.2.0', base64_encode($digest)), 200),
        'https://feed.test/latest-linux.yml.sig' => Http::response(str_repeat('ab', 64), 200),
    ]);

    $result = makeManifestFetcher('https://feed.test', 'Linux')->fetch(UpdateChannel::Stable);

    expect($result)->not->toBeNull()->and($result['latest_version'])->toBe('3.2.0');
});

it('fetches the macOS preview manifest (beta-mac.yml) on a Darwin bundle', function (): void {
    $digest = str_repeat("\x05", 64);
    Http::fake([
        'https://feed.test/beta-mac.yml' => Http::response(manifestYaml('3.3.0-rc.1', base64_encode($digest)), 200),
        'https://feed.test/beta-mac.yml.sig' => Http::response(str_repeat('cd', 64), 200),
    ]);

    $result = makeManifestFetcher('https://feed.test', 'Darwin')->fetch(UpdateChannel::Preview);

    expect($result)->not->toBeNull()->and($result['latest_version'])->toBe('3.3.0-rc.1');
});

it('returns null when no feed url is configured', function (): void {
    expect(makeManifestFetcher(null)->fetch(UpdateChannel::Stable))->toBeNull();
});

it('returns null on a 404 manifest', function (): void {
    Http::fake(['https://feed.test/*' => Http::response('', 404)]);

    expect(makeManifestFetcher('https://feed.test')->fetch(UpdateChannel::Stable))->toBeNull();
});

it('returns null when the signature body is not hex', function (): void {
    $body = manifestYaml('1.2.3', base64_encode(str_repeat("\x01", 64)));
    Http::fake([
        'https://feed.test/latest.yml' => Http::response($body, 200),
        'https://feed.test/latest.yml.sig' => Http::response('nothexatall!!', 200),
    ]);

    expect(makeManifestFetcher('https://feed.test')->fetch(UpdateChannel::Stable))->toBeNull();
});

it('returns null when the decoded sha512 is not 64 bytes', function (): void {
    $body = manifestYaml('1.2.3', base64_encode('too-short'));
    Http::fake([
        'https://feed.test/latest.yml' => Http::response($body, 200),
        'https://feed.test/latest.yml.sig' => Http::response(str_repeat('ab', 64), 200),
    ]);

    expect(makeManifestFetcher('https://feed.test')->fetch(UpdateChannel::Stable))->toBeNull();
});

// PHP_OS_FAMILY also answers BSD, Solaris and Unknown. The old `default => ''`
// arm turned every one of those into Windows' latest.yml, whose SHA-512 can
// never match a non-Windows binary — an update that fails forever, silently,
// on one OS only. Fetching nothing is the correct answer for an OS this app
// publishes no build for.
it('fetches NOTHING at all on an OS family the app publishes no manifest for, rather than falling through to the Windows manifest', function (string $family): void {
    $digest = str_repeat("\x06", 64);
    Http::fake([
        'https://feed.test/latest.yml' => Http::response(manifestYaml('9.9.9', base64_encode($digest)), 200),
        'https://feed.test/latest.yml.sig' => Http::response(str_repeat('ab', 64), 200),
        'https://feed.test/*' => Http::response(manifestYaml('9.9.9', base64_encode($digest)), 200),
    ]);

    expect(makeManifestFetcher('https://feed.test', $family)->fetch(UpdateChannel::Stable))->toBeNull();

    Http::assertNothingSent();
})->with(['BSD', 'Solaris', 'Unknown', 'darwin', 'macOS', '']);

it('gives every OS family it does model its own manifest name, so no family can ever be handed another one', function (): void {
    $names = [];
    foreach (OsFamily::cases() as $family) {
        foreach (UpdateChannel::cases() as $channel) {
            $names[] = $family->updateManifestSuffix().'|'.$channel->value;
        }
    }

    expect($names)->toHaveCount(count(array_unique($names)));
});

it('names the manifest for every modelled family and channel exactly as the release pipeline publishes it', function (): void {
    $fetcher = new ReflectionMethod(HttpPublisherManifestFetcher::class, 'manifestName');

    $name = static fn (string $family, UpdateChannel $channel): ?string => $fetcher->invoke(
        makeManifestFetcher('https://feed.test', $family),
        $channel,
    );

    expect($name('Windows', UpdateChannel::Stable))->toBe('latest.yml')
        ->and($name('Darwin', UpdateChannel::Stable))->toBe('latest-mac.yml')
        ->and($name('Linux', UpdateChannel::Stable))->toBe('latest-linux.yml')
        ->and($name('Windows', UpdateChannel::Preview))->toBe('beta.yml')
        ->and($name('Darwin', UpdateChannel::Preview))->toBe('beta-mac.yml')
        ->and($name('Linux', UpdateChannel::Preview))->toBe('beta-linux.yml')
        ->and($name('FreeBSD', UpdateChannel::Stable))->toBeNull();
});

// `releases/latest/download` resolves the newest NON-prerelease release, so the
// preview set cannot be reached there however it is named — the two channels
// have to ask two origins, and a bundle whose preview feed is unset must go
// quiet rather than fall back onto the stable one and offer a stable build to a
// reader who asked for release candidates.
it('asks each channel its own origin', function (): void {
    Http::fake([
        'https://stable.test/latest.yml' => Http::response(manifestYaml('1.0.0', base64_encode(str_repeat("\x01", 64))), 200),
        'https://stable.test/latest.yml.sig' => Http::response(str_repeat('ab', 64), 200),
        'https://preview.test/beta.yml' => Http::response(manifestYaml('2.0.0-rc.1', base64_encode(str_repeat("\x02", 64))), 200),
        'https://preview.test/beta.yml.sig' => Http::response(str_repeat('cd', 64), 200),
    ]);

    $fetcher = makeManifestFetcher('https://stable.test', 'Windows', 'https://preview.test');

    $stable = $fetcher->fetch(UpdateChannel::Stable);
    $preview = $fetcher->fetch(UpdateChannel::Preview);

    expect($stable)->not->toBeNull()
        ->and($preview)->not->toBeNull()
        ->and($stable['latest_version'])->toBe('1.0.0')
        ->and($preview['latest_version'])->toBe('2.0.0-rc.1');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://stable.test/latest.yml');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://preview.test/beta.yml');
});

it('goes quiet on a channel whose origin is unset, without borrowing the other', function (): void {
    Http::fake();

    $config = new Repository(['auto_update' => [
        'manifest_feed_url' => 'https://stable.test',
        'preview_feed_url' => null,
    ]]);

    $fetcher = new HttpPublisherManifestFetcher(
        app(HttpClient::class),
        $config,
        new NullLogger,
        app(UpdateCheckPreference::class),
        'Windows',
    );

    expect($fetcher->fetch(UpdateChannel::Preview))->toBeNull();

    Http::assertNothingSent();
});
