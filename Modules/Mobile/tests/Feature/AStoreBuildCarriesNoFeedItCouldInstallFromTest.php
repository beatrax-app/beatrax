<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory as HttpClient;
use Modules\Core\Internal\AutoUpdate\HttpPublisherManifestFetcher;
use Modules\Core\Public\Services\UpdateCheckPreference;
use Modules\Mobile\Tests\Support\ConfigFileCode;
use Psr\Log\NullLogger;

// mobile-app/config/auto_update.php was a symlink to the desktop root's copy,
// so the only thing keeping a store build off a release feed was an unset
// AUTO_UPDATE_FEED_URL. That is a configuration accident, not a boundary: one
// variable in the mobile .env, or one feed added to the desktop config the
// symlink pointed at, and a phone polls for binaries its store did not sign.

// Both Composer roots run this, and the two files sit on opposite sides of the
// root that is running. release.yml is the anchor rather than the config file
// itself, because both roots have a config/auto_update.php and a per-file
// probe would resolve the wrong one and pass on it.
function storeBuildDesktopRoot(): string
{
    return is_file(base_path('.github/workflows/release.yml')) ? base_path() : base_path('..');
}

function storeBuildMobileConfigPath(): string
{
    return storeBuildDesktopRoot().'/mobile-app/config/auto_update.php';
}

/** @return array<string, mixed> */
function storeBuildMobileUpdateConfig(): array
{
    $loaded = require storeBuildMobileConfigPath();

    return is_array($loaded) ? $loaded : [];
}

it('gives the mobile root an update configuration of its own', function (): void {
    $mobile = storeBuildMobileConfigPath();
    $desktop = storeBuildDesktopRoot().'/config/auto_update.php';

    expect(is_file($desktop))->toBeTrue('the desktop update configuration was not found — this guard resolved the wrong root');
    expect(is_file($mobile))->toBeTrue('the mobile root has no update configuration at all');

    expect(is_link($mobile))->toBeFalse(
        'mobile-app/config/auto_update.php is a symlink again. A desktop config change then reaches '
        .'the phone silently, and the store build has no update configuration of its own to be judged on.',
    );

    expect(realpath($mobile))->not->toBe(
        realpath($desktop),
        'the two roots resolve to one file, so whatever the desktop is configured to fetch, the phone fetches too',
    );
});

// The positive control sits in the same case as the claim: the desktop file
// genuinely does read the environment, so "the mobile one does not" is a
// difference between two files rather than a scan that found nothing.
it('leaves the phone no environment variable that could name a feed', function (): void {
    $mobileSource = ConfigFileCode::at(storeBuildMobileConfigPath());
    $desktopSource = ConfigFileCode::at(storeBuildDesktopRoot().'/config/auto_update.php');

    expect(str_contains($desktopSource, "env('AUTO_UPDATE_FEED_URL')"))->toBeTrue(
        'the desktop config no longer reads the feed from the environment — rewrite this guard, do not delete it',
    );

    expect(str_contains($mobileSource, 'env('))->toBeFalse(
        'the mobile update configuration reads the environment. Every key here has to be a literal, or '
        .'the boundary is one variable in a .env nobody reviews.',
    );

    expect(storeBuildMobileUpdateConfig())->toBe([
        'publisher_public_key_hex' => null,
        'update_channel' => 'stable',
        'manifest_feed_url' => null,
    ]);
});

// The whole outbound surface of the update check is manifestUrl(), and it is
// the feed origin that decides whether there is one.
it('composes no manifest URL from the phone configuration, on either channel', function (): void {
    $fetcher = new HttpPublisherManifestFetcher(
        app(HttpClient::class),
        new Repository(['auto_update' => storeBuildMobileUpdateConfig()]),
        new NullLogger,
        app(UpdateCheckPreference::class),
        platformFamily: 'Darwin',
    );

    expect($fetcher->fetch('stable'))->toBeNull()
        ->and($fetcher->fetch('preview'))->toBeNull();
});
