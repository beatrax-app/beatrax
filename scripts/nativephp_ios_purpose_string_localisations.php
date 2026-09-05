<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Write the permission purpose strings in every language the interface ships in.
 *
 * A purpose string is the only sentence iOS shows before it hands an app the
 * camera, the face scanner or the local network, and the reader has to decide
 * on it. Beatrax speaks twenty-six languages and, until this script, said all
 * three of those sentences in English to every one of them — including the Face
 * ID prompt, which is the one that releases the key the ledger is encrypted
 * with.
 *
 * The strings themselves live in Modules/Mobile/Resources/ios/purpose-strings.php
 * because they never pass through the translator: iOS reads them out of the
 * bundle before any PHP runs, and this script runs with no framework loaded.
 * mobile-app/config/nativephp.php reads the `en` entry from the same file, so
 * the base plist value and the twenty-five translations cannot drift apart.
 *
 * They land as <locale>.lproj/InfoPlist.strings inside NativePHP/, which the
 * Xcode project declares as a PBXFileSystemSynchronizedRootGroup — every file
 * under it is a target member automatically. That is asserted rather than
 * assumed, for the same reason the privacy manifest asserts it: a project
 * regenerated with an ordinary group would leave these on disk, in no build
 * phase, and every prompt would silently fall back to English again.
 *
 * The one thing this cannot prove is that the built .app carries the folders.
 * `.docs/features/mobile/a-purpose-string-in-every-language.md` names the check
 * to run on a real archive.
 *
 * Same discipline as its siblings: idempotent, marker-guarded, and a missing
 * anchor is a hard failure rather than a silent skip.
 */

const PURPOSE_STRING_SOURCE = '/Modules/Mobile/Resources/ios/purpose-strings.php';

const PURPOSE_STRING_BASE_LOCALE = 'en';

/** Escapes one value for the .strings format, which is C string literal syntax. */
function purposeStringLiteral(string $value): string
{
    return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"';
}

/**
 * @param  array<string, string>  $strings  Info.plist key => the sentence
 */
function purposeStringsFile(string $locale, array $strings): string
{
    ksort($strings);

    $lines = ['/* Written by scripts/'.basename(__FILE__).' — see that file. */', ''];

    foreach ($strings as $key => $value) {
        $lines[] = purposeStringLiteral($key).' = '.purposeStringLiteral($value).';';
    }

    return implode("\n", $lines)."\n";
}

/**
 * The repository root, which is not one place: this script sits in scripts/ under
 * the desktop root, and a materialized Bifrost tree carries the same scripts/
 * beside its own copy of Modules/.
 */
function purposeStringSource(): ?string
{
    $override = getenv('BEATRAX_NATIVE_ROOT');

    $roots = $override === false || $override === ''
        ? [dirname(__DIR__)]
        : [$override, dirname($override), dirname(__DIR__)];

    foreach ($roots as $root) {
        if (is_file($root.PURPOSE_STRING_SOURCE)) {
            return $root.PURPOSE_STRING_SOURCE;
        }
    }

    return null;
}

$ios = beatraxScaffoldPath('ios/NativePHP.xcodeproj/project.pbxproj');

if ($ios === null) {
    fwrite(STDOUT, "nativephp_ios_purpose_string_localisations: no iOS scaffold yet — skipping.\n");
    exit(0);
}

$source = purposeStringSource();

if ($source === null) {
    fwrite(STDERR, 'nativephp_ios_purpose_string_localisations: no'.PURPOSE_STRING_SOURCE." reachable from this tree.\n");
    fwrite(STDERR, "Without it every permission prompt falls back to English; copy Modules/ in beside scripts/.\n");
    exit(1);
}

/** @var array<string, array<string, string>> $localised */
$localised = require $source;

$pbxproj = (string) file_get_contents($ios);

// The whole reason a .lproj folder can be a plain file drop. Without the
// synchronized group each one needs its own build-phase entry, and writing
// those silently would be worse than stopping here.
if (! str_contains($pbxproj, 'isa = PBXFileSystemSynchronizedRootGroup;')
    || preg_match('#PBXFileSystemSynchronizedRootGroup;\s*(?:exceptions = \([^)]*\);\s*)?path = NativePHP;#s', $pbxproj) !== 1) {
    fwrite(STDERR, "nativephp_ios_purpose_string_localisations: the NativePHP folder is no longer a synchronized root group.\n");
    fwrite(STDERR, "The .lproj folders would not be copied into the app bundle; add them to the Resources build phase by hand.\n");
    exit(1);
}

$group = dirname($ios, 2).'/NativePHP';

if (! is_dir($group)) {
    fwrite(STDERR, "nativephp_ios_purpose_string_localisations: {$group} does not exist.\n");
    exit(1);
}

$written = 0;

foreach ($localised as $locale => $strings) {
    $directory = $group.'/'.$locale.'.lproj';

    if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
        fwrite(STDERR, "nativephp_ios_purpose_string_localisations: could not create {$directory}.\n");
        exit(1);
    }

    $target = $directory.'/InfoPlist.strings';
    $contents = purposeStringsFile($locale, $strings);

    if (is_file($target) && (string) file_get_contents($target) === $contents) {
        continue;
    }

    if (file_put_contents($target, $contents) === false) {
        fwrite(STDERR, "nativephp_ios_purpose_string_localisations: could not write {$target}.\n");
        exit(1);
    }

    $written++;
}

// Proof, not assumption: a base locale that never landed is the case where
// every prompt still reads in English and nothing above would have said so.
$base = $group.'/'.PURPOSE_STRING_BASE_LOCALE.'.lproj/InfoPlist.strings';

if (! is_file($base)) {
    fwrite(STDERR, "nativephp_ios_purpose_string_localisations: {$base} was not written.\n");
    exit(1);
}

$landed = count(glob($group.'/*.lproj/InfoPlist.strings') ?: []);

if ($landed !== count($localised)) {
    fwrite(STDERR, sprintf(
        "nativephp_ios_purpose_string_localisations: %d of %d locales landed in %s.\n",
        $landed,
        count($localised),
        $group,
    ));
    exit(1);
}

fwrite(STDOUT, $written === 0
    ? "nativephp_ios_purpose_string_localisations: already applied.\n"
    : "nativephp_ios_purpose_string_localisations: wrote purpose strings for {$written} locale(s).\n");

exit(0);
