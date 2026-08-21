<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;

// A two-sided wire contract with no schema to enforce it, and the two sides
// live in different modules. Getting either wrong fails silently: the peer
// applies an entire encrypted history it cannot read, and quarantine has no
// replay path.

function epochPhaseSource(string $relative): string
{
    $source = file_get_contents(base_path($relative));

    expect($source)->toBeString();

    return (string) $source;
}

it('has both sides read the wire vocabulary from one declaration', function (): void {
    // A repeated literal is exactly how two sides in different modules drift
    // apart without anything failing to compile, so the client names the
    // public gateway's constant and the server's own constant aliases it.
    $client = epochPhaseSource('Modules/Mobile/Internal/Sync/LanSyncClient.php');

    expect($client)->toContain('GdkEpochDeliveryGateway::MSG_EPOCH_PUSH')
        ->and($client)->toContain('GdkEpochDeliveryGateway::MSG_EPOCH_ACK')
        ->and(SyncWebSocketHandler::MSG_GDK_EPOCH_PUSH)->toBe(GdkEpochDeliveryGateway::MSG_EPOCH_PUSH)
        ->and(SyncWebSocketHandler::MSG_GDK_EPOCH_ACK)->toBe(GdkEpochDeliveryGateway::MSG_EPOCH_ACK);
});

it('sends the epoch phase before catch-up on the server', function (): void {
    $server = epochPhaseSource('Modules/Sync/Internal/Transport/SyncWebSocketHandler.php');

    $push = strpos($server, '$this->deliverGdkEpochWraps($client, $session);');
    $catchUp = strpos($server, '$this->runCatchUp($client, $session, $deviceKeys);');

    expect($push)->toBeInt()
        ->and($catchUp)->toBeInt()
        ->and($push)->toBeLessThan($catchUp, 'keys must go out before the data they decrypt');
});

it('reads the epoch phase before catch-up on the client', function (): void {
    $client = epochPhaseSource('Modules/Mobile/Internal/Sync/LanSyncClient.php');

    $receive = strpos($client, '$this->exchangeGdkEpochWraps($connection, $syncSession,');
    $catchUp = strpos($client, '$this->runCatchUp($connection, $syncSession,');

    expect($receive)->toBeInt()
        ->and($catchUp)->toBeInt()
        ->and($receive)->toBeLessThan($catchUp, 'the keyring must be populated before ops are applied');
});

it('announces the wrap count rather than letting a read timeout end the phase', function (): void {
    // A timeout-terminated phase cannot run first: it would consume the
    // catch-up request queued behind it.
    $server = epochPhaseSource('Modules/Sync/Internal/Transport/SyncWebSocketHandler.php');
    $client = epochPhaseSource('Modules/Mobile/Internal/Sync/LanSyncClient.php');

    expect($server)->toContain('\'count\' => count($wraps),')
        ->and($client)->toContain('readEpochPushCount');
});
