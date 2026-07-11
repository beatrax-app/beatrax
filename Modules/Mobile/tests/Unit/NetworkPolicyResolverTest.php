<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Sync\NetworkPolicyResolver;
use Native\Mobile\Facades\Network;

/*
 * NetworkPolicyResolverTest — D-09/D-10 mobile network policy (15-05-PLAN.md
 * Task 1).
 *
 * D-09: sync on ANY network by default, including cellular.
 * D-10: "Pause sync on cellular" toggle, default OFF, persisted to
 * mobile/network-policy.json (never .env).
 *
 * The policy file is device-scoped (not per-user), at a FIXED path — clean
 * it up before/after each test so runs never interfere with each other.
 */

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
    // nativephp/mobile-network only ships under mobile-app/vendor (the
    // on-device Composer root) — the repo-root vendor/ the host toolchain
    // runs this test against does NOT have it installed (15-05-PLAN.md
    // environment note). shouldSyncNow() must never fatal here.
    expect(class_exists(Network::class))->toBeFalse();

    $resolver = new NetworkPolicyResolver;
    $resolver->setPauseOnCellular(true);

    // Even with the pause gate ON, we can never positively confirm a
    // cellular/expensive connection without the plugin — the gate only
    // fires on a positive signal, never on ambiguity.
    expect($resolver->shouldSyncNow())->toBeTrue();
});

it('one policy object governs both the pause-on-cellular read and the shouldSyncNow() decision', function (): void {
    expect(class_exists(NetworkPolicyResolver::class))->toBeTrue();

    $resolver = new NetworkPolicyResolver;
    expect(method_exists($resolver, 'shouldSyncNow'))->toBeTrue();
    expect(method_exists($resolver, 'pauseOnCellular'))->toBeTrue();
    expect(method_exists($resolver, 'setPauseOnCellular'))->toBeTrue();
});
