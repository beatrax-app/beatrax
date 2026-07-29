<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Services\UserDataPathService;
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
        ->toThrow(RuntimeException::class, 'Cannot write relay config');

    @rmdir($path);
});
