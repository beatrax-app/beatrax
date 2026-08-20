<?php

declare(strict_types=1);

return [
    /**
     * @link ../.docs/features/desktop/auto-update.md
     */
    // A public key is not a secret, but it IS the trust anchor, so it is a
    // literal and not env-overridable — a runtime .env write must not be able
    // to swap it. Rotating it is a code diff, and old bundles keep verifying
    // against the old key until they update through the cross-over release.
    'publisher_public_key_hex' => '5cd2b2a3c5a09b4d3e0c778556dd500717a9b10ce396b0a0267363ea20b1abbf',

    // `stable` resolves `latest*.yml` (written for `v*.*.*` tags),
    // `preview` resolves `beta*.yml` (for `v*-rc.*`). No in-app switch yet.
    'update_channel' => env('AUTO_UPDATE_CHANNEL', 'stable'),

    // Unset — self-hosted, web, mobile — the fetch yields null and no update
    // is ever surfaced. That is the off switch, not a failure. The release
    // workflow sets it from the GitHub context, so moving the repository
    // re-points the feed instead of stranding a hardcoded owner.
    'manifest_feed_url' => env('AUTO_UPDATE_FEED_URL'),
];
