<?php

declare(strict_types=1);

use Modules\Sync\Internal\Transport\SyncWebSocketHandler;

/*
 * The GDK epoch phase is a two-sided wire contract with no schema to enforce
 * it: SyncWebSocketHandler sends, LanSyncClient reads, and the two live in
 * different modules. Both halves are pinned here because getting either wrong
 * fails silently and expensively — the peer applies an entire encrypted
 * history it cannot read, and quarantine has no replay path.
 */

function epochPhaseSource(string $relative): string
{
    $source = file_get_contents(base_path($relative));

    expect($source)->toBeString();

    return (string) $source;
}

it('has the client read the same wire literal the server sends', function (): void {
    // The client cannot reference the Sync-internal constant across the module
    // boundary, so it repeats the literal — which is exactly how the two sides
    // drift apart without anything failing to compile.
    $client = epochPhaseSource('Modules/Mobile/Internal/Sync/LanSyncClient.php');

    expect($client)->toContain("'".SyncWebSocketHandler::MSG_GDK_EPOCH_PUSH."'");
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

    $receive = strpos($client, '$this->receiveGdkEpochWraps($connection, $syncSession,');
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
