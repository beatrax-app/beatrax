<?php

declare(strict_types=1);

/*
 * macOS Hardened Runtime entitlements (PKG-08 / T-15-14, T-15-15).
 *
 * The bundled NativePHP build runs an embedded static PHP interpreter
 * under the macOS Hardened Runtime once Phase 17 wires real Apple
 * Developer ID notarization. Two entitlements are the documented
 * minimum for an embedded interpreter — without them dyld refuses to
 * map the PHP binary into the notarized process and the app will not
 * launch on a notarized release build (RESEARCH.md Pitfall 6).
 *
 * Phase 15 only CONFIGURES the file (PKG-08). The signing + notarization
 * run themselves arrive in Phase 17.
 */

it('exists at the path the electron-builder mac config references', function (): void {
    expect(is_file(base_path('build/entitlements.mac.plist')))->toBeTrue();
});

it('parses as a valid XML plist', function (): void {
    $contents = file_get_contents(base_path('build/entitlements.mac.plist'));
    expect($contents)->toBeString()->not->toBe('');

    /** @var SimpleXMLElement|false $xml */
    $xml = @simplexml_load_string($contents);
    expect($xml)->not->toBeFalse();

    // Plist root element is <plist version="1.0"> containing a single <dict>.
    expect($xml->getName())->toBe('plist');
    expect(isset($xml->dict))->toBeTrue();
});

it('declares com.apple.security.cs.allow-unsigned-executable-memory as true', function (): void {
    $keys = loadHardenedRuntimeEntitlements();
    expect($keys)->toHaveKey('com.apple.security.cs.allow-unsigned-executable-memory');
    expect($keys['com.apple.security.cs.allow-unsigned-executable-memory'])->toBeTrue();
});

it('declares com.apple.security.cs.disable-library-validation as true', function (): void {
    $keys = loadHardenedRuntimeEntitlements();
    expect($keys)->toHaveKey('com.apple.security.cs.disable-library-validation');
    expect($keys['com.apple.security.cs.disable-library-validation'])->toBeTrue();
});

/**
 * Parse the entitlements plist into a [key => bool] map. Plists alternate
 * <key>NAME</key> and the value element (<true/> / <false/>), so we walk
 * the <dict>'s children in pairs.
 *
 * @return array<string, bool>
 */
function loadHardenedRuntimeEntitlements(): array
{
    $path = base_path('build/entitlements.mac.plist');
    if (! is_file($path)) {
        return [];
    }
    $contents = (string) file_get_contents($path);
    /** @var SimpleXMLElement|false $xml */
    $xml = @simplexml_load_string($contents);
    if ($xml === false || ! isset($xml->dict)) {
        return [];
    }

    $children = $xml->dict->children();
    $map = [];
    $currentKey = null;
    foreach ($children as $node) {
        $name = $node->getName();
        if ($name === 'key') {
            $currentKey = (string) $node;

            continue;
        }
        if ($currentKey === null) {
            continue;
        }
        $map[$currentKey] = match ($name) {
            'true' => true,
            'false' => false,
            default => null,
        };
        $currentKey = null;
    }

    /** @var array<string, bool> $map */
    return $map;
}
