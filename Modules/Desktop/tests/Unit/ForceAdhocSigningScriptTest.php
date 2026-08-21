<?php

declare(strict_types=1);

// A too-broad `identity:` match anywhere in the electron-builder config would
// skip the patch while the `mac:` block still lacks the key, silently letting
// the next macOS build re-acquire the partial-signing bug.
it('skips the patch only when the mac: { ... } block itself contains identity:', function (): void {
    $pattern = loadIdempotencyRegex();

    $alreadyPatched = <<<'JS'
        mac: {
            identity: null, // forced ad-hoc signing
            category: 'public.app-category.finance',
        }
        JS;

    expect(preg_match($pattern, $alreadyPatched))->toBe(1);
});

it('does NOT skip when a sibling block has identity: but the mac: block does not', function (): void {
    // A future electron-builder upgrade that adds an `identity:` key elsewhere —
    // appx config, publish settings — must not silently disable the patch.
    $pattern = loadIdempotencyRegex();

    $appxHasIdentity = <<<'JS'
        mac: {
            category: 'public.app-category.finance',
        },
        appx: {
            identity: 'My.Publisher',
        }
        JS;

    expect(preg_match($pattern, $appxHasIdentity))->toBe(0);
});

it('does NOT skip when a publish-config block has identity:', function (): void {
    $pattern = loadIdempotencyRegex();

    $publishHasIdentity = <<<'JS'
        publish: {
            identity: 'team-id',
        },
        mac: {
            category: 'public.app-category.finance',
        }
        JS;

    expect(preg_match($pattern, $publishHasIdentity))->toBe(0);
});

it('does NOT skip when only a JS comment outside the mac block mentions identity:', function (): void {
    $pattern = loadIdempotencyRegex();

    $onlyCommentIdentity = <<<'JS'
        // see also: identity: ad-hoc signing notes
        mac: {
            category: 'public.app-category.finance',
        }
        JS;

    expect(preg_match($pattern, $onlyCommentIdentity))->toBe(0);
});

it('does NOT skip when the mac: block is empty / lacks identity:', function (): void {
    $pattern = loadIdempotencyRegex();

    $macWithoutIdentity = <<<'JS'
        mac: {
            category: 'public.app-category.finance',
            hardenedRuntime: true,
        }
        JS;

    expect(preg_match($pattern, $macWithoutIdentity))->toBe(0);
});

function loadIdempotencyRegex(): string
{
    $script = (string) file_get_contents(base_path('scripts/nativephp_force_adhoc_signing.php'));
    // The pattern is read out of the script so the test cannot drift from it.
    // Single quotes only: that is the script's own syntax, and it avoids picking
    // up the patch-insertion regex further down the file.
    if (preg_match("#preg_match\(\s*'([^']+)'\s*,\s*\\\$source#", $script, $m) !== 1) {
        throw new RuntimeException('Could not locate the idempotency regex in nativephp_force_adhoc_signing.php');
    }

    return $m[1];
}
