<?php

declare(strict_types=1);

use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use Modules\Sync\Internal\Exceptions\KeyringStateException;
use Modules\Sync\Internal\Exceptions\SecretFileException;

/*
 * The messages these exceptions carry, pinned.
 *
 * Sync's failure types build their message from a named constructor rather
 * than at the throw site, so the identifiers a maintainer needs — which user,
 * which epoch, which path — live in one place instead of being interpolated
 * into a string at each of sixteen sites. That only helps if the identifiers
 * actually reach the message, which is what these assert. Several of the throw
 * sites themselves are unreachable from a test (a libsodium primitive
 * misbehaving), so without this the formatting would go unchecked entirely.
 */

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
]);
