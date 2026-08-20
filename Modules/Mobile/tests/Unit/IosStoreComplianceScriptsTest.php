<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Symfony\Component\Process\Process;

/*
 * End-to-end guards for the two iOS store-compliance patch scripts.
 *
 * nativephp_ios_privacy_manifest.php answers Apple's required-reason API scan
 * (ITMS-91053), which has been a hard block at App Store Connect since 1 May
 * 2024 and reads the shipped binary's symbols rather than anyone's source.
 *
 * nativephp_ios_export_compliance.php answers the export questionnaire once,
 * in the plist, instead of once per upload in the App Store Connect UI — which
 * an automated pipeline cannot do at all.
 *
 * Both run here against a fixture copy of the real generated project.
 */

function iosComplianceInfoPlist(): string
{
    return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0">
          <dict>
            <key>NSAppTransportSecurity</key>
            <dict>
              <key>NSAllowsArbitraryLoadsInWebContent</key>
              <true/>
            </dict>
            <key>NSCameraUsageDescription</key>
            <string>This app requires camera access to scan QR codes</string>
          </dict>
        </plist>
        XML;
}

/**
 * The two lines of project.pbxproj the privacy-manifest script asserts on.
 * Without the synchronized root group a file dropped into NativePHP/ is in no
 * build phase, so it never reaches the app bundle Apple scans.
 */
function iosCompliancePbxproj(bool $synchronized = true, bool $excepted = false): string
{
    $group = $synchronized
        ? "\t\t95BD5DC32D178E9D00C72704 /* NativePHP */ = {\n"
            ."\t\t\tisa = PBXFileSystemSynchronizedRootGroup;\n"
            ."\t\t\texceptions = (\n\t\t\t);\n"
            ."\t\t\tpath = NativePHP;\n"
            ."\t\t\tsourceTree = \"<group>\";\n\t\t};\n"
        : "\t\t95BD5DC32D178E9D00C72704 /* NativePHP */ = {\n"
            ."\t\t\tisa = PBXGroup;\n"
            ."\t\t\tpath = NativePHP;\n\t\t};\n";

    $exceptions = $excepted
        ? "\t\t\tmembershipExceptions = (\n\t\t\t\tInfo.plist,\n\t\t\t\tPrivacyInfo.xcprivacy,\n\t\t\t);\n"
        : "\t\t\tmembershipExceptions = (\n\t\t\t\tInfo.plist,\n\t\t\t);\n";

    return "// !\$*UTF8*\$!\n{\n"
        ."/* Begin PBXFileSystemSynchronizedBuildFileExceptionSet section */\n"
        .$exceptions
        ."/* End PBXFileSystemSynchronizedBuildFileExceptionSet section */\n"
        ."/* Begin PBXFileSystemSynchronizedRootGroup section */\n"
        .$group
        ."/* End PBXFileSystemSynchronizedRootGroup section */\n}\n";
}

/** @return string the fake native root holding a generated iOS project */
function iosComplianceScaffold(bool $synchronized = true, bool $excepted = false): string
{
    $root = sys_get_temp_dir().'/beatrax-ios-'.bin2hex(random_bytes(6));

    mkdir($root.'/nativephp/ios/NativePHP', 0o755, true);
    mkdir($root.'/nativephp/ios/NativePHP.xcodeproj', 0o755, true);

    file_put_contents($root.'/nativephp/ios/NativePHP/Info.plist', iosComplianceInfoPlist()."\n");
    file_put_contents($root.'/nativephp/ios/NativePHP-simulator-Info.plist', iosComplianceInfoPlist()."\n");
    file_put_contents(
        $root.'/nativephp/ios/NativePHP.xcodeproj/project.pbxproj',
        iosCompliancePbxproj($synchronized, $excepted),
    );

    return $root;
}

function runIosPatch(string $script, string $root): Process
{
    $scripts = NativeBuildPatches::locate(base_path());

    expect($scripts)->not->toBeNull();

    $process = new Process([PHP_BINARY, $scripts.'/'.$script], env: ['BEATRAX_NATIVE_ROOT' => $root]);
    $process->run();

    return $process;
}

it('declares exactly the three required-reason categories the binary trips', function (): void {
    $root = iosComplianceScaffold();

    $process = runIosPatch('nativephp_ios_privacy_manifest.php', $root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $manifest = $root.'/nativephp/ios/NativePHP/PrivacyInfo.xcprivacy';

    expect($manifest)->toBeFile();

    $contents = (string) file_get_contents($manifest);

    expect($contents)
        ->toContain('NSPrivacyAccessedAPICategoryFileTimestamp')
        ->toContain('C617.1')
        ->toContain('NSPrivacyAccessedAPICategoryDiskSpace')
        ->toContain('E174.1')
        ->toContain('NSPrivacyAccessedAPICategoryUserDefaults')
        ->toContain('CA92.1');
});

it('omits the collected-data and tracking-domain keys rather than shipping them empty', function (): void {
    // An empty NSPrivacyCollectedDataTypes is an ITMS-91056 trigger, and for
    // an app with no server it says nothing an absent key does not.
    $root = iosComplianceScaffold();

    runIosPatch('nativephp_ios_privacy_manifest.php', $root);

    $contents = (string) file_get_contents($root.'/nativephp/ios/NativePHP/PrivacyInfo.xcprivacy');

    expect($contents)
        ->not->toContain('NSPrivacyCollectedDataTypes')
        ->not->toContain('NSPrivacyTrackingDomains');
});

it('writes a privacy manifest Apple can parse', function (): void {
    $root = iosComplianceScaffold();

    runIosPatch('nativephp_ios_privacy_manifest.php', $root);

    expect(@simplexml_load_file($root.'/nativephp/ios/NativePHP/PrivacyInfo.xcprivacy'))->not->toBeFalse();
});

it('refuses to leave a privacy manifest that no build phase would copy', function (): void {
    $process = runIosPatch('nativephp_ios_privacy_manifest.php', iosComplianceScaffold(synchronized: false));

    expect($process->isSuccessful())->toBeFalse();
    expect($process->getErrorOutput())->toContain('synchronized root group');
});

it('refuses when the privacy manifest is excluded from the target', function (): void {
    $process = runIosPatch('nativephp_ios_privacy_manifest.php', iosComplianceScaffold(excepted: true));

    expect($process->isSuccessful())->toBeFalse();
    expect($process->getErrorOutput())->toContain('membership exception');
});

it('declares non-exempt encryption at the top level of every Info.plist', function (): void {
    $root = iosComplianceScaffold();

    $process = runIosPatch('nativephp_ios_export_compliance.php', $root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    foreach (['/nativephp/ios/NativePHP/Info.plist', '/nativephp/ios/NativePHP-simulator-Info.plist'] as $relative) {
        $plist = @simplexml_load_file($root.$relative);

        expect($plist)->not->toBeFalse();

        $value = $plist->xpath('/plist/dict/key[text()="ITSAppUsesNonExemptEncryption"]/following-sibling::*[1]');

        expect($value)->toHaveCount(1);
        expect($value[0]->getName())->toBe('true');
    }
});

it('re-runs both iOS patches without changing a byte', function (): void {
    $root = iosComplianceScaffold();

    foreach (['nativephp_ios_privacy_manifest.php', 'nativephp_ios_export_compliance.php'] as $script) {
        runIosPatch($script, $root);
    }

    $before = [
        (string) file_get_contents($root.'/nativephp/ios/NativePHP/PrivacyInfo.xcprivacy'),
        (string) file_get_contents($root.'/nativephp/ios/NativePHP/Info.plist'),
    ];

    foreach (['nativephp_ios_privacy_manifest.php', 'nativephp_ios_export_compliance.php'] as $script) {
        $repeat = runIosPatch($script, $root);

        expect($repeat->isSuccessful())->toBeTrue($repeat->getErrorOutput());
    }

    expect([
        (string) file_get_contents($root.'/nativephp/ios/NativePHP/PrivacyInfo.xcprivacy'),
        (string) file_get_contents($root.'/nativephp/ios/NativePHP/Info.plist'),
    ])->toBe($before);
});
