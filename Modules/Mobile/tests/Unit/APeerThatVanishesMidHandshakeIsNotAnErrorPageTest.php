<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Exceptions\LanSyncException;

// Pressing "Sync now" while the other device slept returned a 500: the client
// caught LanSyncException and rethrew everything that was not a peer
// revocation, so a peer vanishing before Noise msg2 reached the reader as an
// error page. The block beside it already called a dial that never completed
// "retryable, never a thrown fatal" — this is the same condition.

it('marks a peer that vanished before the handshake finished as an incomplete dial', function (): void {
    expect(LanSyncException::peerDisconnectedBeforeHandshakeMessage('msg2')->isDialIncomplete())->toBeTrue();
});

// The security-relevant rejections keep raising, so neither is quietly folded
// into "we could not reach it" — a refusal is not a sleeping device.
it('does not mark the auth-gate refusal or a revocation as an incomplete dial', function (): void {
    expect(LanSyncException::peerFailedConfirmedDeviceGate()->isDialIncomplete())->toBeFalse()
        ->and(LanSyncException::peerRevokedThisDevice()->isDialIncomplete())->toBeFalse();
});

it('keeps the revocation flag independent of the new one', function (): void {
    expect(LanSyncException::peerRevokedThisDevice()->isPeerRevocation())->toBeTrue()
        ->and(LanSyncException::peerDisconnectedBeforeHandshakeMessage('msg2')->isPeerRevocation())->toBeFalse()
        ->and(LanSyncException::peerFailedConfirmedDeviceGate()->isPeerRevocation())->toBeFalse();
});
