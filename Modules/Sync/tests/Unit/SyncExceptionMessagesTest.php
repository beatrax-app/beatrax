<?php

declare(strict_types=1);

use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use Modules\Sync\Internal\Exceptions\KeyringStateException;
use Modules\Sync\Internal\Exceptions\NoiseDecryptionFailedException;
use Modules\Sync\Internal\Exceptions\NoiseNonceExhaustedException;
use Modules\Sync\Internal\Exceptions\PeerDisconnectedException;
use Modules\Sync\Internal\Exceptions\RebuildInProgressException;
use Modules\Sync\Internal\Exceptions\RelayConfigWriteException;
use Modules\Sync\Internal\Exceptions\RelayRefusedException;
use Modules\Sync\Internal\Exceptions\RelayUnavailableException;
use Modules\Sync\Internal\Exceptions\SecretFileException;
use Modules\Sync\Internal\Exceptions\SessionNotAuthenticatedException;
use Modules\Sync\Internal\Exceptions\UnreadableColumnException;

// Sync's failure types build their message in a named constructor rather than at
// the throw site, so the identifiers a maintainer needs — which user, which epoch,
// which path — live in one place. Several throw sites are themselves unreachable
// from a test, so without this the formatting would go unchecked entirely.

it('names the operation that failed', function (): void {
    $e = CryptoOperationFailedException::during('GDK keyring generation');

    expect($e->getMessage())->toBe('Sync crypto failed during GDK keyring generation.')
        ->and($e->getPrevious())->toBeNull();
});

it('keeps the underlying libsodium failure as the previous exception', function (): void {
    $cause = new SodiumException('bad key length');
    $e = CryptoOperationFailedException::during('GDK rotation', $cause);

    // The cause is what tells a maintainer which primitive gave up; losing it
    // would leave only the operation name, which is the part they already knew.
    expect($e->getPrevious())->toBe($cause);
});

it('says which user and epoch the keyring could not satisfy', function (): void {
    expect(KeyringStateException::noCurrentEpoch(42)->getMessage())
        ->toBe('No current GDK epoch recorded for user 42.')
        ->and(KeyringStateException::missingKeyForEpoch(42, 7)->getMessage())
        ->toBe('GDK keyring for user 42 has no key for current epoch 7.')
        ->and(KeyringStateException::corruptPayload(42)->getMessage())
        ->toBe('Corrupt GDK keyring payload for user 42.');
});

it('says which path the secret material could not reach', function (): void {
    expect(SecretFileException::couldNotStage('/tmp/x.key')->getMessage())
        ->toContain('/tmp/x.key')
        ->and(SecretFileException::couldNotLockDown('/tmp/x.key')->getMessage())
        ->toContain('0600')
        ->and(SecretFileException::couldNotFinalizeKeyring(42)->getMessage())
        ->toContain('user 42')
        ->and(SecretFileException::couldNotReadIdentity()->getMessage())
        ->toContain('device identity');
});

// Every one of these is caught, if at all, as a RuntimeException somewhere up
// the stack. Changing a base class would be silent at the throw site and only
// show up as an uncaught exception in production.
it('keeps every failure catchable as a RuntimeException', function (string $class): void {
    expect(is_subclass_of($class, RuntimeException::class))->toBeTrue();
})->with([
    CryptoOperationFailedException::class,
    KeyringStateException::class,
    SecretFileException::class,
    RelayUnavailableException::class,
    RelayRefusedException::class,
    RelayConfigWriteException::class,
    SessionNotAuthenticatedException::class,
    NoiseDecryptionFailedException::class,
    NoiseNonceExhaustedException::class,
    PeerDisconnectedException::class,
    RebuildInProgressException::class,
    UnreadableColumnException::class,
]);

// The relay hop, the Noise session and the op-log rebuild lock all fail on a
// user's device with no operator watching, so the message is the whole of what a
// maintainer ever gets.

// The relay is a store-and-forward hop holding ciphertext it cannot read, so
// which call failed and against which endpoint is the only context there is —
// the payload can never be part of the message.
it('says which relay call failed, with what status, against which endpoint', function (string $operation): void {
    $e = RelayUnavailableException::requestFailed($operation, 502, 'https://relay.example');

    expect($e->getMessage())->toContain($operation)
        ->and($e->getMessage())->toContain('502')
        ->and($e->getMessage())->toContain('https://relay.example');
})->with(['deliver', 'drain', 'confirm']);

it('says what the relay was refused for', function (): void {
    expect(RelayRefusedException::blobTooLarge(2_000_000, 1_048_576)->getMessage())
        ->toContain('2000000')
        ->and(RelayRefusedException::blobTooLarge(2_000_000, 1_048_576)->getMessage())->toContain('1048576')
        ->and(RelayRefusedException::notConfigured()->getMessage())->toContain('No relay endpoint configured')
        ->and(RelayRefusedException::endpointNotHttps()->getMessage())->toContain('HTTPS')
        ->and(RelayRefusedException::endpointVanished()->getMessage())->toContain('isConfigured()');
});

it('says which relay file could not be written', function (): void {
    expect(RelayConfigWriteException::couldNotCreateDirectory('/data/sync')->getMessage())
        ->toContain('/data/sync')
        ->and(RelayConfigWriteException::couldNotWrite('/data/sync/relay.json')->getMessage())
        ->toContain('/data/sync/relay.json')
        ->and(SecretFileException::couldNotCreateSecretsDirectory('/data/secrets')->getMessage())
        ->toContain('/data/secrets')
        ->and(SecretFileException::couldNotWriteRelayToken('/data/secrets/relay.token')->getMessage())
        ->toContain('/data/secrets/relay.token');
});

// Named per operation rather than shared, because the four guarded methods
// fail for the same reason but a maintainer needs to know which one a caller
// reached before the handshake finished.
it('names the session operation attempted before the handshake', function (string $operation): void {
    $e = SessionNotAuthenticatedException::forOperation($operation);

    expect($e->getMessage())->toContain("SyncSession::{$operation}")
        ->and($e->getMessage())->toContain('session not authenticated yet');
})->with(['encrypt', 'decrypt', 'sendOps', 'receiveOps']);

// A decryption failure says nothing about the build, so it must not read like
// one — it means the ciphertext was altered, replayed, or sent under another
// key, and the cause is kept for the cases where sodium supplied one.
it('describes an AEAD rejection as a ciphertext or key problem', function (): void {
    $cause = new SodiumException('invalid tag');

    expect(NoiseDecryptionFailedException::aeadRejected()->getMessage())
        ->toContain('invalid ciphertext or wrong key')
        ->and(NoiseDecryptionFailedException::aeadRejected()->getPrevious())->toBeNull()
        ->and(NoiseDecryptionFailedException::aeadRejected($cause)->getPrevious())->toBe($cause);
});

it('says a nonce-exhausted session must rekey rather than retry', function (): void {
    expect(NoiseNonceExhaustedException::beforeRekey()->getMessage())
        ->toContain('rekey');
});

it('names the handshake message the peer never sent', function (): void {
    expect(PeerDisconnectedException::beforeHandshakeMessage('msg1')->getMessage())
        ->toContain('msg1');
});

it('says which user already holds the rebuild lock', function (): void {
    expect(RebuildInProgressException::forUser(42)->getMessage())
        ->toContain('user 42')
        ->and(RebuildInProgressException::forUser(42)->getMessage())->toContain('maintenance lock');
});
