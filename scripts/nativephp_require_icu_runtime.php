<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Refuse a bundled PHP runtime that carries no ICU.
 *
 * Both composer roots declare `ext-intl`, but composer resolves that against
 * the BUILD HOST's PHP, never the runtime that goes on the phone. Which
 * runtime that is gets decided by one flag: `native:install --with-icu` picks
 * the `-icu` variant and records `"icu": true` in nativephp.lock, and a bare
 * `native:install` — the form NativePHP's own documentation gives — silently
 * picks the variant with no ICU at all and records `"icu": false`. Nothing
 * downstream reads that back, so the swap is invisible until a reader hits it.
 *
 * What the reader hits: Laravel's Number::format() checks extension_loaded()
 * and throws RuntimeException, which the ICU-less fallbacks in Fmt::number()
 * and Money::format() do not name — they catch IntlException and ValueError,
 * and with no intl loaded the class IntlException does not even exist. So the
 * fallback written for exactly this case cannot be reached and the page 500s.
 * Measured on an iPhone 12 mini: the setup wizard's own skip button answered
 * with an Internal Server Error dialog and the wizard could not be advanced.
 *
 * Degrading instead of refusing would be worse than the 500. FingerprintComposer
 * and CounterpartySlugResolver both reach Normalizer to fold diacritics, and
 * both feed PERSISTED keys — a phone that quietly skipped that folding would
 * write fingerprints and slugs the desktop never agrees with, forking every
 * counterparty on the next sync. The extension is a build input, so a build is
 * where its absence has to be caught.
 *
 * Runs from the same two places every other generated-project patch does, so
 * it covers iOS as well as Android — iOS has no packaging command to hang a
 * check on.
 */

// BEATRAX_NATIVE_ROOT names the tree whose scaffold is being patched, and in a
// materialized mobile-build tree the lock sits at that root rather than beside
// this repository's mobile-app/.
$override = getenv('BEATRAX_NATIVE_ROOT');

$lock = $override === false || $override === ''
    ? beatraxSourcePath('nativephp.lock')
    : (is_file($override.'/nativephp.lock') ? $override.'/nativephp.lock' : null);

if ($lock === null) {
    // No runtime has been installed yet, so there is none to judge. The
    // post-update-cmd ordering runs native:install before this script.
    echo "nativephp_require_icu_runtime: no nativephp.lock yet — nothing installed to check.\n";

    exit(0);
}

$contents = file_get_contents($lock);

if ($contents === false) {
    fwrite(STDERR, "nativephp_require_icu_runtime: {$lock} could not be read.\n");

    exit(1);
}

try {
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "nativephp_require_icu_runtime: {$lock} is not valid JSON: ".$e->getMessage()."\n");

    exit(1);
}

$php = is_array($decoded) && isset($decoded['php']) && is_array($decoded['php']) ? $decoded['php'] : [];
$version = isset($php['version']) && is_string($php['version']) ? $php['version'] : 'unknown';

if (($php['icu'] ?? null) === true) {
    echo "nativephp_require_icu_runtime: bundled PHP {$version} carries ICU.\n";

    exit(0);
}

fwrite(STDERR, <<<TEXT
nativephp_require_icu_runtime: the bundled PHP ({$version}) carries no ICU, so ext-intl is
  absent from the runtime this build would put on a device, while both composer roots
  declare ext-intl. Recorded in {$lock} as "icu": false.

  Re-run the install with the flag that selects the ICU variant:

      cd mobile-app && php artisan native:install --with-icu

  That is what composer's post-update-cmd runs; a bare `native:install` replaces the
  runtime with the non-ICU build and this file is the only record that it happened.

TEXT);

exit(1);
