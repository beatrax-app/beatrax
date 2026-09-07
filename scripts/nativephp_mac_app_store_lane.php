#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Adds the Mac App Store build target beside the Developer ID one.
 *
 * Why a second target rather than a switch:
 *
 *   The two lanes disagree about the things electron-builder decides once per
 *   config. Developer ID hardens the runtime and signs with a
 *   `Developer ID Application` certificate against `entitlements.mac.plist`,
 *   which carries two keys the App Store refuses. The store lane sandboxes,
 *   signs with `Apple Distribution`, and must NOT harden: a `mas` build is
 *   hardened only when told to be, and hardening it would restrict the
 *   writable-executable memory PCRE's JIT uses without granting the
 *   entitlement that allows it.
 *
 *   Distribution is additive (ADR-0032). Both lanes ship, so both configs
 *   have to exist at once.
 *
 * The identity and the profile are read from the environment, all or nothing:
 *
 *   `NATIVEPHP_MAS_IDENTITY` is the Apple Distribution certificate's common
 *   name, and `NATIVEPHP_MAS_PROVISIONING_PROFILE` the path to the Mac App
 *   Store profile. Both are artefacts of the Apple Developer portal rather
 *   than of this repository. When either is absent NEITHER key is written, so
 *   electron-builder fails on a mas build with no profile — which is the
 *   correct failure. A bundle signed for the store without one is not a bundle
 *   anybody can submit, and half a config would turn a missing prerequisite
 *   into a confusing signing error instead.
 *
 * The patch is reapplied before every build (it is a `prebuild` hook) and is
 * idempotent: a config that already carries a `mas` block is left untouched.
 *
 * Exit codes:
 *   0  patched, or already correct
 *   1  the config exists but does not have the expected shape
 */
$projectRoot = dirname(__DIR__);
$configPath = $projectRoot.'/nativephp/electron/electron-builder.mjs';

if (! is_file($configPath)) {
    fwrite(STDERR, "nativephp_mac_app_store_lane: no electron-builder.mjs found at {$configPath} — has `php artisan native:install --publish` run?\n");

    exit(1);
}

$source = file_get_contents($configPath);

if ($source === false) {
    fwrite(STDERR, "nativephp_mac_app_store_lane: could not read {$configPath}\n");

    exit(1);
}

if (str_contains($source, 'mas:')) {
    fwrite(STDOUT, "nativephp_mac_app_store_lane: a mas block is already present, leaving as-is.\n");

    exit(0);
}

$identity = trim((string) getenv('NATIVEPHP_MAS_IDENTITY'));
$profile = trim((string) getenv('NATIVEPHP_MAS_PROVISIONING_PROFILE'));

$credentials = '';

if ($identity !== '' && $profile !== '') {
    $credentials = "\n        identity: ".json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).','
        ."\n        provisioningProfile: ".json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).',';
}

$masBlock = <<<JS
    // Mac App Store lane — scripts/nativephp_mac_app_store_lane.php.
    // Sandboxed, NOT hardened, and signed against its own entitlements pair:
    // the Developer ID file carries two keys the store refuses.
    mas: {{$credentials}
        entitlements: 'build/entitlements.mas.plist',
        entitlementsInherit: 'build/entitlements.mas.inherit.plist',
        hardenedRuntime: false,
        type: 'distribution',
        artifactName: appName + '-\${version}-\${arch}-mas.\${ext}',
    },
JS;

// After the mac block closes, found by brace matching rather than by a regex:
// the block contains a nested object (extendInfo), and `[^{}]*` stops at the
// first inner brace while `.*` runs past the outer one.
$patched = beatraxInsertAfterMacBlock($source, $masBlock);

if ($patched === null) {
    fwrite(STDERR, "nativephp_mac_app_store_lane: could not find where the mac block ends in {$configPath}.\n");
    fwrite(STDERR, "The generated config changed shape; confirm the store lane still has its own entitlements before submitting.\n");

    exit(1);
}

if (file_put_contents($configPath, $patched) === false) {
    fwrite(STDERR, "nativephp_mac_app_store_lane: could not write {$configPath}\n");

    exit(1);
}

fwrite(STDOUT, "nativephp_mac_app_store_lane: added the sandboxed mas target beside the Developer ID mac one.\n");

exit(0);

/**
 * Inserts a block immediately after the `mac: { ... }` object closes.
 *
 * A regex cannot match balanced braces, and the mac block contains at least
 * one nested object (extendInfo). Counting is the only correct reader.
 */
function beatraxInsertAfterMacBlock(string $source, string $block): ?string
{
    if (preg_match('/\bmac\s*:\s*\{/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    $open = (int) $match[0][1] + strlen($match[0][0]) - 1;
    $depth = 0;

    for ($i = $open; $i < strlen($source); $i++) {
        $depth += match ($source[$i]) {
            '{' => 1,
            '}' => -1,
            default => 0,
        };

        if ($depth === 0) {
            // Past the closing brace and its comma, so the new block lands
            // between two complete properties rather than inside one.
            $after = strpos($source, "\n", $i);

            return $after === false
                ? null
                : substr($source, 0, $after + 1).$block."\n".substr($source, $after + 1);
        }
    }

    return null;
}
