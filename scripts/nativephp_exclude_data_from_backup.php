<?php

declare(strict_types=1);

/*
 * Keep the on-device database out of Google's cloud backup.
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
 *
 * Spec: E5-R23
 */

$root = __DIR__.'/../mobile-app/nativephp/android/app/src/main';
$manifest = $root.'/AndroidManifest.xml';

if (! is_file($manifest)) {
    fwrite(STDOUT, "nativephp_exclude_data_from_backup: no Android scaffold yet — skipping.\n");
    exit(0);
}

$source = (string) file_get_contents($manifest);

$androidDone = str_contains($source, 'android:allowBackup="false"');

$anchor = 'android:allowBackup="true"';

if (! $androidDone && ! str_contains($source, $anchor)) {
    fwrite(STDERR, "nativephp_exclude_data_from_backup: allowBackup anchor not found in {$manifest}.\n");
    fwrite(STDERR, "The generated manifest changed shape; confirm the data directory is still excluded before shipping.\n");
    exit(1);
}

// The flag alone, with no comment beside it: an XML comment between an
// element's attributes is not well-formed, and the manifest merger rejects the
// whole file. The note goes above the element, where it is legal.
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
// BETWEEN the application element's attributes, which is not well-formed. The
// attribute was present and readable, every check for it passed, and Gradle
// died in the manifest merger with a SAX error nobody could see.
$reparsed = @simplexml_load_file($manifest);

if ($reparsed === false) {
    fwrite(STDERR, "nativephp_exclude_data_from_backup: {$manifest} is no longer well-formed XML after patching.\n");

    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, '  line '.$error->line.': '.trim($error->message)."\n");
    }

    exit(1);
}

// The rule files are the device-transfer half. Written whole rather than
// patched: the generated ones are Android Studio's samples with every rule
// commented out, so there is no anchor in them worth preserving.
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

fwrite(STDOUT, $androidDone
    ? "nativephp_exclude_data_from_backup: Android already patched.\n"
    : "nativephp_exclude_data_from_backup: Android data directory is out of cloud backup and device transfer.\n");

// ─── iOS ────────────────────────────────────────────────────────────────────
// Application Support is backed up to iCloud by default, and that is where the
// database, the keyring and the staged secrets live. iOS has no manifest flag;
// the exclusion is a per-URL resource value, set as each directory is created.
$app = __DIR__.'/../mobile-app/nativephp/ios/NativePHP/NativePHPApp.swift';

if (! is_file($app)) {
    fwrite(STDOUT, "nativephp_exclude_data_from_backup: no iOS scaffold yet — skipping that half.\n");
    exit(0);
}

$swift = (string) file_get_contents($app);

if (str_contains($swift, 'nativephp_exclude_data_from_backup.php')) {
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
            try excluded.setResourceValues(values)
        } catch {
SWIFT;

if (file_put_contents($app, str_replace($swiftAnchor, $swiftPatched, $swift)) === false) {
    fwrite(STDERR, "nativephp_exclude_data_from_backup: could not write {$app}.\n");
    exit(1);
}

fwrite(STDOUT, "nativephp_exclude_data_from_backup: iOS Application Support is out of iCloud backup.\n");
exit(0);
