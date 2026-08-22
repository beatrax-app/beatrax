<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpLogWriterFactory;

// OpLogWriter needs four runtime primitives no autowiring can supply — a device
// id, a user id and a signing pair — and they come from an identity only an
// unlocked session can open. The factory refuses rather than returning a writer
// that would sign with nothing, because a capture that silently produces
// unsigned entries is one no peer can ever verify.

function opLogWriterFactory(): OpLogWriterFactory
{
    return new OpLogWriterFactory(app(Container::class));
}

/**
 * @return array{deviceId: string, userId: int, secretKey: string, publicKey: string}
 */
function opLogWriterCredentials(): array
{
    $pair = sodium_crypto_sign_keypair();

    return [
        'deviceId' => 'device-under-test',
        'userId' => 1,
        'secretKey' => sodium_crypto_sign_secretkey($pair),
        'publicKey' => sodium_crypto_sign_publickey($pair),
    ];
}

it('builds a writer from credentials the caller already holds', function (): void {
    expect(opLogWriterFactory()->make(opLogWriterCredentials()))->toBeInstanceOf(OpLogWriter::class);
});

// No authenticated user is not an empty capture, it is a capture that cannot be
// attributed. Callers already read a failed resolution as "capture is not
// possible right now", so throwing is what keeps that contract.
it('refuses to resolve for the current user when nobody is signed in', function (): void {
    expect(fn (): OpLogWriter => opLogWriterFactory()->make([]))
        ->toThrow(BindingResolutionException::class, 'no authenticated user');
});

// Each of the four is checked, not just the presence of the array: a caller that
// passes three of them is the shape a refactor produces, and the writer would
// otherwise be built around whatever `null` casts to.
it('refuses a credential set missing any one of the four', function (array $missing): void {
    $credentials = opLogWriterCredentials();
    unset($credentials[$missing[0]]);

    expect(fn (): OpLogWriter => opLogWriterFactory()->make($credentials))
        ->toThrow(BindingResolutionException::class, 'explicit credentials are incomplete');
})->with([
    'no device id' => [['deviceId']],
    'no user id' => [['userId']],
    'no secret key' => [['secretKey']],
    'no public key' => [['publicKey']],
]);

// A user id arriving as a numeric string is the shape a query builder or a URL
// hands over. Accepting it would put a string into a column the merge rules
// compare as an integer.
it('refuses a credential of the wrong type rather than coercing it', function (): void {
    $credentials = opLogWriterCredentials();
    $credentials['userId'] = '1';

    expect(fn (): OpLogWriter => opLogWriterFactory()->make($credentials))
        ->toThrow(BindingResolutionException::class, 'explicit credentials are incomplete');
});

// The distinction the two paths turn on: an empty array means "resolve who is
// signed in", anything else means "use exactly these". A caller passing extra
// keys is still on the explicit path.
it('treats only an empty array as a request to resolve the current user', function (): void {
    $credentials = opLogWriterCredentials();
    $credentials['somethingElse'] = 'ignored';

    expect(opLogWriterFactory()->make($credentials))->toBeInstanceOf(OpLogWriter::class);
});
