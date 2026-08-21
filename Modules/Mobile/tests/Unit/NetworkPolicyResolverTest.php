<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Sync\NetworkPolicyResolver;
use Native\Mobile\Facades\Network;

// The policy file is device-scoped rather than per-user, at a fixed path, so runs
// interfere with each other unless it is cleaned up around every test.
function networkPolicyFilePath(): string
{
    return UserDataPathService::appPath('mobile/network-policy.json');
}

beforeEach(function (): void {
    @unlink(networkPolicyFilePath());
});

afterEach(function (): void {
    @unlink(networkPolicyFilePath());
});

it('defaults to pause-on-cellular OFF and syncs on any network (D-09) when no policy file exists', function (): void {
    expect(file_exists(networkPolicyFilePath()))->toBeFalse();

    $resolver = new NetworkPolicyResolver;

    expect($resolver->pauseOnCellular())->toBeFalse();
    expect($resolver->shouldSyncNow())->toBeTrue();
});

it('persists the pause-on-cellular toggle to mobile/network-policy.json, never .env', function (): void {
    $resolver = new NetworkPolicyResolver;

    $resolver->setPauseOnCellular(true);

    expect($resolver->pauseOnCellular())->toBeTrue();

    $path = networkPolicyFilePath();
    expect(file_exists($path))->toBeTrue();

    $contents = (string) file_get_contents($path);
    expect($contents)->toContain('pause_on_cellular');

    $envPath = base_path('.env');
    if (file_exists($envPath)) {
        expect((string) file_get_contents($envPath))->not->toContain('pause_on_cellular');
    }
});

it('setPauseOnCellular(false) reverts to the D-09 default (sync everywhere)', function (): void {
    $resolver = new NetworkPolicyResolver;

    $resolver->setPauseOnCellular(true);
    expect($resolver->pauseOnCellular())->toBeTrue();

    $resolver->setPauseOnCellular(false);
    expect($resolver->pauseOnCellular())->toBeFalse();
    expect($resolver->shouldSyncNow())->toBeTrue();
});

it('degrades to "sync now" when the native Network facade is unavailable (class_exists-guarded)', function (): void {
    // nativephp/mobile-network ships only under mobile-app/vendor, so this
    // assertion holds only against the repo-root tree. The mobile-app-rooted CI job
    // runs the same file where the plugin genuinely is installed and excludes this
    // group. shouldSyncNow() must never fatal either way.
    expect(class_exists(Network::class))->toBeFalse();

    $resolver = new NetworkPolicyResolver;
    $resolver->setPauseOnCellular(true);

    // Without the plugin a cellular connection can never be positively confirmed,
    // and the gate fires only on a positive signal, never on ambiguity.
    expect($resolver->shouldSyncNow())->toBeTrue();
})->group('repo-root-only');

it('one policy object governs both the pause-on-cellular read and the shouldSyncNow() decision', function (): void {
    expect(class_exists(NetworkPolicyResolver::class))->toBeTrue();

    $resolver = new NetworkPolicyResolver;
    expect(method_exists($resolver, 'shouldSyncNow'))->toBeTrue();
    expect(method_exists($resolver, 'pauseOnCellular'))->toBeTrue();
    expect(method_exists($resolver, 'setPauseOnCellular'))->toBeTrue();
});
