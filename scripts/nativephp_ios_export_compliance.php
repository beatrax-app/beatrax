<?php

declare(strict_types=1);

// Without this libxml_get_errors() is always empty, and the per-line
// detail below never printed — the failure was loud but unreadable.
libxml_use_internal_errors(true);

/*
 * Declare the app's export-compliance answer in the iOS Info.plist.
 *
 * Without ITSAppUsesNonExemptEncryption, App Store Connect re-asks the export
 * questionnaire on EVERY upload before the build can be submitted — which an
 * automated Bifrost -> TestFlight pipeline cannot answer.
 *
 * The value is true, and the reason is specific rather than cautious. Apple
 * exempts encryption "built into the operating system", and this app's crypto
 * is not: Ed25519 signing and BLAKE2b hashing come from libsodium through
 * PHP's sodium extension, a third-party library linked into the bundle
 * (Modules/Sync/Internal/Signing/DeviceKeySigner.php,
 * Modules/Sync/Internal/Transport/Noise/NoiseSymmetricState.php). Apple's own
 * documentation says the answer covers "any third-party libraries you link
 * against", so CryptoKit's absence is what decides this, not the algorithms.
 *
 * The algorithms themselves are standards-body work — Ed25519 is RFC 8032,
 * XChaCha20-Poly1305 and BLAKE2b likewise — so this is an industry-standard
 * algorithm outside the OS, not a proprietary one, and no US CCATS follows
 * from it. A French ANSSI declaration does, and that is paperwork rather than
 * code: see .docs/runbooks/mobile-release.md. Once ANSSI issues a code it goes
 * in ITSEncryptionExportComplianceCode beside this key, which also retires the
 * annual BIS self-classification report.
 *
 * Same discipline as its siblings: idempotent, marker-guarded, and a missing
 * anchor is a hard failure rather than a silent skip.
 */

const COMPLIANCE_KEY = 'ITSAppUsesNonExemptEncryption';

/**
 * The generated iOS project, which is not at one path: the desktop repo root
 * reaches it under mobile-app/, while a materialized Bifrost tree IS the
 * mobile root and holds it directly. Probed rather than assumed.
 */
function iosInfoPlists(): array
{
    $override = getenv('BEATRAX_NATIVE_ROOT');

    $roots = $override === false || $override === ''
        ? [dirname(__DIR__).'/mobile-app', dirname(__DIR__)]
        : [$override];

    foreach ($roots as $root) {
        $ios = $root.'/nativephp/ios';

        if (! is_file($ios.'/NativePHP/Info.plist')) {
            continue;
        }

        // The simulator target carries its own plist. It never reaches App
        // Store Connect, but a build configured from it and then archived
        // would ship without the key, so both are written.
        return array_values(array_filter([
            $ios.'/NativePHP/Info.plist',
            is_file($ios.'/NativePHP-simulator-Info.plist') ? $ios.'/NativePHP-simulator-Info.plist' : null,
        ]));
    }

    return [];
}

$plists = iosInfoPlists();

if ($plists === []) {
    fwrite(STDOUT, "nativephp_ios_export_compliance: no iOS scaffold yet — skipping.\n");
    exit(0);
}

$written = 0;

foreach ($plists as $plist) {
    $source = (string) file_get_contents($plist);

    if (str_contains($source, COMPLIANCE_KEY)) {
        continue;
    }

    // The closing dict of the plist's single root dictionary. Anchoring on the
    // LAST one rather than the first is what keeps the key out of a nested
    // dict such as NSAppTransportSecurity.
    $anchor = "\n  </dict>\n</plist>";

    if (! str_contains($source, $anchor)) {
        // The generated plist has been through several shapes; accept a
        // tab-or-space-indented close as well, but never guess further.
        $anchor = "</dict>\n</plist>";

        if (substr_count($source, $anchor) !== 1) {
            fwrite(STDERR, "nativephp_ios_export_compliance: root </dict> anchor not found in {$plist}.\n");
            fwrite(STDERR, 'The generated plist changed shape; add '.COMPLIANCE_KEY." by hand before uploading.\n");
            exit(1);
        }
    }

    $entry = '    <key>'.COMPLIANCE_KEY."</key>\n    <true/>\n";

    if (file_put_contents($plist, str_replace($anchor, "\n".$entry.ltrim($anchor, "\n"), $source)) === false) {
        fwrite(STDERR, "nativephp_ios_export_compliance: could not write {$plist}.\n");
        exit(1);
    }

    $written++;
}

// Proof, not assumption: an Info.plist Apple cannot parse fails the build long
// after this script, with an error that names neither the key nor this file.
foreach ($plists as $plist) {
    $reparsed = @simplexml_load_file($plist);

    if ($reparsed === false) {
        fwrite(STDERR, "nativephp_ios_export_compliance: {$plist} is no longer well-formed XML after patching.\n");

        foreach (libxml_get_errors() as $error) {
            fwrite(STDERR, '  line '.$error->line.': '.trim($error->message)."\n");
        }

        exit(1);
    }

    // Read the value back rather than trusting the write: a key nested inside
    // NSAppTransportSecurity would be present, greppable, and ignored.
    $value = $reparsed->xpath('/plist/dict/key[text()="'.COMPLIANCE_KEY.'"]/following-sibling::*[1]');

    if (! is_array($value) || $value === [] || $value[0]->getName() !== 'true') {
        fwrite(STDERR, 'nativephp_ios_export_compliance: '.COMPLIANCE_KEY." is not a top-level true in {$plist}.\n");
        exit(1);
    }
}

fwrite(STDOUT, $written === 0
    ? "nativephp_ios_export_compliance: already applied.\n"
    : "nativephp_ios_export_compliance: declared non-exempt encryption in {$written} plist(s).\n");

exit(0);
