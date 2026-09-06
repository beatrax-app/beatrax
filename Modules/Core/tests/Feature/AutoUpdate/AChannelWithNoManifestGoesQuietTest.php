<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use Modules\Core\Internal\AutoUpdate\HttpPublisherManifestFetcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\UpdateChannel;
use Modules\Core\Public\Services\ElectronUpdateChannel;
use Modules\Core\Public\Services\SystemClock;
use Modules\Core\Public\Services\UpdateChannelPreference;
use Modules\Core\Public\Services\UpdateCheckPreference;
use Modules\Core\Tests\Support\EuvRecordingLogger;

// The channel used to be an environment variable read at build time, so the
// only bundle that could ask for a preview manifest was one rebuilt to ask for
// it. It is a row now, and these hold the whole path from that row to the URL.
//
// The last case is the one F6 names: a channel whose manifest is not on the
// feed has to end in silence. Nothing about a missing file is actionable by
// the person reading the screen — they did not publish it and cannot fetch it
// — so a banner or an error there would only be noise they cannot clear.

const QUIET_CHANNEL_FEED = 'https://feed.quiet-channel.test';

/** @return array{0: string, 1: string} [secretKey, publicKeyHex] */
function quietChannelKeypair(): array
{
    $keypair = sodium_crypto_sign_keypair();

    return [sodium_crypto_sign_secretkey($keypair), sodium_bin2hex(sodium_crypto_sign_publickey($keypair))];
}

/**
 * Publishes only the names given, and answers 404 for every other manifest —
 * which is the state a release page is actually in while one channel is
 * published and the other is not.
 *
 * @param  array<string, string>  $versionsByManifestName
 */
function quietChannelFeed(array $versionsByManifestName, string $secretKey): void
{
    $fakes = [];

    foreach (['latest-mac.yml', 'beta-mac.yml'] as $name) {
        $version = $versionsByManifestName[$name] ?? null;

        if ($version === null) {
            $fakes[QUIET_CHANNEL_FEED.'/'.$name] = Http::response('', 404);
            $fakes[QUIET_CHANNEL_FEED.'/'.$name.'.sig'] = Http::response('', 404);

            continue;
        }

        $body = "version: {$version}\nsha512: ".base64_encode(str_repeat("\x07", 64))."\nreleaseDate: '2026-09-01T00:00:00.000Z'\n";

        $fakes[QUIET_CHANNEL_FEED.'/'.$name] = Http::response($body, 200);
        $fakes[QUIET_CHANNEL_FEED.'/'.$name.'.sig'] = Http::response(
            sodium_bin2hex(sodium_crypto_sign_detached($body, $secretKey)),
            200,
        );
    }

    Http::fake($fakes);
}

function quietChannelFetcher(EuvRecordingLogger $logger): HttpPublisherManifestFetcher
{
    return new HttpPublisherManifestFetcher(
        app(HttpClient::class),
        // Both channels are pointed at the one origin on purpose: the subject
        // here is a 404 for a manifest, and a channel left with no feed at all
        // would go quiet for a different reason and prove nothing.
        new Repository(['auto_update' => [
            'manifest_feed_url' => QUIET_CHANNEL_FEED,
            'preview_feed_url' => QUIET_CHANNEL_FEED,
        ]]),
        $logger,
        app(UpdateCheckPreference::class),
        platformFamily: 'Darwin',
    );
}

function quietChannelUpdates(EuvRecordingLogger $logger, string $publicKeyHex): ElectronUpdateChannel
{
    return new ElectronUpdateChannel(
        app(DatabaseManager::class),
        $logger,
        new SystemClock,
        new Repository(['auto_update' => ['publisher_public_key_hex' => $publicKeyHex]]),
        app(UpdateChannelPreference::class),
    );
}

function quietChannelReader(?string $storedChannel): User
{
    $columns = [
        'username' => 'quiet-channel-reader',
        'password' => 'a-long-password-12chars',
        'period_start_day' => 1,
    ];

    return User::create($storedChannel === null ? $columns : $columns + ['update_channel' => $storedChannel]);
}

function quietChannelAlertCount(): int
{
    return app(DatabaseManager::class)->connection()->table('system_alerts')->count();
}

it('asks the feed for the stable manifest while nobody has chosen otherwise', function (): void {
    [$secret, $publicHex] = quietChannelKeypair();
    quietChannelReader(null);
    quietChannelFeed(['latest-mac.yml' => '2.0.0'], $secret);

    $logger = new EuvRecordingLogger;
    $manifest = quietChannelUpdates($logger, $publicHex)->poll(quietChannelFetcher($logger));

    expect($manifest?->channel)->toBe(UpdateChannel::Stable)
        ->and($manifest?->latestVersion)->toBe('2.0.0');

    Http::assertSent(static fn ($request): bool => $request->url() === QUIET_CHANNEL_FEED.'/latest-mac.yml');
});

it('asks for the preview manifest once the reader has chosen preview', function (): void {
    [$secret, $publicHex] = quietChannelKeypair();
    quietChannelReader(UpdateChannel::Preview->value);
    quietChannelFeed(['latest-mac.yml' => '2.0.0', 'beta-mac.yml' => '2.1.0-rc.1'], $secret);

    $logger = new EuvRecordingLogger;
    $manifest = quietChannelUpdates($logger, $publicHex)->poll(quietChannelFetcher($logger));

    expect($manifest?->channel)->toBe(UpdateChannel::Preview)
        ->and($manifest?->latestVersion)->toBe('2.1.0-rc.1');

    Http::assertSent(static fn ($request): bool => $request->url() === QUIET_CHANNEL_FEED.'/beta-mac.yml');
});

it('goes quiet rather than wrong when the chosen channel has no manifest on the feed', function (): void {
    [$secret, $publicHex] = quietChannelKeypair();
    quietChannelReader(UpdateChannel::Preview->value);

    // The state the release pipeline actually left behind: the stable set is
    // on the page and the preview set is on no page at all.
    quietChannelFeed(['latest-mac.yml' => '2.0.0'], $secret);

    $logger = new EuvRecordingLogger;
    $manifest = quietChannelUpdates($logger, $publicHex)->poll(quietChannelFetcher($logger));

    expect($manifest)->toBeNull()
        ->and(quietChannelAlertCount())->toBe(0)
        ->and($logger->warnings)->toBe(
            [],
            'A manifest that is not published is not a tampering signal and not a fault the reader '
            .'can act on. It has to read as nothing to show, the same as being offline.',
        );

    Http::assertSent(static fn ($request): bool => $request->url() === QUIET_CHANNEL_FEED.'/beta-mac.yml');
});

// The positive control for the case above: the same feed, the same fetcher,
// one row different. Without it, "returned null" would be equally satisfied by
// a fetch that never happened.
it('still answers on the channel the same feed does publish', function (): void {
    [$secret, $publicHex] = quietChannelKeypair();
    quietChannelReader(UpdateChannel::Stable->value);
    quietChannelFeed(['latest-mac.yml' => '2.0.0'], $secret);

    $logger = new EuvRecordingLogger;

    expect(quietChannelUpdates($logger, $publicHex)->poll(quietChannelFetcher($logger)))->not->toBeNull();
});

it('reads a stored word no release has ever published as stable', function (): void {
    [$secret, $publicHex] = quietChannelKeypair();
    quietChannelReader('nightly');
    quietChannelFeed(['latest-mac.yml' => '2.0.0'], $secret);

    $logger = new EuvRecordingLogger;
    $manifest = quietChannelUpdates($logger, $publicHex)->poll(quietChannelFetcher($logger));

    expect($manifest?->channel)->toBe(UpdateChannel::Stable);

    Http::assertSent(static fn ($request): bool => $request->url() === QUIET_CHANNEL_FEED.'/latest-mac.yml');
});
