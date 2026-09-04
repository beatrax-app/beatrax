<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Keep the on-device database out of Google's cloud backup and Apple's iCloud.
 *
 * `native:install` generates a manifest carrying android:allowBackup="true"
 * and points it at Android Studio's sample rule files, whose every rule is
 * commented out. The effect is Auto Backup's default: the whole internal data
 * directory is eligible, and that directory holds persisted_data/ — the SQLite
 * database with every transaction, the sync keyring, and the staged secrets.
 *
 * For a product whose stated position is local-first and end-to-end encrypted,
 * a copy of all of it in a Google account is the one exposure no amount of
 * transport encryption addresses. It is also silent: nothing in the app tells
 * the user it happens, and nothing in the build fails because of it.
 *
 * Both halves are written. allowBackup="false" is what actually stops cloud
 * backup; the rule files are filled in as well because Android 12+ reads
 * <device-transfer> from dataExtractionRules independently of allowBackup, so
 * a device-to-device transfer would otherwise still carry the database.
 *
 * Same discipline as its siblings: idempotent, marker-guarded, and a missing
 * anchor is a hard failure rather than a silent skip.
 */

/**
 * The Android half, in a function so that its absence cannot end the run.
 *
 * This was straight-line code ending in exit(0) when there was no manifest,
 * and a tree with only the iOS scaffold generated therefore got no iCloud
 * exclusion at all — reported as a success whose one line of output talked
 * about Android. Each platform now answers only for itself.
 */
function beatraxExcludeAndroidFromBackup(string $root): void
{
    $manifest = $root.'/AndroidManifest.xml';
    $source = (string) file_get_contents($manifest);

    $androidDone = str_contains($source, 'android:allowBackup="false"');

    $anchor = 'android:allowBackup="true"';

    if (! $androidDone && ! str_contains($source, $anchor)) {
        fwrite(STDERR, "nativephp_exclude_data_from_backup: allowBackup anchor not found in {$manifest}.\n");
        fwrite(STDERR, "The generated manifest changed shape; confirm the data directory is still excluded before shipping.\n");
        exit(1);
    }

    // The flag alone, with no comment beside it: an XML comment between an
    // element's attributes is not well-formed, and the manifest merger rejects
    // the whole file. The note goes above the element, where it is legal.
    $replacement = 'android:allowBackup="false"';

    if (! $androidDone) {
        $rewritten = str_replace($anchor, $replacement, $source);
        $rewritten = str_replace(
            '    <application',
            "    <!-- allowBackup false, and the rules below, by\n"
            ."         scripts/nativephp_exclude_data_from_backup.php — see that file. -->\n"
            .'    <application',
            $rewritten,
        );

        if (file_put_contents($manifest, $rewritten) === false) {
            fwrite(STDERR, "nativephp_exclude_data_from_backup: could not write {$manifest}.\n");
            exit(1);
        }
    }

    // Proof, not assumption: an earlier version of this script put the note
    // BETWEEN the application element's attributes, which is not well-formed.
    // The attribute was present and readable, every check for it passed, and
    // Gradle died in the manifest merger with a SAX error nobody could see.
    $reparsed = @simplexml_load_file($manifest);

    if ($reparsed === false) {
        fwrite(STDERR, "nativephp_exclude_data_from_backup: {$manifest} is no longer well-formed XML after patching.\n");

        foreach (libxml_get_errors() as $error) {
            fwrite(STDERR, '  line '.$error->line.': '.trim($error->message)."\n");
        }

        exit(1);
    }

    beatraxWriteAndroidBackupRules($root);

    fwrite(STDOUT, $androidDone
        ? "nativephp_exclude_data_from_backup: Android already patched.\n"
        : "nativephp_exclude_data_from_backup: Android data directory is out of cloud backup and device transfer.\n");
}

/**
 * The device-transfer half. Written whole rather than patched: the generated
 * files are Android Studio's samples with every rule commented out, so there
 * is no anchor in them worth preserving.
 */
function beatraxWriteAndroidBackupRules(string $root): void
{
    $rules = [
        $root.'/res/xml/data_extraction_rules.xml' => <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<!-- Written by scripts/nativephp_exclude_data_from_backup.php — see that file. -->
<data-extraction-rules>
    <cloud-backup>
        <exclude domain="file" />
        <exclude domain="database" />
        <exclude domain="sharedpref" />
    </cloud-backup>
    <device-transfer>
        <exclude domain="file" />
        <exclude domain="database" />
        <exclude domain="sharedpref" />
    </device-transfer>
</data-extraction-rules>
XML,
        $root.'/res/xml/backup_rules.xml' => <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<!-- Written by scripts/nativephp_exclude_data_from_backup.php — see that file.
     Read on API 30 and below, where dataExtractionRules is ignored. -->
<full-backup-content>
    <exclude domain="file" />
    <exclude domain="database" />
    <exclude domain="sharedpref" />
</full-backup-content>
XML,
    ];

    foreach ($rules as $path => $contents) {
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0o755, true) && ! is_dir(dirname($path))) {
            fwrite(STDERR, 'nativephp_exclude_data_from_backup: could not create '.dirname($path).".\n");
            exit(1);
        }

        if (file_put_contents($path, $contents."\n") === false) {
            fwrite(STDERR, "nativephp_exclude_data_from_backup: could not write {$path}.\n");
            exit(1);
        }
    }
}

$androidRoot = beatraxScaffoldPath('android/app/src/main') ?? '';

if ($androidRoot !== '' && is_file($androidRoot.'/AndroidManifest.xml')) {
    beatraxExcludeAndroidFromBackup($androidRoot);
} else {
    fwrite(STDOUT, "nativephp_exclude_data_from_backup: no Android scaffold yet — skipping that half.\n");
}

// ─── iOS ────────────────────────────────────────────────────────────────────
// Application Support is backed up to iCloud by default, and that is where the
// database, the keyring and the staged secrets live. iOS has no manifest flag;
// the exclusion is a per-URL resource value, set as each directory is created.
$app = beatraxScaffoldPath('ios/NativePHP/NativePHPApp.swift') ?? '';

if (! is_file($app)) {
    fwrite(STDOUT, "nativephp_exclude_data_from_backup: no iOS scaffold yet — skipping that half.\n");
    exit(0);
}

$swift = (string) file_get_contents($app);

// Two markers, because a scaffold patched before the read-back existed carries
// the first and not the second, and that is the one case that must be upgraded
// rather than skipped: it is the silent form, which reports nothing when the
// exclusion does not take.
$marker = 'nativephp_exclude_data_from_backup.php';
$readsBack = str_contains($swift, 'isExcludedFromBackupKey');

if (str_contains($swift, $marker) && $readsBack) {
    fwrite(STDOUT, "nativephp_exclude_data_from_backup: iOS already patched.\n");

    exit(0);
}

$swiftAnchor = <<<'SWIFT'
        do {
            try FileManager.default.createDirectory(
                at: destination,
                withIntermediateDirectories: true,
                attributes: nil
            )
        } catch {
SWIFT;

// A scaffold already carrying the silent form is patched against ITS text, not
// the pristine one: the original anchor is long gone from it, and refusing
// there would leave the shell that most needs the read-back without it.
$swiftLegacy = <<<'SWIFT'
            var excluded = destination
            var values = URLResourceValues()
            values.isExcludedFromBackup = true
            try excluded.setResourceValues(values)
SWIFT;

$swiftLegacyUpgraded = <<<'SWIFT'
            var excluded = destination
            var values = URLResourceValues()
            values.isExcludedFromBackup = true

            // Its own do/catch, and the flag is read back rather than trusted:
            // a throw here would otherwise land in the catch below beside a
            // failed mkdir, leaving the whole ledger in iCloud with the app
            // reporting nothing. NSLog is the only channel this early on device.
            do {
                try excluded.setResourceValues(values)

                let observed = try excluded.resourceValues(forKeys: [.isExcludedFromBackupKey])

                if observed.isExcludedFromBackup != true {
                    NSLog("%@", "[Beatrax] [BACKUP] \(destination.path) is still eligible for iCloud backup after the exclusion was set")
                }
            } catch {
                NSLog("%@", "[Beatrax] [BACKUP] could not exclude \(destination.path) from iCloud backup: \(error.localizedDescription)")
            }
SWIFT;

if (str_contains($swift, $marker) && str_contains($swift, $swiftLegacy)) {
    if (file_put_contents($app, str_replace($swiftLegacy, $swiftLegacyUpgraded, $swift)) === false) {
        fwrite(STDERR, "nativephp_exclude_data_from_backup: could not write {$app}.\n");
        exit(1);
    }

    fwrite(STDOUT, "nativephp_exclude_data_from_backup: iOS exclusion upgraded to report a failure.\n");
    exit(0);
}

if (! str_contains($swift, $swiftAnchor)) {
    fwrite(STDERR, "nativephp_exclude_data_from_backup: getAppSupportDir anchor not found in {$app}.\n");
    fwrite(STDERR, "The generated shell changed shape; confirm the data directory is still excluded from iCloud before shipping.\n");
    exit(1);
}

$swiftPatched = <<<'SWIFT'
        do {
            try FileManager.default.createDirectory(
                at: destination,
                withIntermediateDirectories: true,
                attributes: nil
            )

            // scripts/nativephp_exclude_data_from_backup.php — Application
            // Support is in iCloud backup by default, and this is where the
            // database and the keyring live. Set as the directory is created,
            // because the flag applies from the moment the file exists.
            var excluded = destination
            var values = URLResourceValues()
            values.isExcludedFromBackup = true

            // Its own do/catch, and the flag is read back rather than trusted:
            // a throw here would otherwise land in the catch below beside a
            // failed mkdir, leaving the whole ledger in iCloud with the app
            // reporting nothing. NSLog is the only channel this early on device.
            do {
                try excluded.setResourceValues(values)

                let observed = try excluded.resourceValues(forKeys: [.isExcludedFromBackupKey])

                if observed.isExcludedFromBackup != true {
                    NSLog("%@", "[Beatrax] [BACKUP] \(destination.path) is still eligible for iCloud backup after the exclusion was set")
                }
            } catch {
                NSLog("%@", "[Beatrax] [BACKUP] could not exclude \(destination.path) from iCloud backup: \(error.localizedDescription)")
            }
        } catch {
SWIFT;

if (file_put_contents($app, str_replace($swiftAnchor, $swiftPatched, $swift)) === false) {
    fwrite(STDERR, "nativephp_exclude_data_from_backup: could not write {$app}.\n");
    exit(1);
}

fwrite(STDOUT, "nativephp_exclude_data_from_backup: iOS Application Support is out of iCloud backup.\n");
exit(0);
