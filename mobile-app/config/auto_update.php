<?php

declare(strict_types=1);

/*
 * The mobile root's own update configuration, and the reason it is a file
 * rather than the symlink to ../../config/auto_update.php it used to be.
 *
 * A store build may carry no reachable path that downloads or installs
 * application code. Three things stop this one: the AutoUpdater listeners live
 * in Modules\Desktop, which the mobile shell never loads; `nativephp/mobile`
 * has no `updater` config section for electron-updater to read; and
 * HttpPublisherManifestFetcher composes no URL without a feed origin.
 *
 * Only the third of those was ever this file's business, and while the symlink
 * stood it was not a boundary at all — it was an unset AUTO_UPDATE_FEED_URL.
 * Set that variable in the mobile root's .env, or add a feed to the desktop
 * config the symlink pointed at, and a phone starts polling a release feed
 * whose binaries its store did not sign. A build that must never self-update
 * should not be one environment variable away from doing so.
 *
 * So the keys are literals here, and there is no `env()` call to override:
 * no feed to reach, and no publisher key, because a build that installs
 * nothing verifies nothing and a trust anchor it cannot use is an invitation
 * to find a use for it. The channel name is kept only because
 * ElectronUpdateChannel::channel() is shared code that reads it.
 */
return [
    'publisher_public_key_hex' => null,

    'update_channel' => 'stable',

    'manifest_feed_url' => null,
];
