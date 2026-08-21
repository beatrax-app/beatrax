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

    // `stable` resolves `latest*.yml` (written for `v*.*.*` tags),
    // `preview` resolves `beta*.yml` (for `v*-rc.*`). No in-app switch yet.
    'update_channel' => env('AUTO_UPDATE_CHANNEL', 'stable'),

    // Unset, the fetch yields null and nothing is surfaced: that is the off
    // switch, not a failure. The release workflow sets it from the GitHub
    // context, so moving the repository re-points the feed.
    'manifest_feed_url' => env('AUTO_UPDATE_FEED_URL'),
];
