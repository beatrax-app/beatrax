<?php

declare(strict_types=1);

return [
    /*
     * Ed25519 publisher public key (hex-encoded; 64 hex chars =
     * 32 bytes per Ed25519 spec).
     *
     * Public-key cryptography means this value is NOT a secret —
     * embedding it in the committed bundle is the design. The matching
     * SECRET half lives in the repository secret `ED25519_PRIVATE_KEY`
     * and never leaves the release-pipeline runtime.
     *
     * ElectronUpdateChannel reads this through the injected
     * Illuminate\Contracts\Config\Repository (DI-only — never the
     * global `config()` helper) and verifies every fetched manifest's
     * detached signature against it before raising any system_alerts
     * row. With no OS-level binary signing on any shipped platform,
     * this verification is the sole binary-integrity signal for the
     * auto-update path.
     *
     * Key rotation contract: a future rotation lands as a config diff
     * — the constant moves to the new hex string in this file and the
     * shipped bundle re-checks against the new public key on its next
     * launch. Bundles still on the old binary continue to verify
     * against the old key until they auto-update through the
     * cross-over release that ships the new value.
     *
     * Pinned as a literal, not env-overridable: it is the trust anchor
     * the live update path verifies against, and a runtime .env write must
     * not be able to swap it for an attacker's key. Rotation is a code diff.
     */
    'publisher_public_key_hex' => '5cd2b2a3c5a09b4d3e0c778556dd500717a9b10ce396b0a0267363ea20b1abbf',

    /*
     * Update channel: `stable` resolves `latest.yml` on the publisher
     * (the channel softprops/action-gh-release writes for `v*.*.*`
     * tags); `preview` resolves `beta.yml` (the channel for `v*-rc.*`
     * tags). Default ships as `stable`; preview users override via the
     * AUTO_UPDATE_CHANNEL env var (in-app channel-switch UI is a
     * later polish item).
     */
    'update_channel' => env('AUTO_UPDATE_CHANNEL', 'stable'),

    /*
     * Public base URL of the update feed — the same origin electron-updater
     * is pointed at, holding `latest.yml`/`beta.yml` and their detached
     * `.sig` siblings. The desktop update listeners fetch the manifest and
     * signature from here to run the Ed25519 check BEFORE any download or
     * install proceeds; left unset (self-hosted / web / mobile) the fetch
     * yields null and no update is ever surfaced or applied.
     */
    'manifest_feed_url' => env('AUTO_UPDATE_FEED_URL'),
];
