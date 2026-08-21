<?php

declare(strict_types=1);

use Modules\Sync\Public\Services\SyncDaemonIdentity;

// `sync:serve` was constructed with empty placeholder credentials and a comment
// promising an injection nobody wrote, so the responder answered every Noise
// handshake with a key no peer could use.

afterEach(function (): void {
    foreach ([
        SyncDaemonIdentity::ENV_USER,
        SyncDaemonIdentity::ENV_DEVICE,
        SyncDaemonIdentity::ENV_SECRET,
        SyncDaemonIdentity::ENV_PUBLIC,
    ] as $name) {
        putenv($name);
    }
});

it('reads a complete credential set from the environment', function (): void {
    putenv(SyncDaemonIdentity::ENV_USER.'=7');
    putenv(SyncDaemonIdentity::ENV_DEVICE.'=device-abc');
    putenv(SyncDaemonIdentity::ENV_SECRET.'='.str_repeat('a', 64));
    putenv(SyncDaemonIdentity::ENV_PUBLIC.'='.str_repeat('b', 64));

    expect(SyncDaemonIdentity::fromEnvironment())->toBe([
        'userId' => 7,
        'deviceId' => 'device-abc',
        'secret' => str_repeat('a', 64),
        'public' => str_repeat('b', 64),
    ]);
});

it('reports nothing when the environment is incomplete', function (): void {
    putenv(SyncDaemonIdentity::ENV_USER.'=7');
    putenv(SyncDaemonIdentity::ENV_DEVICE.'=device-abc');

    // A half-configured spawn must reject peers outright rather than answer
    // handshakes with a key it does not have.
    expect(SyncDaemonIdentity::fromEnvironment())->toBeNull();
});

it('reports nothing when the device id is blank', function (): void {
    putenv(SyncDaemonIdentity::ENV_USER.'=7');
    putenv(SyncDaemonIdentity::ENV_DEVICE.'=');
    putenv(SyncDaemonIdentity::ENV_SECRET.'='.str_repeat('a', 64));
    putenv(SyncDaemonIdentity::ENV_PUBLIC.'='.str_repeat('b', 64));

    expect(SyncDaemonIdentity::fromEnvironment())->toBeNull();
});
