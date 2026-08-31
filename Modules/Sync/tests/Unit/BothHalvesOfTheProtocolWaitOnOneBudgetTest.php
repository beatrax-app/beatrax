<?php

declare(strict_types=1);

use Modules\Sync\Public\Transport\ProtocolTimings;

// The initiator and the responder are separate classes in separate modules and
// nothing but this holds them to one set of numbers. They had already drifted
// to a fifteen-second initiator against a sixty-second responder, so every
// replay batch the responder needed longer than fifteen seconds to produce was
// abandoned by the only device asking for it.

it('never lets the initiator give up before the responder is allowed to finish', function (): void {
    expect(ProtocolTimings::initiatorReadSeconds())
        ->toBeGreaterThanOrEqual(ProtocolTimings::responderReadSeconds());
});

it('keeps a cached browse answer alive longer than the browse that produced it', function (): void {
    expect(ProtocolTimings::DISCOVERY_CACHE_TTL_SECONDS)
        ->toBeGreaterThan(ProtocolTimings::BROWSE_SECONDS);
});

// Read as source rather than as a value, because the hazard is a second
// DECLARATION rather than a wrong number: a constant re-declared beside its
// user is one nobody compares against the half on the other end of the wire.
it('leaves no half of the LAN protocol declaring a wait of its own', function (string $file): void {
    $source = file_get_contents(base_path($file));

    expect($source)->toBeString()
        ->not->toMatch('/const\s+(?:float\s+|int\s+)?(?:READ_TIMEOUT|CONNECT_TIMEOUT|HANDSHAKE_TIMEOUT|BROWSE_TIMEOUT|TTL)_SECONDS/');
})->with([
    'Modules/Sync/Internal/Transport/SyncWebSocketHandler.php',
    'Modules/Sync/Internal/Pairing/LanPeerBrowser.php',
    'Modules/Sync/Internal/Transport/Discovery/CachedPeerDiscovery.php',
    'Modules/Mobile/Internal/Sync/LanSyncClient.php',
]);
