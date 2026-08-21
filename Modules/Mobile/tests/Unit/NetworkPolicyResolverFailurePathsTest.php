<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Exceptions\NetworkPolicyException;
use Modules\Mobile\Internal\Sync\NetworkPolicyResolver;

// UserDataPathService derives its root from NATIVEPHP_STORAGE_PATH, so each test
// redirects it at a throwaway tree it can make un-creatable or un-writable without
// touching the real device store.
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

// A silently dropped toggle would let sync run on a connection the user paused, so
// both local-storage faults surface as exceptions.

it('throws NetworkPolicyException::directoryNotCreatable when the policy directory cannot be made', function (): void {
    withTempStorageRoot(function (string $root): void {
        // A regular file planted at "<root>/app" means mkdir() of any child
        // underneath it can never succeed.
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

        // Occupying the exact target path with a directory makes
        // file_put_contents() fail whatever the running uid, so this stays
        // deterministic under a root CI runner where chmod would not bite.
        mkdir($path, 0700, true);

        $resolver = new NetworkPolicyResolver;

        expect(fn () => $resolver->setPauseOnCellular(true))
            ->toThrow(NetworkPolicyException::class, "Cannot write network policy to: {$path}");
    });
});
