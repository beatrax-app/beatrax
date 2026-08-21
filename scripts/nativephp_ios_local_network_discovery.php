<?php

declare(strict_types=1);

libxml_use_internal_errors(true);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Let the iOS build speak mDNS at all.
 *
 * `MulticastMdnsQuery` finds a pairing peer by sending a raw UDP datagram to
 * 224.0.0.251 and reading the unicast answer. On a real iPhone that browse
 * returned NOTHING, every time, while the identical class on macOS found the
 * desktop instantly — so the code and the advertisement were fine and the
 * platform was refusing the send.
 *
 * Two declarations are missing from the generated scaffold, and iOS needs both:
 *
 *   com.apple.developer.networking.multicast   (entitlement)
 *     Since iOS 14 a raw multicast send is refused unless the app holds this.
 *     Local Network permission alone does NOT cover it — which is why the
 *     symptom was so confusing: the system prompt appeared, the reader could
 *     grant it, and the browse still found nothing.
 *
 *   NSBonjourServices                          (Info.plist)
 *     The service types the app may browse. Without it the Local Network
 *     prompt can appear while discovery of this service is still not permitted.
 *
 * The service type is `_beatrax-sync._tcp`, matching MdnsAdvertiser.
 *
 * A caveat that belongs in the release checklist rather than in code: the
 * multicast entitlement is one Apple grants on request for distribution
 * builds. Development signing accepts it, so this unblocks device testing and
 * the ad-hoc builds; shipping through the App Store needs the grant on the
 * account first, and a provisioning profile that lacks it fails to sign rather
 * than failing quietly at runtime.
 *
 * Without pairing over the LAN a phone can only pair through a relay, which
 * is the very thing the LAN roads exist to avoid.
 */

$root = beatraxScaffoldPath('ios/NativePHP') ?? '';

$entitlements = $root.'/NativePHP.entitlements';
$infoPlist = $root.'/Info.plist';

if (! is_file($entitlements) || ! is_file($infoPlist)) {
    fwrite(STDOUT, "nativephp_ios_local_network_discovery: no iOS scaffold yet — skipping.\n");
    exit(0);
}

const BEATRAX_MULTICAST_ENTITLEMENT = 'com.apple.developer.networking.multicast';
const BEATRAX_BONJOUR_SERVICE = '_beatrax-sync._tcp';

$entitlementsXml = (string) file_get_contents($entitlements);

if (str_contains($entitlementsXml, BEATRAX_MULTICAST_ENTITLEMENT)) {
    fwrite(STDOUT, "nativephp_ios_local_network_discovery: entitlement already declared.\n");
} else {
    // Matches both the empty `<dict></dict>` a fresh scaffold writes and a
    // populated one, so this composes with whatever else has been added.
    $entry = "\t<key>".BEATRAX_MULTICAST_ENTITLEMENT."</key>\n\t<true/>\n";
    $patched = preg_replace('#(<dict>\s*)#', "<dict>\n".$entry, $entitlementsXml, 1);

    if (! is_string($patched) || ! str_contains($patched, BEATRAX_MULTICAST_ENTITLEMENT)) {
        fwrite(STDERR, "nativephp_ios_local_network_discovery: no <dict> to extend in {$entitlements}.\n");
        exit(1);
    }

    if (file_put_contents($entitlements, $patched) === false) {
        fwrite(STDERR, "nativephp_ios_local_network_discovery: could not write {$entitlements}.\n");
        exit(1);
    }

    fwrite(STDOUT, "nativephp_ios_local_network_discovery: multicast entitlement declared.\n");
}

$infoXml = (string) file_get_contents($infoPlist);

if (str_contains($infoXml, 'NSBonjourServices')) {
    fwrite(STDOUT, "nativephp_ios_local_network_discovery: Bonjour services already declared.\n");
} else {
    $entry = "\t<key>NSBonjourServices</key>\n\t<array>\n\t\t<string>".BEATRAX_BONJOUR_SERVICE."</string>\n\t</array>\n";
    $patchedInfo = preg_replace('#(<dict>\s*)#', "<dict>\n".$entry, $infoXml, 1);

    if (! is_string($patchedInfo) || ! str_contains($patchedInfo, 'NSBonjourServices')) {
        fwrite(STDERR, "nativephp_ios_local_network_discovery: no <dict> to extend in {$infoPlist}.\n");
        exit(1);
    }

    if (file_put_contents($infoPlist, $patchedInfo) === false) {
        fwrite(STDERR, "nativephp_ios_local_network_discovery: could not write {$infoPlist}.\n");
        exit(1);
    }

    fwrite(STDOUT, "nativephp_ios_local_network_discovery: Bonjour service type declared.\n");
}

// Proof, not assumption: a malformed plist fails much later inside Xcode with
// an error nobody reads back to this script.
foreach ([$entitlements, $infoPlist] as $file) {
    if (@simplexml_load_file($file) === false) {
        fwrite(STDERR, "nativephp_ios_local_network_discovery: {$file} is no longer well-formed XML.\n");

        foreach (libxml_get_errors() as $error) {
            fwrite(STDERR, '  line '.$error->line.': '.trim($error->message)."\n");
        }

        exit(1);
    }
}

exit(0);
