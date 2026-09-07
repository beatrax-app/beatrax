<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Boot\MacBundleReview;

// The Developer ID lane and the store lane cannot share one entitlements
// file: two keys the notarised build relies on are refused for App Store
// distribution.

/** @return array<string, mixed> */
function storeLaneEntitlements(string $name): array
{
    $path = base_path('build/'.$name);

    if (! is_file($path)) {
        return [];
    }

    $document = simplexml_load_string((string) file_get_contents($path));

    if ($document === false) {
        return [];
    }

    $keys = [];
    $key = null;

    foreach ($document->dict->children() as $node) {
        if ($node->getName() === 'key') {
            $key = (string) $node;

            continue;
        }

        if ($key !== null) {
            $keys[$key] = $node->getName() === 'true';
            $key = null;
        }
    }

    return $keys;
}

it('ships a store entitlements file beside the Developer ID one', function (string $name): void {
    expect(is_file(base_path('build/'.$name)))->toBeTrue();
    expect(storeLaneEntitlements($name))->not->toBe([]);
})->with(['entitlements.mas.plist', 'entitlements.mas.inherit.plist']);

it('sandboxes the app, which is what the store takes and Developer ID does not', function (): void {
    expect(storeLaneEntitlements('entitlements.mas.plist'))
        ->toHaveKey('com.apple.security.app-sandbox');
    expect(storeLaneEntitlements('entitlements.mas.plist')['com.apple.security.app-sandbox'])->toBeTrue();
});

it('carries none of the entitlements the store refuses', function (string $refused): void {
    foreach (['entitlements.mas.plist', 'entitlements.mas.inherit.plist'] as $file) {
        expect(storeLaneEntitlements($file))->not->toHaveKey($refused);
    }
})->with(array_keys(MacBundleReview::REFUSED_ENTITLEMENTS));

// Apple terminates a child that combines inherit with any other sandbox
// entitlement, so the inherit file is exactly two keys long or it is wrong.
it('gives an inheriting child exactly app-sandbox and inherit', function (): void {
    expect(array_keys(storeLaneEntitlements('entitlements.mas.inherit.plist')))
        ->toBe(MacBundleReview::CHILD_SANDBOX_ENTITLEMENTS);
});

// The Developer ID file is the control: it has to still carry what the store
// refuses, or the two lanes were quietly collapsed into one.
it('leaves the Developer ID lane holding the keys the store will not take', function (): void {
    $developerId = storeLaneEntitlements('entitlements.mac.plist');

    expect($developerId)->toHaveKey('com.apple.security.cs.allow-unsigned-executable-memory');
    expect($developerId)->not->toHaveKey('com.apple.security.app-sandbox');
});
