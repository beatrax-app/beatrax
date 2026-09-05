<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PersistedStore;

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Keep the on-device user data out of the vendor's cloud backup.
 *
 * What sits on a phone is `persisted_data/` — the SQLite ledger with every
 * transaction, the GDK keyring, the sync identity, and the staged secrets. For
 * a product whose stated position is local-first and end-to-end encrypted, a
 * copy of all of it in a Google or Apple account is the one exposure no amount
 * of transport encryption addresses. It is also silent: nothing in the app
 * tells the user it happens, and nothing in the build fails because of it.
 *
 * ANDROID. `native:install` generates a manifest carrying
 * android:allowBackup="true" and points it at Android Studio's sample rule
 * files, whose every rule is commented out. The effect is Auto Backup's
 * default: the whole internal data directory is eligible. Both halves are
 * written here. allowBackup="false" is what actually stops cloud backup; the
 * rule files are filled in as well because Android 12+ reads <device-transfer>
 * from dataExtractionRules independently of allowBackup, so a device-to-device
 * transfer would otherwise still carry the database.
 *
 * IOS has no manifest flag; the exclusion is a per-URL resource value, and it
 * has to be set on the tree the app actually writes. That tree is NOT
 * Application Support. `base_path()` on iOS is <container>/Documents/app, so
 * UserDataPathService puts the store at <container>/Documents/persisted_data —
 * and Documents is in iCloud backup by default. The store is created and
 * flagged by the shell BEFORE the PHP runtime starts, because a flag is set on
 * a node that exists: had PHP's own mkdir got there first, the flag would have
 * been applied to a directory nobody wrote to.
 *
 * The layout is read from PersistedStore rather than spelled here. The reason
 * this shipped is that the flag and the writer named two different trees, and
 * nothing held them together.
 *
 * Same discipline as its siblings: idempotent, marker-guarded, and a missing
 * anchor is a hard failure rather than a silent skip. Both platform halves are
 * guarded separately — they were not at first, and the Android marker
 * short-circuited the script before the iOS half ever ran.
 */

$layout = beatraxSourcePath('Modules/Core/Public/Support/PersistedStore.php');

if ($layout === null) {
    fwrite(STDERR, 'nativephp_exclude_data_from_backup: PersistedStore.php is not reachable from '.__DIR__.".\n");
    fwrite(STDERR, "The iOS exclusion is derived from it, and a copy of the layout written here by hand is the drift this guards against.\n");
    exit(1);
}

require_once $layout;

$root = beatraxScaffoldPath('android/app/src/main') ?? '';
$manifest = $root.'/AndroidManifest.xml';

if (! is_file($manifest)) {
    fwrite(STDOUT, "nativephp_exclude_data_from_backup: no Android scaffold yet — skipping that half.\n");
} else {
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
}

// ─── iOS ────────────────────────────────────────────────────────────────────
$app = beatraxScaffoldPath('ios/NativePHP/NativePHPApp.swift') ?? '';

if (! is_file($app)) {
    fwrite(STDOUT, "nativephp_exclude_data_from_backup: no iOS scaffold yet — skipping that half.\n");
    exit(0);
}

$swift = (string) file_get_contents($app);
$original = $swift;

// Each patch is guarded by a line of its own CODE. One filename marker used to
// cover both halves, so applying either made the script call the other done;
// and a guard reading the injected COMMENT re-fires the whole patch the day
// that comment is reworded. The markers below are labels, not guards.
$supportSentinel = 'var excluded = destination';
$storeSentinel = 'private func prepareDurableStore()';
$supportMarker = 'backup-exclusion: application support';
$storeMarker = 'backup-exclusion: persisted store';

$supportAnchor = <<<'SWIFT'
        do {
            try FileManager.default.createDirectory(
                at: destination,
                withIntermediateDirectories: true,
                attributes: nil
            )
        } catch {
SWIFT;

$supportPatched = <<<SWIFT
        do {
            try FileManager.default.createDirectory(
                at: destination,
                withIntermediateDirectories: true,
                attributes: nil
            )

            // {$supportMarker} — scripts/nativephp_exclude_data_from_backup.php.
            // Application Support carries php.ini, the compiled views and the
            // database the stock shell pre-creates and the app never opens. The
            // store PHP writes is under Documents; prepareDurableStore() has it.
            var excluded = destination
            var values = URLResourceValues()
            values.isExcludedFromBackup = true
            try excluded.setResourceValues(values)
        } catch {
SWIFT;

$storeAnchor = <<<'SWIFT'
    private func preparePhpEnvironment() -> String {
        let phpIniPath = createPhpIni()
SWIFT;

$directory = PersistedStore::DIRECTORY;
$relatives = implode(', ', array_map(
    static fn (string $relative): string => '"'.$relative.'"',
    PersistedStore::relativeDirectories(),
));

$storePatched = <<<SWIFT
    // {$storeMarker} — scripts/nativephp_exclude_data_from_backup.php.
    // The store is dirname(base_path())/{$directory}, i.e. under Documents,
    // which iCloud backs up unless a node says otherwise. Created and flagged
    // ahead of PHP: a flag set after PHP's mkdir is set on nothing.
    private func prepareDurableStore() {
        let fileManager = FileManager.default
        let store = URL(fileURLWithPath: AppUpdateManager.shared.getAppPath())
            .deletingLastPathComponent()
            .appendingPathComponent("{$directory}")

        try? fileManager.createDirectory(at: store, withIntermediateDirectories: true)

        for relative in [{$relatives}] {
            try? fileManager.createDirectory(
                at: store.appendingPathComponent(relative),
                withIntermediateDirectories: true
            )
        }

        Self.excludeFromBackup(store)

        // For the one case an excluded ancestor does NOT cover: a directory
        // deleted and made again loses the flag with the inode. iCloud backs up
        // once the device is locked and idle, so the way to the background is
        // when to re-assert it.
        NotificationCenter.default.addObserver(
            forName: UIApplication.didEnterBackgroundNotification,
            object: nil,
            queue: .main
        ) { _ in Self.excludeFromBackup(store) }
    }

    // Measured, not assumed: the key answers the EFFECTIVE value, so a file
    // created later inside an excluded directory reads back excluded carrying
    // no xattr of its own. The read-back is the point — the 2026-09-04 device
    // check confirmed "a database" was excluded, named no path, and the file it
    // read was a 4 KB empty stub in the tree the app never opens.
    static func excludeFromBackup(_ store: URL) {
        let fileManager = FileManager.default
        var pending = [store]
        var flagged = 0
        var missed: [String] = []

        while let url = pending.popLast() {
            var node = url
            var values = URLResourceValues()
            values.isExcludedFromBackup = true
            try? node.setResourceValues(values)

            let readBack = URL(fileURLWithPath: url.path)

            if (try? readBack.resourceValues(forKeys: [.isExcludedFromBackupKey]))?
                .isExcludedFromBackup == true {
                flagged += 1
            } else {
                missed.append(url.path)
            }

            pending.append(contentsOf: (try? fileManager.contentsOfDirectory(
                at: url,
                includingPropertiesForKeys: nil
            )) ?? [])
        }

        NSLog("[NativePHP] backup-excluded flagged=\\(flagged) unflagged=\\(missed.count) store=\\(store.path)")

        for path in missed {
            NSLog("[NativePHP] backup-NOT-excluded \\(path)")
        }
    }

    private func preparePhpEnvironment() -> String {
        prepareDurableStore()

        let phpIniPath = createPhpIni()
SWIFT;

$patches = [
    'getAppSupportDir' => [$supportSentinel, $supportAnchor, $supportPatched],
    'preparePhpEnvironment' => [$storeSentinel, $storeAnchor, $storePatched],
];

$applied = 0;

foreach ($patches as $where => [$sentinel, $anchor, $patched]) {
    if (str_contains($swift, $sentinel)) {
        continue;
    }

    $applied++;

    if (! str_contains($swift, $anchor)) {
        fwrite(STDERR, "nativephp_exclude_data_from_backup: {$where} anchor not found in {$app}.\n");
        fwrite(STDERR, "The generated shell changed shape; confirm the data directory is still excluded from iCloud before shipping.\n");
        exit(1);
    }

    $swift = str_replace($anchor, $patched, $swift);
}

// A scaffold patched before the premise was checked still carries the sentence
// that caused this: that Application Support is where the database lives. It is
// generated, gitignored code, so nothing else will ever correct it, and a false
// statement left in the tree is how the next reader repeats the mistake.
$staleNote = <<<'SWIFT'
            // scripts/nativephp_exclude_data_from_backup.php — Application
            // Support is in iCloud backup by default, and this is where the
            // database and the keyring live. Set as the directory is created,
            // because the flag applies from the moment the file exists.
SWIFT;

$freshNote = <<<SWIFT
            // {$supportMarker} — scripts/nativephp_exclude_data_from_backup.php.
            // Application Support carries php.ini, the compiled views and the
            // database the stock shell pre-creates and the app never opens. The
            // store PHP writes is under Documents; prepareDurableStore() has it.
SWIFT;

$carriedTheSilentForm = str_contains($original, 'values.isExcludedFromBackup = true')
    && ! str_contains($original, '.isExcludedFromBackupKey');

$swift = str_replace($staleNote, $freshNote, $swift);

if ($swift !== $original && file_put_contents($app, $swift) === false) {
    fwrite(STDERR, "nativephp_exclude_data_from_backup: could not write {$app}.\n");
    exit(1);
}

// Proof, not assumption, on the half that shipped wrong: the file on disk has
// to derive the store from base_path()'s PARENT and name it, rather than
// merely mention a flag somewhere. Every needle is code, so a scaffold patched
// by an older revision of this script satisfies it too.
$written = (string) file_get_contents($app);
$derivesFromBundleParent = '.deletingLastPathComponent()';

foreach ([$supportSentinel, $storeSentinel, $derivesFromBundleParent, '"'.$directory.'"'] as $needle) {
    if (str_contains($written, $needle)) {
        continue;
    }

    fwrite(STDERR, "nativephp_exclude_data_from_backup: {$app} does not carry '{$needle}' after patching.\n");
    exit(1);
}

// Symmetry with the Android half, and not cosmetic: without it a build log
// reads the same whether the iOS patch ran or was skipped, which is the
// distinction this script exists to make legible.
if ($applied === 0 && ! $carriedTheSilentForm) {
    fwrite(STDOUT, "nativephp_exclude_data_from_backup: iOS already patched.\n");
    exit(0);
}

// An upgrade is the case an operator most needs in the build log: it says an
// earlier build shipped the form that set the flag and never read it back, so
// what that build actually excluded was never established.
if ($carriedTheSilentForm) {
    fwrite(STDOUT, "nativephp_exclude_data_from_backup: upgraded a shell that set the flag without reading it back.\n");
}

fwrite(STDOUT, "nativephp_exclude_data_from_backup: iOS Documents/{$directory} is out of iCloud backup.\n");
exit(0);
