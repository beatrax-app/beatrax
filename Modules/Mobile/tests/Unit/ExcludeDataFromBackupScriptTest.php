<?php

declare(strict_types=1);

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\PersistedStore;
use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Symfony\Component\Process\Process;

// The shell shape the patch writes against, trimmed to the two functions it
// anchors on. getAppSupportDir builds Library/Application Support;
// preparePhpEnvironment is the last thing that runs before PHP is embedded,
// which is the only place a flag can still get ahead of PHP's own mkdir.
function excludeBackupFixtureSwift(): string
{
    return <<<'SWIFT'
        import SwiftUI

        @main
        struct NativePHPApp: App {
            private func getAppSupportDir(dir: String) -> String {
                let appSupportURL = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first

                let destination = appSupportURL!.appendingPathComponent(dir)

                do {
                    try FileManager.default.createDirectory(
                        at: destination,
                        withIntermediateDirectories: true,
                        attributes: nil
                    )
                } catch {
                    // Handle the error
                }

                return destination.path
            }

            private func preparePhpEnvironment() -> String {
                let phpIniPath = createPhpIni()

                setenv("PHPRC", phpIniPath, 1)

                setupEnvironment()

                createDatabase()

                return output
            }
        }
        SWIFT;
}

function excludeBackupFixtureManifest(): string
{
    return <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <manifest xmlns:android="http://schemas.android.com/apk/res/android">
            <application android:allowBackup="true" android:label="Beatrax">
            </application>
        </manifest>
        XML;
}

/** @return array{0: string, 1: string} the fake native root and the Swift file inside it */
function excludeBackupScaffold(bool $withAndroid = true): array
{
    $root = sys_get_temp_dir().'/beatrax-backup-'.bin2hex(random_bytes(6));
    $ios = $root.'/nativephp/ios/NativePHP';

    mkdir($ios, 0o755, true);
    file_put_contents($ios.'/NativePHPApp.swift', excludeBackupFixtureSwift()."\n");

    if ($withAndroid) {
        $android = $root.'/nativephp/android/app/src/main';
        mkdir($android, 0o755, true);
        file_put_contents($android.'/AndroidManifest.xml', excludeBackupFixtureManifest()."\n");
    }

    return [$root, $ios.'/NativePHPApp.swift'];
}

function excludeBackupRun(string $root): Process
{
    $scripts = NativeBuildPatches::locate(base_path());

    expect($scripts)->not->toBeNull();

    $process = new Process(
        [PHP_BINARY, $scripts.'/nativephp_exclude_data_from_backup.php'],
        env: ['BEATRAX_NATIVE_ROOT' => $root],
    );
    $process->run();

    return $process;
}

// What the patched shell will actually create and flag, read back out of the
// Swift rather than assumed: the store directory it hangs off base_path()'s
// parent, and every relative directory it walks.
/** @return array{root: string, directories: list<string>} */
function excludeBackupTree(string $swift, string $documents): array
{
    $pattern = '/\.deletingLastPathComponent\(\)\s*\n\s*\.appendingPathComponent\("([^"]+)"\)/';

    expect($swift)->toMatch($pattern);
    preg_match($pattern, $swift, $store);

    expect($swift)->toMatch('/for relative in \[([^\]]+)\]/');
    preg_match('/for relative in \[([^\]]+)\]/', $swift, $list);
    preg_match_all('/"([^"]+)"/', $list[1], $relatives);

    $root = $documents.'/'.$store[1];

    return [
        'root' => $root,
        'directories' => array_map(
            static fn (string $relative): string => $root.'/'.$relative,
            $relatives[1],
        ),
    ];
}

// The defect this file exists for: the exclusion was set inside
// getAppSupportDir, so it flagged Library/Application Support, on the premise
// that the database lived there. It does not. On iOS base_path() is
// Documents/app, the store is Documents/persisted_data, and Documents is in
// iCloud backup by default — so the ledger, the GDK keyring, the sync identity
// and the staged secrets were all in the user's iCloud backup, silently, while
// a hardware check that recorded no path reported the requirement satisfied.

it('flags the tree the path service writes to, not a tree beside it', function (): void {
    [$root, $app] = excludeBackupScaffold();

    $process = excludeBackupRun($root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $swift = (string) file_get_contents($app);

    // The device layout, and the only fact this test hard-codes: base_path() on
    // iOS is <container>/Documents/app, so every durable path the service
    // resolves hangs off <container>/Documents.
    $documents = '/private/var/mobile/Containers/Data/Application/BEATRAX/Documents';

    $originalBase = $this->app->basePath();
    $originalStorage = getenv('NATIVEPHP_STORAGE_PATH');

    putenv('NATIVEPHP_STORAGE_PATH');
    putenv('NATIVEPHP_PLATFORM=ios');

    try {
        $this->app->setBasePath($documents.'/app');

        ['root' => $store, 'directories' => $excluded] = excludeBackupTree($swift, $documents);

        // Named by the service, not by this test: move where the keyring goes
        // and this is what fails, rather than a device six weeks later.
        $written = [
            'database' => UserDataPathService::databaseFile(),
            'gdk keyring' => UserDataPathService::appPath('sync/gdk/1.enc'),
            'sync identity' => UserDataPathService::appPath('sync/identity/1.enc'),
            'secrets' => UserDataPathService::secretsPath(),
            'backups' => UserDataPathService::backupsPath(),
        ];

        $unflagged = [];

        foreach ($written as $what => $path) {
            $covered = array_filter(
                $excluded,
                static fn (string $directory): bool => $path === $directory
                    || str_starts_with($path, $directory.'/'),
            );

            if ($covered === []) {
                $unflagged[] = $what.' at '.$path;
            }
        }

        expect($unflagged)->toBe([], 'outside every excluded directory: '.implode(', ', $unflagged))
            ->and($store)->toBe(dirname(UserDataPathService::databaseFile(), 2))
            ->and($store)->toBe(dirname(UserDataPathService::appPath(), 2));
    } finally {
        $this->app->setBasePath($originalBase);
        putenv('NATIVEPHP_PLATFORM');

        if (is_string($originalStorage) && $originalStorage !== '') {
            putenv('NATIVEPHP_STORAGE_PATH='.$originalStorage);
        }
    }
});

it('reaches every file the walk can find, and re-asserts on the way to the background', function (): void {
    // The handset carried eight files in this tree: the ledger, its -wal and
    // -shm, two relay secrets, relay.json, the GDK keyring and the sync
    // identity. Only the first three sit under database/; the rest arrive
    // during pairing, long after the shell's own pass — hence both halves.
    [$root, $app] = excludeBackupScaffold();

    excludeBackupRun($root);

    $swift = (string) file_get_contents($app);

    expect($swift)->toContain('while let url = pending.popLast()')
        ->and($swift)->toContain('contentsOfDirectory(')
        ->and($swift)->toContain('values.isExcludedFromBackup = true')
        ->and($swift)->toContain('UIApplication.didEnterBackgroundNotification');
});

it('creates and flags the store before the PHP runtime can create it first', function (): void {
    // Order is the whole mechanism. NSURLIsExcludedFromBackupKey is set on a
    // node that exists; if PHP's @mkdir in mobile-app/bootstrap/app.php gets
    // there first the shell flags a directory it did not make, and a shell that
    // ran after PHP would flag it a launch late.
    [$root, $app] = excludeBackupScaffold();

    excludeBackupRun($root);

    $swift = (string) file_get_contents($app);

    $start = strpos($swift, 'private func preparePhpEnvironment() -> String {');

    expect($start)->toBeInt();

    $body = substr($swift, (int) $start);

    $call = strpos($body, 'prepareDurableStore()');
    $phpIni = strpos($body, 'createPhpIni()');
    $database = strpos($body, 'createDatabase()');

    expect($call)->toBeInt()
        ->and($phpIni)->toBeInt()
        ->and($database)->toBeInt()
        ->and($call)->toBeLessThan($phpIni)
        ->and($call)->toBeLessThan($database);
});

it('reads the flag back and names the path, which is what the 2026-09-04 check could not', function (): void {
    // The device check is this log line. It reports the store path, how many
    // nodes came back excluded and how many did not, so a run proves a named
    // tree rather than "a database" — the phrasing that passed against a 4 KB
    // empty stub in Application Support.
    [$root, $app] = excludeBackupScaffold();

    excludeBackupRun($root);

    $swift = (string) file_get_contents($app);

    expect($swift)->toContain('resourceValues(forKeys: [.isExcludedFromBackupKey])')
        ->and($swift)->toContain('backup-excluded flagged=')
        ->and($swift)->toContain('unflagged=')
        ->and($swift)->toContain('backup-NOT-excluded');
});

it('patches iOS even where there is no Android scaffold to patch', function (): void {
    // The Android half used to exit(0) for the whole script when no manifest
    // was there, so an iOS-only tree got the skip line and no exclusion at all.
    [$root, $app] = excludeBackupScaffold(withAndroid: false);

    $process = excludeBackupRun($root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and((string) file_get_contents($app))->toContain('private func prepareDurableStore()');
});

it('produces the same bytes on a second run', function (): void {
    [$root, $app] = excludeBackupScaffold();

    excludeBackupRun($root);
    $first = (string) file_get_contents($app);

    $second = excludeBackupRun($root);

    expect($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and((string) file_get_contents($app))->toBe($first);
});

it('fails loudly when the shell no longer has the function the store patch anchors on', function (): void {
    [$root] = excludeBackupScaffold();
    $app = $root.'/nativephp/ios/NativePHP/NativePHPApp.swift';

    file_put_contents($app, str_replace(
        'private func preparePhpEnvironment() -> String {',
        'private func preparePhpEnvironment() -> String? {',
        (string) file_get_contents($app),
    ));

    $process = excludeBackupRun($root);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('preparePhpEnvironment anchor not found');
});

it('fails loudly when the application-support anchor has gone', function (): void {
    [$root] = excludeBackupScaffold();
    $app = $root.'/nativephp/ios/NativePHP/NativePHPApp.swift';

    file_put_contents($app, str_replace(
        'withIntermediateDirectories: true,',
        'withIntermediateDirectories: false,',
        (string) file_get_contents($app),
    ));

    $process = excludeBackupRun($root);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('getAppSupportDir anchor not found');
});

it('upgrades a shell already patched on the premise that turned out to be false', function (): void {
    // The real upgrade path on a machine that has run the old script: the
    // Application Support half is applied, so its anchor is gone, and the
    // premise it wrote — that the database lives there — is still in the file.
    [$root, $app] = excludeBackupScaffold();

    $legacy = str_replace(
        <<<'SWIFT'
                    )
                } catch {
        SWIFT,
        <<<'SWIFT'
                    )

                    // scripts/nativephp_exclude_data_from_backup.php — Application
                    // Support is in iCloud backup by default, and this is where the
                    // database and the keyring live. Set as the directory is created,
                    // because the flag applies from the moment the file exists.
                    var excluded = destination
                    var values = URLResourceValues()
                    values.isExcludedFromBackup = true
                    try excluded.setResourceValues(values)
                } catch {
        SWIFT,
        (string) file_get_contents($app),
    );

    file_put_contents($app, $legacy);

    $process = excludeBackupRun($root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $patched = (string) file_get_contents($app);

    expect($patched)->toContain('private func prepareDurableStore()')
        ->and($patched)->not->toContain('this is where the')
        ->and($patched)->toContain('backup-exclusion: application support')
        ->and(substr_count($patched, 'var excluded = destination'))->toBe(1);
});

it('is a required patch, so a failure stops the build rather than warning in a log', function (): void {
    // A prebuild hook's non-zero exit is swallowed by NativePHP's runProcess(),
    // and this patch's failure is invisible on the device — nothing about a
    // running app says whether its ledger is in iCloud.
    $required = (new ReflectionClass(NativeBuildPatches::class))
        ->getReflectionConstant('REQUIRED_SCRIPTS')
        ->getValue();

    expect($required)->toContain('nativephp_exclude_data_from_backup.php');
});

it('spells the store layout nowhere but PersistedStore', function (): void {
    $scripts = NativeBuildPatches::locate(base_path());
    $source = (string) file_get_contents($scripts.'/nativephp_exclude_data_from_backup.php');

    expect($source)->not->toContain("'".PersistedStore::DIRECTORY."'")
        ->and($source)->not->toContain('"'.PersistedStore::DIRECTORY.'"')
        ->and($source)->toContain('PersistedStore::DIRECTORY')
        ->and($source)->toContain('PersistedStore::relativeDirectories()');
});
