<?php

declare(strict_types=1);

// The generated shells put the ledger in a cloud by default: Android's manifest
// ships allowBackup="true", and iOS backs up Application Support to iCloud.
// Neither is announced anywhere, and neither fails a build, so the only thing
// standing between the database and a Google or Apple account is this script.

function backupExclusionScript(): string
{
    $script = dirname(__DIR__, 4).'/scripts/nativephp_exclude_data_from_backup.php';

    expect(is_file($script))->toBeTrue("The patch script is not at {$script}.");

    return $script;
}

function runBackupExclusion(string $root): array
{
    $process = proc_open(
        ['php', backupExclusionScript()],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['BEATRAX_NATIVE_ROOT' => $root, 'PATH' => getenv('PATH')],
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

function backupScaffoldRoot(): string
{
    $root = sys_get_temp_dir().'/beatrax-backup-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    return $root;
}

function withAndroidScaffold(string $root, string $manifest): string
{
    mkdir($root.'/nativephp/android/app/src/main/res/xml', 0700, true);
    file_put_contents($root.'/nativephp/android/app/src/main/AndroidManifest.xml', $manifest);

    return $root;
}

function stockAndroidManifest(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application
        android:allowBackup="true"
        android:label="Beatrax">
    </application>
</manifest>
XML;
}

function withIosScaffold(string $root, string $body): string
{
    mkdir($root.'/nativephp/ios/NativePHP', 0700, true);
    file_put_contents(
        $root.'/nativephp/ios/NativePHP/NativePHPApp.swift',
        $body.stockPreparePhpEnvironment(),
    );

    return $root;
}

// The script patches two sites and exits 1 if either anchor is missing, so a
// scaffold carrying only the first is not a shell this script has ever met.
function stockPreparePhpEnvironment(): string
{
    return "\n"
        ."    private func preparePhpEnvironment() -> String {\n"
        ."        let phpIniPath = createPhpIni()\n"
        ."        return phpIniPath\n"
        ."    }\n";
}

// Concatenated rather than heredoc: the anchor the script matches is
// indentation-exact, and an indented closing marker would strip the very
// spaces being matched.
function stockGetAppSupportDir(string $insideTheDo = ''): string
{
    return "    private func getAppSupportDir() -> URL {\n"
        ."        do {\n"
        ."            try FileManager.default.createDirectory(\n"
        ."                at: destination,\n"
        ."                withIntermediateDirectories: true,\n"
        ."                attributes: nil\n"
        ."            )\n"
        .$insideTheDo
        ."        } catch {\n"
        ."            // Handle the error\n"
        ."        }\n"
        ."        return destination\n"
        ."    }\n";
}

// What this script itself wrote before the failure could be reported: the
// exclusion is set and never read back, and a throw lands in the mkdir's catch.
function silentIosExclusion(): string
{
    return "\n"
        ."            // scripts/nativephp_exclude_data_from_backup.php — Application\n"
        ."            // Support is in iCloud backup by default.\n"
        ."            var excluded = destination\n"
        ."            var values = URLResourceValues()\n"
        ."            values.isExcludedFromBackup = true\n"
        ."            try excluded.setResourceValues(values)\n";
}

function iosShell(string $root): string
{
    return (string) file_get_contents($root.'/nativephp/ios/NativePHP/NativePHPApp.swift');
}

it('takes the Android data directory out of cloud backup and device transfer', function (): void {
    $root = withAndroidScaffold(backupScaffoldRoot(), stockAndroidManifest());

    $result = runBackupExclusion($root);
    expect($result['status'])->toBe(0);

    $main = $root.'/nativephp/android/app/src/main';
    $manifest = (string) file_get_contents($main.'/AndroidManifest.xml');

    expect($manifest)->toContain('android:allowBackup="false"')
        ->and($manifest)->not->toContain('android:allowBackup="true"');

    // allowBackup="false" stops cloud backup and nothing else. Android 12+
    // reads <device-transfer> independently of it, so a device-to-device
    // transfer carries the database unless the rule files say otherwise.
    expect((string) file_get_contents($main.'/res/xml/data_extraction_rules.xml'))
        ->toContain('<device-transfer>')
        ->toContain('<cloud-backup>');

    expect((string) file_get_contents($main.'/res/xml/backup_rules.xml'))
        ->toContain('<full-backup-content>');
});

// An XML comment between an element's attributes is not well-formed, and the
// manifest merger rejects the whole file. Gradle died in it once, with a SAX
// error nobody could see, for exactly that reason.
it('leaves the manifest well-formed', function (): void {
    $root = withAndroidScaffold(backupScaffoldRoot(), stockAndroidManifest());

    runBackupExclusion($root);

    $parsed = @simplexml_load_file($root.'/nativephp/android/app/src/main/AndroidManifest.xml');

    expect($parsed)->not->toBeFalse();
});

it('gives the iOS exclusion its own catch and reads the flag back', function (): void {
    $root = withIosScaffold(backupScaffoldRoot(), stockGetAppSupportDir());

    $result = runBackupExclusion($root);
    expect($result['status'])->toBe(0);

    $patched = iosShell($root);

    expect($patched)->toContain('values.isExcludedFromBackup = true');

    // The load-bearing half. Without the read-back the flag is only known to
    // have been *asked for*, and without its own catch a throw is indexed
    // under a failed mkdir — either way the ledger sits in iCloud silently.
    expect($patched)->toContain('.isExcludedFromBackupKey')
        ->and($patched)->toContain('NSLog');
});

// The one case that must not be skipped: the marker is already there, so a
// marker-only guard would call this patched and leave the silent form shipping.
it('upgrades a shell already carrying the silent form', function (): void {
    $root = withIosScaffold(backupScaffoldRoot(), stockGetAppSupportDir(silentIosExclusion()));

    expect(iosShell($root))->not->toContain('.isExcludedFromBackupKey');

    $result = runBackupExclusion($root);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('upgraded')
        ->and(iosShell($root))->toContain('.isExcludedFromBackupKey')
        ->and(iosShell($root))->toContain('NSLog');
});

// The Android half used to end in exit(0) when there was no manifest, which
// ended the process: a tree with only the iOS scaffold got no iCloud exclusion
// at all, and said so in a line about Android, with a zero exit status.
it('excludes iOS even when no Android scaffold has been generated', function (): void {
    $root = withIosScaffold(backupScaffoldRoot(), stockGetAppSupportDir());

    $result = runBackupExclusion($root);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('no Android scaffold')
        ->and(iosShell($root))->toContain('.isExcludedFromBackupKey');
});

it('patches Android even when no iOS scaffold has been generated', function (): void {
    $root = withAndroidScaffold(backupScaffoldRoot(), stockAndroidManifest());

    $result = runBackupExclusion($root);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('no iOS scaffold')
        ->and((string) file_get_contents($root.'/nativephp/android/app/src/main/AndroidManifest.xml'))
        ->toContain('android:allowBackup="false"');
});

it('is idempotent, because native:install regenerates the tree under it', function (): void {
    $root = withIosScaffold(
        withAndroidScaffold(backupScaffoldRoot(), stockAndroidManifest()),
        stockGetAppSupportDir(),
    );

    runBackupExclusion($root);
    $once = iosShell($root);

    $again = runBackupExclusion($root);

    expect($again['status'])->toBe(0)
        ->and(iosShell($root))->toBe($once)
        ->and($again['stdout'])->toContain('Android already patched')
        ->and($again['stdout'])->toContain('iOS already patched');
});

// A silent skip ships the default, which is the defect this script exists for.
it('fails loudly when the generated manifest changed shape', function (): void {
    $root = withAndroidScaffold(
        backupScaffoldRoot(),
        str_replace('allowBackup', 'someOtherFlag', stockAndroidManifest()),
    );

    $result = runBackupExclusion($root);

    expect($result['status'])->toBe(1)
        ->and($result['stderr'])->toContain('anchor not found');
});

it('fails loudly when the generated iOS shell changed shape', function (): void {
    $root = withIosScaffold(backupScaffoldRoot(), "    private func getAppSupportDir() -> URL { return destination }\n");

    $result = runBackupExclusion($root);

    expect($result['status'])->toBe(1)
        ->and($result['stderr'])->toContain('anchor not found');
});

it('skips a checkout that has no native scaffold yet', function (): void {
    $result = runBackupExclusion(backupScaffoldRoot());

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('no Android scaffold')
        ->and($result['stdout'])->toContain('no iOS scaffold');
});
