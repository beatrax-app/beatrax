<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Public\Services\PairingGateway;

uses(RefreshDatabase::class);

/*
 * A responder needs an identity of its own before it can accept a pairing
 * token. load() reports "never minted" and "app is locked" as the same null,
 * so anything that mints must gate on the key-file itself — minting over a
 * locked device's identity would orphan every pairing it already had.
 */

function identityPreconditionUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('reports no identity file before one has ever been minted', function (): void {
    $user = identityPreconditionUser('precondition-fresh');

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);

    expect($gateway->hasIdentityFile((int) $user->id))->toBeFalse();
});

it('reports an identity file once the responder bootstrap has run', function (): void {
    $user = identityPreconditionUser('precondition-minted');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);

    $gateway->enableSyncIdentityWithoutEpoch((int) $user->id, $session);

    expect($gateway->hasIdentityFile((int) $user->id))->toBeTrue()
        ->and($gateway->hasUsableIdentity((int) $user->id, $session))->toBeTrue();
});

it('keeps the key-file check independent of whether the app is unlocked', function (): void {
    $user = identityPreconditionUser('precondition-locked');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);

    $gateway->enableSyncIdentityWithoutEpoch((int) $user->id, $session);

    $path = UserDataPathService::appPath("sync/identity/{$user->id}.enc");
    $before = (string) file_get_contents($path);

    // Losing the KEK is what a locked app looks like to the loader.
    $session->flush();

    expect($gateway->hasIdentityFile((int) $user->id))
        ->toBeTrue('a locked device still HAS an identity — it just cannot open it');

    // The property that matters: the file is what a minting caller must gate
    // on, so an identity is never regenerated over a locked one.
    expect((string) file_get_contents($path))->toBe($before);
});

it('does not mint an identity as a side effect of asking whether one exists', function (): void {
    $user = identityPreconditionUser('precondition-readonly');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var PairingGateway $gateway */
    $gateway = app(PairingGateway::class);

    $gateway->hasIdentityFile((int) $user->id);
    $gateway->hasUsableIdentity((int) $user->id, $session);

    expect(file_exists(UserDataPathService::appPath("sync/identity/{$user->id}.enc")))->toBeFalse();
});
