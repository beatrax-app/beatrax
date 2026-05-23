<?php

declare(strict_types=1);

/*
 * Static-fixture regression guards for the macOS ad-hoc-signing
 * idempotency regex in `scripts/nativephp_force_adhoc_signing.php`.
 *
 * The script's failure mode the review pinned (WR-04): a too-broad
 * `\bidentity\s*:` match would match `identity:` anywhere in the
 * electron-builder config — `appx: { identity: ... }`, a JS comment,
 * etc. — causing the patch to be skipped even though the `mac:` block
 * still lacks an `identity` key. The next macOS build would silently
 * re-acquire the partial-signing bug the script exists to prevent.
 *
 * The regex must scope the guard to the innermost `mac: { ... }` block.
 * Tests drive each variant by extracting the regex string from the
 * script source — keeping the test and the script in lockstep without
 * re-encoding the pattern.
 */

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
    // The WR-04 regression case. A future electron-builder upgrade that
    // adds an `identity:` key elsewhere (Windows Store appx config,
    // publish settings, etc.) must NOT silently disable the patch.
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

/**
 * Reads the regex literal out of the signing-script source so the
 * tests and the script use the same pattern. Returns the regex with
 * its delimiters intact (the script's preg_match call shape).
 */
function loadIdempotencyRegex(): string
{
    $script = (string) file_get_contents(base_path('scripts/nativephp_force_adhoc_signing.php'));
    // The regex literal lives on the line that calls preg_match against
    // the `mac:` block; capture the first single-quoted string on that
    // line. Restricting to single quotes matches the script's actual
    // syntax and avoids picking up the patch-insertion regex below it.
    if (preg_match("#preg_match\(\s*'([^']+)'\s*,\s*\\\$source#", $script, $m) !== 1) {
        throw new RuntimeException('Could not locate the idempotency regex in nativephp_force_adhoc_signing.php');
    }

    return $m[1];
}
