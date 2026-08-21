<?php

declare(strict_types=1);

// The bundle runs an embedded static PHP interpreter under the macOS Hardened
// Runtime. Without these two entitlements dyld refuses to map the PHP binary
// into the notarized process and a notarized release build will not launch.
it('exists at the path the electron-builder mac config references', function (): void {
    expect(is_file(base_path('build/entitlements.mac.plist')))->toBeTrue();
});

it('parses as a valid XML plist', function (): void {
    $contents = file_get_contents(base_path('build/entitlements.mac.plist'));
    expect($contents)->toBeString()->not->toBe('');

    /** @var SimpleXMLElement|false $xml */
    $xml = @simplexml_load_string($contents);
    expect($xml)->not->toBeFalse();

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
    // A plist dict alternates <key>NAME</key> with its value element, so the
    // children are walked in pairs.
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
