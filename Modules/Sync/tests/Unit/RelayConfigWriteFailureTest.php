<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\RelayConfigWriteException;
use Modules\Sync\Internal\Exceptions\SecretFileException;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;

uses(RefreshDatabase::class);

/*
 * RelayConfig refuses to report success when it could not write.
 *
 * The endpoint and the auth token decide where this device syncs and what it
 * proves itself with, so a write that silently did nothing would leave the
 * device pointing at whatever the previous value was while the caller believes
 * it was reconfigured.
 *
 * The guards are `=== false` checks on file_put_contents(), which only decide
 * anything because the call is suppressed — unsuppressed, Laravel's error
 * handler converts the E_WARNING to an ErrorException first and the guard
 * never runs.
 */
beforeEach(function (): void {
    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-relay-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'storage';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

// A directory where the config file belongs: file_put_contents refuses it, and
// that refusal has to surface rather than being reported as a successful save.
it('refuses to report a saved endpoint it could not write', function (): void {
    $path = UserDataPathService::appPath('sync/relay.json');
    mkdir(dirname($path), 0700, true);
    mkdir($path);

    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    expect(fn () => $config->setEndpointUrl('https://relay.example/ws'))
        ->toThrow(RelayConfigWriteException::class, 'Cannot write relay config');

    @rmdir($path);
});

// A file where the config directory belongs: mkdir cannot create it and
// is_dir stays false, which is the pair of conditions the guard needs.
it('refuses when the config directory cannot be created', function (): void {
    $dir = dirname(UserDataPathService::appPath('sync/relay.json'));
    mkdir(dirname($dir), 0700, true);
    file_put_contents($dir, 'not a directory');

    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    expect(fn () => $config->setEndpointUrl('https://relay.example/ws'))
        ->toThrow(RelayConfigWriteException::class, 'Cannot create relay config directory');

    @unlink($dir);
});

/*
 * The auth token is key material, so its failures are SecretFileException
 * rather than the config type — the recovery differs. A half-written or
 * world-readable token is a secret on disk in a state the caller has to
 * reason about, which a failed endpoint write never is.
 */

it('refuses when the secrets directory cannot be created', function (): void {
    $dir = UserDataPathService::secretsPath();
    mkdir(dirname($dir), 0700, true);
    file_put_contents($dir, 'not a directory');

    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    expect(fn () => $config->setAuthToken('relay-token'))
        ->toThrow(SecretFileException::class, 'Cannot create secrets directory');

    @unlink($dir);
});

it('refuses to report a saved token it could not write', function (): void {
    $dir = UserDataPathService::secretsPath();
    mkdir($dir, 0700, true);
    mkdir($dir.DIRECTORY_SEPARATOR.'sync-relay-token.json');

    /** @var RelayConfig $config */
    $config = $this->app->make(RelayConfig::class);

    expect(fn () => $config->setAuthToken('relay-token'))
        ->toThrow(SecretFileException::class, 'Cannot write relay token');

    @rmdir($dir.DIRECTORY_SEPARATOR.'sync-relay-token.json');
});
