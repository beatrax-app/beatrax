<?php

declare(strict_types=1);

libxml_use_internal_errors(true);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Let the iOS build declare an entitlement at all, and browse Bonjour.
 *
 * `MulticastMdnsQuery` finds a pairing peer by sending a raw UDP datagram to
 * 224.0.0.251 and reading the unicast answer. On a real iPhone that browse
 * returned NOTHING, every time, while the identical class on macOS found the
 * desktop instantly — so the code and the advertisement were fine and the
 * platform was refusing the send. Since iOS 14 a raw multicast send is refused
 * unless the app holds `com.apple.developer.networking.multicast`; the Local
 * Network permission does not cover it, and `NSBonjourServices` exempts
 * NWBrowser and NSNetService traffic rather than a BSD socket.
 *
 * Writing that entitlement into the generated NativePHP.entitlements is what
 * this script used to do, and it never reached a binary:
 * `BuildIosAppCommand::updateEntitlementsFile()` rewrites that file FROM
 * SCRATCH out of `deeplink_host` + `permissions.push_notifications` +
 * `permissions.nfc` on every `native:build`, discarding whatever was there.
 * With all three unset the result is a literal empty `<dict/>` — which is
 * exactly what the installed binary carried.
 *
 * So the entitlement has to arrive AFTER that rewrite. `IOSPluginCompiler`
 * runs later in the same `configureXcodeProject()` and already merges
 * entitlements — from plugin manifests only. This patch teaches its merge to
 * read `config('nativephp.entitlements')` too, the same way it already reads
 * `config('nativephp.permissions')` for Info.plist, so the declaration lives
 * in committed config instead of in generated output.
 *
 * `IOSPluginCompiler` is the right file to patch rather than
 * `BuildIosAppCommand`: Artisan instantiates every registered command before
 * it fires CommandStarting, so by the time NativeBuildPatches runs a command
 * class is already loaded and a rewrite of it cannot affect the build now
 * being started. The compiler is resolved later, from `app()`, and picks the
 * patched file up in the same process.
 *
 * The entitlement itself is one Apple grants per Team on request, and no
 * provisioning profile on this account carries it yet, so declaring it
 * unconditionally would fail signing rather than fail discovery. The config
 * key is env-gated and off until the grant lands.
 *
 * @link ../.docs/features/mobile/ios-lan-discovery-entitlement.md
 */

const BEATRAX_ENTITLEMENTS_CONFIG = "config('nativephp.entitlements')";
const BEATRAX_BONJOUR_SERVICE = '_beatrax-sync._tcp';

// Both sit in IOSPluginCompiler::compile()/mergeEntitlements(). The guard has
// to yield as well: with no plugin shipping iOS data, compile() returns before
// the merge is ever reached and app-declared entitlements vanish with it.
$compilerPatches = [
    "            && ! \$hasAppLocalizations\n        ) {\n" => "            && ! \$hasAppLocalizations\n            && empty(".BEATRAX_ENTITLEMENTS_CONFIG.")\n        ) {\n",
    "        // Collect all entitlements from plugins\n        \$allEntitlements = [];\n" => "        // Collect all entitlements from plugins\n        \$allEntitlements = (array) config('nativephp.entitlements', []);\n",
];

$compiler = beatraxMobileVendorPath('nativephp/mobile/src/Plugins/Compilers/IOSPluginCompiler.php');

if ($compiler === null) {
    fwrite(STDOUT, "nativephp_ios_local_network_discovery: no mobile vendor tree — skipping the compiler patch.\n");
} else {
    $source = (string) file_get_contents($compiler);

    if (str_contains($source, BEATRAX_ENTITLEMENTS_CONFIG)) {
        fwrite(STDOUT, "nativephp_ios_local_network_discovery: compiler already reads app entitlements.\n");
    } else {
        foreach ($compilerPatches as $needle => $replacement) {
            if (! str_contains($source, $needle)) {
                fwrite(STDERR, "nativephp_ios_local_network_discovery: anchor not found in IOSPluginCompiler.php:\n{$needle}\n");
                exit(1);
            }

            $source = str_replace($needle, $replacement, $source);
        }

        if (file_put_contents($compiler, $source) === false) {
            fwrite(STDERR, "nativephp_ios_local_network_discovery: could not write {$compiler}.\n");
            exit(1);
        }

        fwrite(STDOUT, "nativephp_ios_local_network_discovery: compiler now merges config('nativephp.entitlements').\n");
    }
}

$infoPlist = beatraxScaffoldPath('ios/NativePHP/Info.plist');

if ($infoPlist === null) {
    fwrite(STDOUT, "nativephp_ios_local_network_discovery: no iOS scaffold yet — skipping the Info.plist entry.\n");
    exit(0);
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
if (@simplexml_load_file($infoPlist) === false) {
    fwrite(STDERR, "nativephp_ios_local_network_discovery: {$infoPlist} is no longer well-formed XML.\n");

    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, '  line '.$error->line.': '.trim($error->message)."\n");
    }

    exit(1);
}

exit(0);
