<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Modules\Sync\Public\Services\SyncPorts;

// SYNC_PORT and SYNC_RELAY_PORT were documented env vars wired to nothing:
// config/sync.php was read nowhere and every listener, spawner and dialler
// carried its own copy of the number. A sync port is what someone changes on a
// hostile network, so a knob that silently does nothing is worse than none.

it('resolves both ports from config rather than a compiled-in number', function (): void {
    config(['sync.port' => 51999, 'sync.relay_port' => 52999]);

    $ports = app(SyncPorts::class);

    expect($ports->lan())->toBe(51999)
        ->and($ports->relay())->toBe(52999);
});

it('falls back to the documented default when the config key is absent', function (): void {
    /** @var Repository $config */
    $config = app(Repository::class);
    $config->set('sync', []);

    $ports = app(SyncPorts::class);

    expect($ports->lan())->toBe(SyncPorts::DEFAULT_PORT)
        ->and($ports->relay())->toBe(SyncPorts::DEFAULT_RELAY_PORT);
});

// Both daemons range-check the port they are about to bind and name it in the
// refusal, which is the only observable proof the configured value reaches the
// bind path without this test standing up a listener.
it('carries a configured sync port into the sync:serve daemon', function (): void {
    config(['sync.port' => 70000]);

    $this->artisan('sync:serve')
        ->expectsOutputToContain('invalid port 70000')
        ->assertExitCode(Command::FAILURE);
});

it('carries a configured relay port into the relay:serve daemon', function (): void {
    config(['sync.relay_port' => 70000]);

    $this->artisan('relay:serve')
        ->expectsOutputToContain('invalid port 70000')
        ->assertExitCode(Command::FAILURE);
});

// An explicit --port still wins: the desktop host spawns both daemons with one.
it('lets an explicit --port override the configured default', function (): void {
    config(['sync.port' => 51999]);

    $this->artisan('sync:serve', ['--port' => '70001'])
        ->expectsOutputToContain('invalid port 70001')
        ->assertExitCode(Command::FAILURE);
});
