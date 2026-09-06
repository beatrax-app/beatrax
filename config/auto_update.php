<?php

declare(strict_types=1);

return [
    /**
     * @link ../.docs/features/desktop/auto-update.md
     */
    // A literal, not env-overridable: a public key is no secret but it IS the
    // trust anchor, and a runtime .env write must not swap it. Rotation is a
    // code diff, and old bundles verify the old key until they cross over.
    'publisher_public_key_hex' => '5cd2b2a3c5a09b4d3e0c778556dd500717a9b10ce396b0a0267363ea20b1abbf',

    // The channel is deliberately absent from this file. It used to be
    // `env('AUTO_UPDATE_CHANNEL')`, which made opting into preview a rebuild —
    // nobody who installs a bundle can reach its .env. It is now the reader's,
    // in `users.update_channel`, read through UpdateChannelPreference.

    // Unset, the fetch yields null and nothing is surfaced — a build with no
    // feed, not a failure, and not the reader's switch, which narrows a build
    // that has one. The release workflow sets it from the GitHub context, so
    // moving the repository re-points the feed.
    'manifest_feed_url' => env('AUTO_UPDATE_FEED_URL'),

    // The preview set cannot live behind the same origin. `releases/latest`
    // resolves the newest NON-prerelease release, so a release candidate is
    // unreachable through it and a reader on preview would be handed the
    // newest stable build's manifest instead. A direct tag URL does serve a
    // prerelease, so the pipeline keeps one rolling `preview` release and this
    // addresses it by name. Unset behaves like the stable feed unset: null.
    'preview_feed_url' => env('AUTO_UPDATE_PREVIEW_FEED_URL'),
];
