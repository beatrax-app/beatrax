<?php

declare(strict_types=1);

// Without this libxml_get_errors() is always empty, and the per-line
// detail below never printed — the failure was loud but unreadable.
libxml_use_internal_errors(true);

/*
 * Strip the Android permissions the app declares but never exercises.
 *
 * `native:install` writes a manifest carrying the union of every permission
 * any NativePHP plugin might want, and two of them are a Play problem rather
 * than a tidiness one:
 *
 *   USE_EXACT_ALARM       auto-granted, and Play restricts it to apps whose
 *                         core user-facing purpose is precisely-timed action —
 *                         alarm clocks and calendars. A budgeting app is not
 *                         that, so the declaration form is refused and the
 *                         listing is removable.
 *   SCHEDULE_EXACT_ALARM  the runtime-consent sibling. Also declaration-gated,
 *                         also unused: the local-notifications plugin only
 *                         reaches AlarmManager from schedule()/scheduleRecurring(),
 *                         and the one call this app makes is showRaw() — an
 *                         immediate post with no alarm anywhere in it.
 *
 * RECEIVE_BOOT_COMPLETED goes with them: its only consumer is the same
 * plugin's BootReceiver, which re-registers alarms that were never registered.
 * FLASHLIGHT goes because nothing anywhere drives a torch — the scanner's only
 * "flash" is a green success animation drawn in Compose.
 *
 * VIBRATE deliberately STAYS. It reads as unused from the PHP side, but
 * ScannerActivity buzzes on a successful QR scan, and that scanner is how a
 * phone pairs with a household peer. Removing it turns pairing into a
 * SecurityException.
 *
 * Two more arrive from libraries and never appear in the source file, so
 * deleting lines cannot reach them:
 *
 *   FOREGROUND_SERVICE    from androidx.work. Nothing calls setForeground(),
 *                         setForegroundAsync() or setExpedited(), so no
 *                         foreground service is ever started — and the
 *                         WorkManager service it would start is declared with
 *                         no android:foregroundServiceType, which on
 *                         targetSdk 34+ throws at the moment it is used.
 *   USE_FINGERPRINT       from androidx.biometric, for its pre-API-28 path.
 *                         minSdk here is 33, so that path is unreachable.
 *
 * WAKE_LOCK also arrives from androidx.work and is deliberately KEPT: the
 * background-task scheduler is real, WorkManager takes wakelocks internally,
 * and Play treats it as an ordinary permission with no declaration attached.
 *
 * Same discipline as its siblings: idempotent, marker-guarded, and a missing
 * anchor is a hard failure rather than a silent skip.
 *
 * What the read-back at the bottom can and cannot prove
 * ----------------------------------------------------
 * This script runs on the app manifest, and it runs BEFORE Gradle merges the
 * plugin manifests into it. So there are two different questions about a
 * permission that has to survive, and only one of them is answerable here.
 *
 * KEEP_IN_SOURCE are written by native:install into the app manifest itself.
 * Whether they are still declared is a fact about this file, this script is
 * the only thing that edits it, and a missing one is this script's own bug —
 * so it is checked by presence.
 *
 * KEEP_THROUGH_MERGE (camera for QR pairing, biometric unlock, notifications)
 * arrive from a plugin's own manifest at merge time. Right after native:install
 * they are simply not in this file, and they are in it later only because the
 * plugin compiler happened to have run first. Asking "is it declared here" of
 * those three answered no on every fresh scaffold and exited 1 on every build
 * off one — a build step that always fails teaches everyone to ignore its exit
 * code, which is worse than not checking at all.
 *
 * What IS answerable about them is the only way this script could keep them
 * out of the merged manifest: a tools:node="remove" written here deletes the
 * plugin's contribution at merge, and nothing downstream would report it. So
 * they are checked negatively — never pinned out — and the merged result is
 * verified where it can actually be read, on the device.
 */

// The source-declared ones, deleted from the file outright. They are also
// pinned out below, because native:install regenerates this manifest and would
// write every one of them back.
const REMOVE_FROM_SOURCE = [
    'android.permission.FLASHLIGHT',
    'android.permission.SCHEDULE_EXACT_ALARM',
    'android.permission.USE_EXACT_ALARM',
    'android.permission.RECEIVE_BOOT_COMPLETED',
];

// Injected into the merged manifest by a dependency's own manifest. There is
// no line here to delete; tools:node="remove" is the only lever.
const REMOVE_FROM_MERGE = [
    'android.permission.FOREGROUND_SERVICE',
    'android.permission.USE_FINGERPRINT',
];

// Declared in the app manifest native:install writes, so their presence after
// this script's edits is a fact it can read back.
const KEEP_IN_SOURCE = [
    'android.permission.INTERNET',
    'android.permission.ACCESS_NETWORK_STATE',
    'android.permission.VIBRATE',
];

// Contributed by a plugin's own manifest at Gradle's merge, which is after this
// script runs — see the header. Checked for what this script could do to them,
// not for a presence it has no way to know.
const KEEP_THROUGH_MERGE = [
    'android.permission.CAMERA',
    'android.permission.USE_BIOMETRIC',
    'android.permission.POST_NOTIFICATIONS',
];

const MARKER = 'scripts/nativephp_strip_unused_permissions.php';

const TOOLS_NAMESPACE = 'http://schemas.android.com/tools';

/**
 * The generated Android project, which is not at one path: the desktop repo
 * root reaches it under mobile-app/, while a materialized Bifrost tree IS the
 * mobile root and holds it directly. Probed rather than assumed.
 */
function androidMainDirectory(): ?string
{
    $override = getenv('BEATRAX_NATIVE_ROOT');

    $roots = $override === false || $override === ''
        ? [dirname(__DIR__).'/mobile-app', dirname(__DIR__)]
        : [$override];

    foreach ($roots as $root) {
        $candidate = $root.'/nativephp/android/app/src/main';

        if (is_file($candidate.'/AndroidManifest.xml')) {
            return $candidate;
        }
    }

    return null;
}

$main = androidMainDirectory();

if ($main === null) {
    fwrite(STDOUT, "nativephp_strip_unused_permissions: no Android scaffold yet — skipping.\n");
    exit(0);
}

$manifest = $main.'/AndroidManifest.xml';
$source = (string) file_get_contents($manifest);

if (! str_contains($source, TOOLS_NAMESPACE)) {
    fwrite(STDERR, "nativephp_strip_unused_permissions: the tools namespace is not declared in {$manifest}.\n");
    fwrite(STDERR, "tools:node=\"remove\" is inert without it; confirm the manifest shape before shipping.\n");
    exit(1);
}

// The anchor the removal block is written after. Present in every generated
// manifest, and the one permission that can never be dropped.
$anchor = '<uses-permission android:name="android.permission.INTERNET" />';

if (! str_contains($source, $anchor)) {
    fwrite(STDERR, "nativephp_strip_unused_permissions: INTERNET anchor not found in {$manifest}.\n");
    fwrite(STDERR, "The generated manifest changed shape; confirm the permission set by hand before shipping.\n");
    exit(1);
}

// A block an earlier run wrote, cleared before the fresh one is composed.
// Its lines carry tools:node and so survive the removal pass below, which
// would otherwise leave a second copy behind on every build.
// The leading \r?\n is the separator line the insertion below writes. Without
// it in the pattern the file grows one blank line per build, which is not
// wrong but means no two runs ever produce the same bytes.
$rewritten = (string) preg_replace(
    '#\r?\n[ \t]*<!-- Unused permissions removed by '.preg_quote(MARKER, '#').'.*?-->[ \t]*\r?\n#s',
    '',
    $source,
);

$rewritten = (string) preg_replace(
    '#[ \t]*<uses-permission\s+android:name="[^"]+"\s+tools:node="remove"\s*/>[ \t]*\r?\n?#',
    '',
    $rewritten,
);

// The commented decoys go with them, or a second run keeps the old ones and
// adds a fresh set, and no two builds produce the same bytes.
$rewritten = (string) preg_replace(
    '#[ \t]*<!-- <uses-permission\s+android:name="[^"]+"\s*/> kept here only[^>]*-->[ \t]*\r?\n?#',
    '',
    $rewritten,
);

// Element-wise rather than line-wise on purpose: the generated manifest puts
// FLASHLIGHT and USE_BIOMETRIC on the SAME line, so dropping the line that
// carries one silently drops biometric unlock with it.
foreach (array_merge(REMOVE_FROM_SOURCE, REMOVE_FROM_MERGE) as $permission) {
    $rewritten = (string) preg_replace(
        '#[ \t]*<uses-permission\s+android:name="'.preg_quote($permission, '#').'"\s*/>[ \t]*\r?\n?#',
        '',
        $rewritten,
    );
}

$block = '    <!-- Unused permissions removed by '.MARKER." — see that file.\n"
    ."         Kept as tools:node=\"remove\" rather than merely deleted: native:install\n"
    ."         rewrites this manifest, and two of them are contributed by a library\n"
    ."         manifest where there is no line to delete at all. -->\n";

// Each removal is paired with the plain declaration, commented out. The plugin
// compiler decides what to append with
// `str_contains($manifest, '<uses-permission android:name="X" />')` — an exact
// substring — and the tools:node="remove" form does not contain it, so it
// re-added all three on the next build and the device shipped them. The
// commented copy satisfies that check; the merger ignores comments and honours
// only the removal. Measured on an SM-S928B: SCHEDULE_EXACT_ALARM,
// USE_EXACT_ALARM and RECEIVE_BOOT_COMPLETED were granted before this.
foreach (array_merge(REMOVE_FROM_SOURCE, REMOVE_FROM_MERGE) as $permission) {
    $plain = '<uses-permission android:name="'.$permission.'" />';

    $block .= '    <!-- '.$plain." kept here only so the plugin compiler sees it -->\n";
    $block .= '    <uses-permission android:name="'.$permission."\" tools:node=\"remove\" />\n";
}

$rewritten = str_replace($anchor, $anchor."\n\n".rtrim($block, "\n"), $rewritten);

if ($rewritten !== $source && file_put_contents($manifest, $rewritten) === false) {
    fwrite(STDERR, "nativephp_strip_unused_permissions: could not write {$manifest}.\n");
    exit(1);
}

// Proof, not assumption: a malformed manifest fails much later, inside
// Gradle's merger, with a SAX error nobody reads back to this script.
$reparsed = @simplexml_load_file($manifest);

if ($reparsed === false) {
    fwrite(STDERR, "nativephp_strip_unused_permissions: {$manifest} is no longer well-formed XML after patching.\n");

    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, '  line '.$error->line.': '.trim($error->message)."\n");
    }

    exit(1);
}

// Every declaration that survives has to carry the removal marker, and the
// ones that must stay have to still be there. Read back off disk, because the
// question is what the merger will see and not what this script intended.
$verified = (string) file_get_contents($manifest);

// Comments first: the merger ignores them, and one of them deliberately holds
// a plain declaration to satisfy the plugin compiler's substring check. Asking
// "what will the merger see" of the raw text answered yes to that comment.
$verified = (string) preg_replace('/<!--.*?-->/s', '', $verified);

foreach (array_merge(REMOVE_FROM_SOURCE, REMOVE_FROM_MERGE) as $permission) {
    $pattern = '#<uses-permission\s+android:name="'.preg_quote($permission, '#').'"(?![^>]*tools:node="remove")#';

    if (preg_match($pattern, $verified) === 1) {
        fwrite(STDERR, "nativephp_strip_unused_permissions: {$permission} still declared without tools:node=\"remove\".\n");
        exit(1);
    }
}

foreach (KEEP_IN_SOURCE as $keep) {
    if (! str_contains($verified, 'android:name="'.$keep.'" />')) {
        fwrite(STDERR, "nativephp_strip_unused_permissions: {$keep} is used and no longer declared in {$manifest}.\n");
        exit(1);
    }
}

// Attribute order is not fixed by anything, and the removal pass above only
// normalises the one order it writes — so the element is matched as a whole
// rather than name-first, or a hand-edited manifest slips past.
foreach (KEEP_THROUGH_MERGE as $keep) {
    $pattern = '#<uses-permission\s(?=[^>]*android:name="'.preg_quote($keep, '#').'")[^>]*tools:node="remove"[^>]*/>#';

    if (preg_match($pattern, $verified) === 1) {
        fwrite(STDERR, "nativephp_strip_unused_permissions: {$keep} is pinned out in {$manifest}.\n");
        fwrite(STDERR, "It is contributed by a plugin manifest, and tools:node=\"remove\" deletes that contribution at merge.\n");
        exit(1);
    }
}

$pinned = count(REMOVE_FROM_SOURCE) + count(REMOVE_FROM_MERGE);

fwrite(STDOUT, $rewritten === $source
    ? "nativephp_strip_unused_permissions: already applied.\n"
    : "nativephp_strip_unused_permissions: pinned out {$pinned} unused permissions.\n");

exit(0);
