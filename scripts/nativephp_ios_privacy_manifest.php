<?php

declare(strict_types=1);

/*
 * Write the iOS app privacy manifest.
 *
 * Apple's required-reason API check (ITMS-91053) is a static scan of the
 * shipped binary's symbols, not of anyone's Swift, and it has been a hard
 * block at App Store Connect since 1 May 2024. Running the same scan against
 * the built product finds three of the four categories:
 *
 *   nm -u NativePHP.app/NativePHP.debug.dylib
 *     _stat _fstat _lstat _NSFileModificationDate   -> File Timestamp
 *     _statfs _fstatfs                              -> Disk Space
 *     _OBJC_CLASS_$_NSUserDefaults / standardUserDefaults -> User Defaults
 *
 * None of that is sloppiness — a PHP interpreter cannot exist without stat(),
 * and SQLite asks the filesystem whether a write will fit. It simply has to be
 * declared, and Apple's rule covers first-party code and not only SDKs.
 *
 * System Boot Time is deliberately NOT declared: NSProcessInfo is linked, but
 * neither systemUptime (as a selector) nor mach_absolute_time appears, so the
 * category does not fire and claiming a reason for an API the app never calls
 * is its own kind of false statement.
 *
 * NSPrivacyCollectedDataTypes and NSPrivacyTrackingDomains are OMITTED rather
 * than shipped empty. There is no server and no tracking, so an empty array
 * says nothing an absent key does not — and an empty collected-types array is
 * a known ITMS-91056 trigger.
 *
 * The file lands in NativePHP/, which the Xcode project declares as a
 * PBXFileSystemSynchronizedRootGroup: every file in that directory is a target
 * member automatically, and resources are copied to the .app root. That is
 * asserted below rather than assumed, because if NativePHP ever regenerates
 * the project with an ordinary group the manifest would sit on disk, be in no
 * build phase, and the rejection would look identical to having no manifest.
 *
 * Same discipline as its siblings: idempotent, marker-guarded, and a missing
 * anchor is a hard failure rather than a silent skip.
 */

const MANIFEST_RELATIVE_PATH = 'NativePHP/PrivacyInfo.xcprivacy';

/**
 * The generated iOS project, which is not at one path: the desktop repo root
 * reaches it under mobile-app/, while a materialized Bifrost tree IS the
 * mobile root and holds it directly. Probed rather than assumed.
 */
function iosProjectDirectory(): ?string
{
    $override = getenv('BEATRAX_NATIVE_ROOT');

    $roots = $override === false || $override === ''
        ? [dirname(__DIR__).'/mobile-app', dirname(__DIR__)]
        : [$override];

    foreach ($roots as $root) {
        $candidate = $root.'/nativephp/ios';

        if (is_file($candidate.'/NativePHP.xcodeproj/project.pbxproj')) {
            return $candidate;
        }
    }

    return null;
}

$ios = iosProjectDirectory();

if ($ios === null) {
    fwrite(STDOUT, "nativephp_ios_privacy_manifest: no iOS scaffold yet — skipping.\n");
    exit(0);
}

$pbxproj = (string) file_get_contents($ios.'/NativePHP.xcodeproj/project.pbxproj');

// The whole reason this can be a plain file drop. Without the synchronized
// group the manifest needs a PBXBuildFile and a Resources phase entry, and
// writing one silently would be worse than stopping here.
if (! str_contains($pbxproj, 'isa = PBXFileSystemSynchronizedRootGroup;')
    || preg_match('#PBXFileSystemSynchronizedRootGroup;\s*exceptions = \([^)]*\);\s*path = NativePHP;#s', $pbxproj) !== 1) {
    fwrite(STDERR, "nativephp_ios_privacy_manifest: the NativePHP folder is no longer a synchronized root group.\n");
    fwrite(STDERR, "PrivacyInfo.xcprivacy would not be copied into the app bundle; add it to the Resources build phase by hand.\n");
    exit(1);
}

// A membership exception is how a file inside a synchronized folder is kept
// OUT of a target — Info.plist is listed for exactly that reason. The privacy
// manifest appearing there would mean it ships in no bundle at all.
if (preg_match('#membershipExceptions = \([^)]*PrivacyInfo\.xcprivacy[^)]*\);#s', $pbxproj) === 1) {
    fwrite(STDERR, "nativephp_ios_privacy_manifest: PrivacyInfo.xcprivacy is listed as a membership exception in the Xcode project.\n");
    exit(1);
}

// C617.1 — file metadata for files inside the app's own container. That is
// every stat() the interpreter makes: the bundle it runs from and the
// Application Support tree it writes to.
//
// E174.1 — checking whether there is room to write. SQLite asks before it
// grows the ledger, and answers SQLITE_FULL when there is not.
//
// CA92.1 — user defaults readable and writable only by this app. The shell
// keeps its own launch state there; nothing is read from, or exposed to,
// another app or the system.
$manifest = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<!-- Written by scripts/nativephp_ios_privacy_manifest.php — see that file. -->
<plist version="1.0">
<dict>
	<key>NSPrivacyTracking</key>
	<false/>
	<key>NSPrivacyAccessedAPITypes</key>
	<array>
		<dict>
			<key>NSPrivacyAccessedAPIType</key>
			<string>NSPrivacyAccessedAPICategoryFileTimestamp</string>
			<key>NSPrivacyAccessedAPITypeReasons</key>
			<array>
				<string>C617.1</string>
			</array>
		</dict>
		<dict>
			<key>NSPrivacyAccessedAPIType</key>
			<string>NSPrivacyAccessedAPICategoryDiskSpace</string>
			<key>NSPrivacyAccessedAPITypeReasons</key>
			<array>
				<string>E174.1</string>
			</array>
		</dict>
		<dict>
			<key>NSPrivacyAccessedAPIType</key>
			<string>NSPrivacyAccessedAPICategoryUserDefaults</string>
			<key>NSPrivacyAccessedAPITypeReasons</key>
			<array>
				<string>CA92.1</string>
			</array>
		</dict>
	</array>
</dict>
</plist>

XML;

$target = $ios.'/'.MANIFEST_RELATIVE_PATH;
$existing = is_file($target) ? (string) file_get_contents($target) : null;

if ($existing === $manifest) {
    fwrite(STDOUT, "nativephp_ios_privacy_manifest: already applied.\n");
    exit(0);
}

if (file_put_contents($target, $manifest) === false) {
    fwrite(STDERR, "nativephp_ios_privacy_manifest: could not write {$target}.\n");
    exit(1);
}

// Proof, not assumption: a manifest Apple cannot parse is ITMS-91056, which
// reads as "invalid" rather than "missing" and costs the same submission.
$reparsed = @simplexml_load_file($target);

if ($reparsed === false) {
    fwrite(STDERR, "nativephp_ios_privacy_manifest: {$target} is not well-formed XML.\n");

    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, '  line '.$error->line.': '.trim($error->message)."\n");
    }

    exit(1);
}

fwrite(STDOUT, "nativephp_ios_privacy_manifest: declared 3 required-reason API categories.\n");
exit(0);
