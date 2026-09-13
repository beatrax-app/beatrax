<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Keep vendor packages' dev tooling out of the shipped bundle.
 *
 * `BundleExclusions::VENDOR_PATTERNS` drops a package's `docs` directory and
 * `ANY_DEPTH` drops its `tests`, but nothing dropped `tools` — so every
 * package's dev helpers were copied into the Laravel bundle and shipped.
 *
 * One of them is key material. `amphp/http-server/tools/tls/` carries the TLS
 * fixtures its example server runs against: `localhost.key.pem` is a PRIVATE
 * KEY block, and `localhost.pem` is a certificate with the same key appended.
 * A signed release APK carried all three, and `mobile:inspect-bundle` refused
 * the artifact the first time it was ever able to read one.
 *
 * The six `tools` directories in the mobile vendor tree are php-cs-fixer
 * configurations, an ECS configuration, a fuzzer, an h2spec shell script and
 * an autoload generator. None is reachable at runtime: composer autoloads none
 * of them, because no package puts a PSR-4 root under `tools`.
 *
 * Scoped to `VENDOR_PATTERNS` rather than `ANY_DEPTH` deliberately — the
 * pattern list is applied under `vendor/` only, so this repo's own dev-only
 * `tools/` keeps whatever treatment the project list gives it.
 *
 * Patches `mobile-app/vendor/` (composer-managed, wiped by `composer update`),
 * so it is re-applied from post-update-cmd like the patches beside it.
 * Idempotent; a missing anchor is a hard failure rather than a silent skip,
 * because an unpatched list ships key material and says nothing.
 */

$target = beatraxMobileVendorPath('nativephp/mobile/src/Support/BundleExclusions.php');

$anchor = "    public const VENDOR_PATTERNS = [\n";
$patched = $anchor."        'tools',\n";

// Every platform's build runs the whole patch list, and only the mobile root
// installs nativephp/mobile. A desktop tree has no Laravel bundle to copy
// vendor into, so there is nothing here that could ship a key — and a required
// patch that failed on its absence stopped macOS, Windows and Linux dead.
if ($target === null) {
    fwrite(STDOUT, "nativephp_exclude_vendor_dev_tools: no mobile vendor tree here — skipping.\n");

    exit(0);
}

$contents = (string) file_get_contents($target);

if (str_contains($contents, $patched)) {
    echo "Vendor dev tooling already excluded from the bundle; nothing to do.\n";

    exit(0);
}

if (! str_contains($contents, $anchor)) {
    fwrite(STDERR, sprintf("Anchor not found in %s: VENDOR_PATTERNS\n", basename($target)));

    exit(1);
}

file_put_contents($target, str_replace($anchor, $patched, $contents));

echo "Excluded vendor dev tooling (tools/) from the shipped bundle.\n";
