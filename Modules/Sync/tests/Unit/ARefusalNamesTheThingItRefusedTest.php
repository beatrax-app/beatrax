<?php

declare(strict_types=1);

use Modules\Sync\Internal\Exceptions\RelayRefusedException;
use Modules\Sync\Internal\Exceptions\SecretFileException;
use Modules\Sync\Public\Exceptions\CascadeRemovalFailedException;

// Every message below is assembled from a format and its subject. A refusal
// that loses the subject sends whoever is reading the log looking for a path,
// a user or a table that the message no longer names.
it('names the secret-material path it could not write', function (string $factory, string|int $subject): void {
    /** @var callable(string|int): SecretFileException $make */
    $make = [SecretFileException::class, $factory];

    expect($make($subject)->getMessage())->toContain((string) $subject);
})->with([
    ['couldNotStage', '/tmp/secrets/staged.enc'],
    ['couldNotLockDown', '/tmp/secrets/readable.enc'],
    ['couldNotFinalizeKeyring', 70001],
    ['couldNotFinalizeSealedFile', '/tmp/secrets/sealed.enc'],
    ['couldNotReadStagedPlaintext', '/tmp/secrets/plain.tmp'],
    ['couldNotCreateSecretsDirectory', '/tmp/secrets'],
    ['couldNotWriteDrainTokens', '/tmp/secrets/tokens.json'],
    ['couldNotWriteDrainRegistry', '/tmp/secrets/registry.json'],
]);

it('names the size and the cap when a relay blob is too large', function (): void {
    $message = RelayRefusedException::blobTooLarge(1_048_577, 1_048_576)->getMessage();

    expect($message)->toContain('1048577')->toContain('1048576');
});

it('names the TLS backend that cannot pin', function (): void {
    expect(RelayRefusedException::pinningUnsupported('NSS')->getMessage())->toContain('NSS');
});

it('says which relay refusals carry no subject at all', function (): void {
    expect(RelayRefusedException::endpointVanished()->getMessage())
        ->toContain('Relay endpoint unexpectedly null')
        ->and(RelayRefusedException::notConfigured()->getMessage())->not->toBe('')
        ->and(RelayRefusedException::endpointNotHttps()->getMessage())->not->toBe('');
});

it('counts the tables a cascade removal left behind', function (): void {
    expect(CascadeRemovalFailedException::stillCascading(3)->getMessage())->toContain('3 table(s)');
});

it('carries the schema verdict it could not read', function (): void {
    expect(CascadeRemovalFailedException::schemaUnreadable('malformed database schema')->getMessage())
        ->toContain('malformed database schema')
        ->and(CascadeRemovalFailedException::foreignKeysUnenforced()->getMessage())
        ->toContain('foreign keys unenforced');
});
