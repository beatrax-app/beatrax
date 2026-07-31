<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Exceptions\NetworkPolicyException;
use Modules\Mobile\Internal\Sync\NetworkPolicyResolver;

/*
 * NetworkPolicyResolverFailurePathsTest — the two I/O failure branches of
 * NetworkPolicyResolver::setPauseOnCellular() that the happy-path
 * NetworkPolicyResolverTest never reaches:
 *
 *   - the policy directory cannot be created            -> NetworkPolicyException::directoryNotCreatable()
 *   - the policy file itself cannot be written           -> NetworkPolicyException::notWritable()
 *
 * Both are local-storage faults the resolver surfaces (never silently
 * drops), since a lost toggle would let sync run on a paused connection.
 *
 * UserDataPathService::appPath() derives its root from NATIVEPHP_STORAGE_PATH
 * (see UserDataPathService::storageRoot()), so each test redirects that env
 * var to a throwaway temp tree it can make un-creatable / un-writable
 * without touching the real device store, then restores it.
 */

/**
 * Point UserDataPathService at a fresh temp storage root for the duration of
 * $body, restoring the previous NATIVEPHP_STORAGE_PATH afterwards. Returns
 * the temp root so the caller can shape it (e.g. block directory creation).
 */
function withTempStorageRoot(Closure $body): void
{
    $previous = getenv('NATIVEPHP_STORAGE_PATH');
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'netpol-fail-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    putenv('NATIVEPHP_STORAGE_PATH='.$root);

    try {
        $body($root);
    } finally {
        if ($previous === false) {
            putenv('NATIVEPHP_STORAGE_PATH');
        } else {
            putenv('NATIVEPHP_STORAGE_PATH='.$previous);
        }
        // Best-effort recursive cleanup of the throwaway tree.
        exec('chmod -R u+rwx '.escapeshellarg($root).' 2>/dev/null');
        exec('rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
}

it('throws NetworkPolicyException::directoryNotCreatable when the policy directory cannot be made', function (): void {
    withTempStorageRoot(function (string $root): void {
        // appPath() is "<root>/app/mobile/network-policy.json"; its parent
        // dir is "<root>/app/mobile". Plant a regular FILE at "<root>/app"
        // so mkdir() of any child underneath it can never succeed.
        $appAsFile = $root.DIRECTORY_SEPARATOR.'app';
        file_put_contents($appAsFile, 'not a directory');

        $expectedDir = dirname(UserDataPathService::appPath('mobile/network-policy.json'));

        $resolver = new NetworkPolicyResolver;

        expect(fn () => $resolver->setPauseOnCellular(true))
            ->toThrow(NetworkPolicyException::class, "Cannot create network-policy directory: {$expectedDir}");
    });
});

it('throws NetworkPolicyException::notWritable when the policy file cannot be written', function (): void {
    withTempStorageRoot(function (): void {
        $path = UserDataPathService::appPath('mobile/network-policy.json');

        // Create the parent dir the resolver expects, then occupy the exact
        // target path with a DIRECTORY — file_put_contents() to a path that
        // is itself a directory fails regardless of the running uid (so this
        // stays deterministic even under a root CI runner where chmod would
        // not bite).
        mkdir($path, 0700, true);

        $resolver = new NetworkPolicyResolver;

        expect(fn () => $resolver->setPauseOnCellular(true))
            ->toThrow(NetworkPolicyException::class, "Cannot write network policy to: {$path}");
    });
});
