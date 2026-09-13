<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Public\Services\UserDataPathService;
use Tests\Contracts\Support\RepoTree;

// An imported statement is copied to a stable location so the ledger can point
// at its source. Which location that is comes from the filesystem disk, and the
// framework's default root for 'local' is storage_path('app/private').
//
// The desktop shell remaps storage_path() to the writable data directory, so
// there the default is already right. Both mobile shells remap it too, under
// their own name -- LARAVEL_STORAGE_PATH -- but not to the same tree: Android
// points it at persisted_data/storage, iOS at Application Support/storage,
// which is NOT where base_path()'s sibling store is. So on an iPhone the
// framework's default root and the durable half are two different directories,
// and the durable half is persisted_data/, which is what the path service
// answers.

/** Whether the file resolves a filesystem disk by name, in either spelling. */
function uploadedArtifactNamesADisk(string $source): bool
{
    return preg_match('/(?:Storage::disk|->disk)\s*\(/', $source) === 1;
}

/**
 * The roots come from RepoTree rather than from app/ and Modules/: the rule
 * says no caller anywhere has gone unaccounted for, and a disk named from
 * routes/, config/ or scripts/ was outside the walk that stated it.
 *
 * @return list<string> production files that resolve a filesystem disk by name
 */
function uploadedArtifactDiskCallers(): array
{
    $found = [];

    foreach (RepoTree::files(RepoTree::PRODUCTION_PHP) as $path) {
        if (uploadedArtifactNamesADisk((string) file_get_contents($path))) {
            $found[] = str_replace(RepoTree::root().'/', '', $path);
        }
    }

    sort($found);

    return $found;
}

it('resolves the upload disk through the path service, not through storage_path', function (): void {
    expect(config('filesystems.disks.local.root'))
        ->toBe(UserDataPathService::appPath('private'));
});

// Without this the assertion above proves nothing off-device: in a plain
// checkout storage_path('app/private') and appPath('private') are the same
// directory, so a binding that had been deleted would still look correct.
it('binds a root that a phone would otherwise resolve somewhere else', function (): void {
    $platform = getenv('NATIVEPHP_PLATFORM');

    // The suite sets NATIVEPHP_STORAGE_PATH, which the path service honours
    // ahead of everything else -- so the mobile branch is unreachable in a
    // test until it is cleared. A phone has the platform and not the override.
    $storage = getenv('NATIVEPHP_STORAGE_PATH');

    putenv('NATIVEPHP_PLATFORM=ios');
    putenv('NATIVEPHP_STORAGE_PATH');

    try {
        expect(UserDataPathService::isMobileRuntime())
            ->toBeTrue('the mobile branch has to be reachable, or the two assertions below prove nothing about a phone')
            ->and(UserDataPathService::appPath('private'))->not->toBe(
                storage_path('app/private'),
                'on a phone storage_path() names the unpacked bundle, which an app update replaces, so the two must not be the same directory',
            )
            ->and(UserDataPathService::appPath('private'))->toContain('persisted_data');
    } finally {
        $platform === false ? putenv('NATIVEPHP_PLATFORM') : putenv('NATIVEPHP_PLATFORM='.$platform);
        $storage === false ? putenv('NATIVEPHP_STORAGE_PATH') : putenv('NATIVEPHP_STORAGE_PATH='.$storage);
    }
});

// The binding lives in one place, and a deleted line is the failure this rule
// exists for -- the runtime assertion above cannot see it off-device.
it('keeps the binding where the container can apply it before a disk resolves', function (): void {
    $provider = (string) file_get_contents(base_path('Modules/Core/Providers/CoreServiceProvider.php'));

    expect($provider)->toContain("->set('filesystems.disks.local.root', UserDataPathService::appPath('private'));");
});

// A new caller naming a disk this rule has not considered is the way the
// defect returns, so the set is closed rather than sampled.
//
// Both entries name the SAME disk, and StagedStatementPath owns the constant
// RunImport resolves it through, so neither can drift to a second root. They
// inherit the binding above, which is appPath('private') — persisted_data/ on
// a phone, the directory an app update does not replace.
it('has no filesystem-disk caller that has not been accounted for', function (): void {
    $callers = uploadedArtifactDiskCallers();

    expect($callers)->toBe([
        'Modules/Import/Internal/Services/StagedStatementPath.php',
        'Modules/Import/Public/Actions/RunImport.php',
    ], implode("\n  ", [
        'Two places in the product resolve a filesystem disk by name — the writer that stages',
        'an upload and the reader that proves a stored path is this device\'s own — and the',
        'binding above is what decides where that disk is rooted. A further caller either',
        'inherits the same root, in which case it belongs in this list, with the reader having',
        'checked that a phone would put its file somewhere an app update does not replace — or',
        'names a disk of its own, which this rule then says nothing about. Compared in both',
        'directions: a caller that disappeared fails here as loudly as one that appeared.',
        'Found: '.implode(', ', $callers),
    ]));
});

it('reads a disk resolved by name in both spellings, and leaves a method that merely starts with one alone', function (): void {
    expect(uploadedArtifactNamesADisk("<?php Storage::disk('local')->put(\$path, \$body);"))
        ->toBeTrue('the facade spelling is one of the two ways a disk is named');

    expect(uploadedArtifactNamesADisk('<?php $this->storage->disk(self::STORAGE_DISK);'))
        ->toBeTrue('the injected-manager spelling is the one RunImport uses');

    expect(uploadedArtifactNamesADisk('<?php $report->diskUsageInBytes();'))
        ->toBeFalse('a method whose name merely starts with disk resolves nothing');
});

/**
 * Every registered route whose name the framework minted for a served disk.
 *
 * @return list<string>
 */
function uploadedArtifactServedDiskRoutes(): array
{
    $named = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (str_starts_with($name, 'storage.')) {
            $named[] = implode('|', $route->methods()).' /'.$route->uri().' ('.$name.')';
        }
    }

    sort($named);

    return $named;
}

// The root above is only half the question. The framework's own default sets
// `serve => true` on this disk, and the provider then registers GET and PUT on
// /storage/{path} with where('path', '.*') inside $this->app->booted() -- after
// the middleware groups, so with no session, no CSRF, no user and no app-lock.
it('serves no disk over HTTP', function (): void {
    $served = [];

    foreach ((array) config('filesystems.disks') as $disk => $config) {
        if (is_array($config) && ($config['serve'] ?? false) === true) {
            $served[] = (string) $disk;
        }
    }

    expect($served)->toBe([], implode("\n  ", [
        'These disks are configured to be served: '.implode(', ', $served).'.',
        'The local disk is rooted on the live user-data tree, which holds imports/{userId}/{sha256}.',
        'ServeFile does require an APP_KEY signature for a disk with no visibility key — but that',
        'signature authenticates the path, not the caller, so one scheme spans every user\'s',
        'directory, and PUT ...?upload=1 writes the request body to whichever path it names.',
    ]));
});

// The config key and the route are two different claims: the key could be right
// while a second disk, or a package, registers the same route under another
// name. This asks the router what it actually holds.
it('registers no route the framework minted for a disk', function (): void {
    $routes = uploadedArtifactServedDiskRoutes();

    expect($routes)->toBe([], implode("\n  ", [
        'The router holds these disk-serving routes:',
        ...$routes,
        '',
        'Set serve => false on the disk in config/filesystems.php rather than deleting the route.',
    ]));
});

// Without this the two assertions above pass on a router that registered
// nothing at all, which is the same empty list and none of the same behaviour.
it('has a routing table to have found one in', function (): void {
    expect(Route::has('dashboard'))
        ->toBeTrue('the application\'s own routes have to be registered, or an absent storage route proves nothing');
});

// The disk config is read the same way the provider reads it, and the provider
// treats a missing key as false. So the guard has to bite on a disk that says
// serve => true, and leave one that says nothing alone.
it('sees a served disk, and leaves a disk that names no serving alone', function (): void {
    $served = static fn (array $disks): array => array_values(array_keys(array_filter(
        $disks,
        static fn (array $config): bool => ($config['serve'] ?? false) === true,
    )));

    expect($served(['local' => ['driver' => 'local', 'serve' => true]]))->toBe(['local'])
        ->and($served(['local' => ['driver' => 'local', 'serve' => false]]))->toBe([])
        ->and($served(['local' => ['driver' => 'local']]))->toBe([]);
});
