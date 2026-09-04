<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Give the iOS app an App Store category.
 *
 * App Store Connect refuses a submission whose primary category is unset, and
 * the generated project sets it to the empty string in every build
 * configuration — NativePHP's Xcode template ships
 * `INFOPLIST_KEY_LSApplicationCategoryType = ""` and `native:install` copies it
 * forward.
 *
 * Declaring the key in config('nativephp.permissions') is not enough on its
 * own. That array reaches the Info.plist through IOSPluginCompiler, but the
 * target also carries GENERATE_INFOPLIST_FILE = YES beside its explicit
 * INFOPLIST_FILE, so Xcode merges every INFOPLIST_KEY_* build setting into the
 * processed plist as well. Reading the last built app back shows exactly that:
 * LSApplicationCategoryType is present and empty in
 * NativePHP.app/Info.plist while the source Info.plist never mentions it.
 *
 * What that proves is that this build setting writes this key, so setting it
 * is necessary. Which value would win if the two disagreed is NOT established
 * — that needs a real iOS build to settle. Both are set, so the question never
 * has to be answered and the artefact carries the same value either way.
 *
 * Finance is the category the product is, and it is the one whose review
 * expectations the store applies to a personal-finance app; picking a milder
 * one to attract a lighter review would be a false statement about what the
 * app does.
 *
 * Same discipline as its siblings: idempotent, marker-guarded, and a missing
 * anchor is a hard failure rather than a silent skip.
 */

const BUILD_SETTING = 'INFOPLIST_KEY_LSApplicationCategoryType';

const APP_CATEGORY = 'public.app-category.finance';

$pbxproj = beatraxScaffoldPath('ios/NativePHP.xcodeproj/project.pbxproj');

if ($pbxproj === null) {
    fwrite(STDOUT, "nativephp_ios_app_category: no iOS scaffold yet — skipping.\n");
    exit(0);
}

$source = (string) file_get_contents($pbxproj);

$empty = '#'.preg_quote(BUILD_SETTING, '#').'\s*=\s*"";#';
$already = '#'.preg_quote(BUILD_SETTING, '#').'\s*=\s*"?'.preg_quote(APP_CATEGORY, '#').'"?;#';

$blank = preg_match_all($empty, $source);
$set = preg_match_all($already, $source);

// preg_match_all returns false on a backtrack or recursion limit, and false
// reads as "no matches" to anything that does not check. That would report a
// project needing no change and exit 0 over a category that is still empty.
if ($blank === false || $set === false) {
    fwrite(STDERR, "nativephp_ios_app_category: the pbxproj could not be scanned.\n");
    exit(1);
}

if ($blank === 0 && $set === 0) {
    fwrite(STDERR, 'nativephp_ios_app_category: '.BUILD_SETTING." is not declared in {$pbxproj}.\n");
    fwrite(STDERR, "The generated project changed shape; set the primary category by hand before submitting.\n");
    exit(1);
}

if ($blank > 0) {
    $rewritten = preg_replace($empty, BUILD_SETTING.' = "'.APP_CATEGORY.'";', $source);

    if (! is_string($rewritten) || file_put_contents($pbxproj, $rewritten) === false) {
        fwrite(STDERR, "nativephp_ios_app_category: could not write {$pbxproj}.\n");
        exit(1);
    }
}

// Proof, not assumption: a rewrite that matched nothing leaves a project that
// builds cleanly and is rejected at upload, which is the failure this exists
// to stop.
$verified = (string) file_get_contents($pbxproj);

if (preg_match($empty, $verified) === 1) {
    fwrite(STDERR, 'nativephp_ios_app_category: '.BUILD_SETTING." is still empty in {$pbxproj}.\n");
    exit(1);
}

if (preg_match($already, $verified) !== 1) {
    fwrite(STDERR, 'nativephp_ios_app_category: '.BUILD_SETTING.' does not carry '.APP_CATEGORY." in {$pbxproj}.\n");
    exit(1);
}

fwrite(STDOUT, $blank === 0
    ? "nativephp_ios_app_category: already applied.\n"
    : "nativephp_ios_app_category: set the App Store category in {$blank} build configuration(s).\n");

exit(0);
