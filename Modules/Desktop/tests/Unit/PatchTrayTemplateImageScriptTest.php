<?php

declare(strict_types=1);

/*
 * Static-fixture regression guards for `scripts/nativephp_patch_tray_template_image.php`.
 *
 * The script must:
 *
 *   1. Inject a `setTemplateImage(true)` call into the NativeImage handed to
 *      `new Tray(...)` so the macOS tray pictogram auto-tints to the active
 *      menu-bar appearance.
 *
 *   2. Correctly balance parentheses inside the `new Tray(<expr>)` argument.
 *      The upstream argument is `icon || state.icon.replace('icon.png',
 *      'IconTemplate.png')` — a flat `[^)]+` regex truncates at the first
 *      inner `)` and corrupts the dist file. The earlier draft of the script
 *      shipped exactly that bug; these tests pin the fix in place.
 *
 *   3. Stay idempotent — a dist that already contains `setTemplateImage`
 *      must be left untouched so re-running the prebuild hook is safe.
 *
 * The tests drive the script's `replaceFirstNewTrayCall` helper directly so
 * the patch logic is exercised without needing the full NativePHP electron
 * dist on disk. Loading the script via `require_once` registers the helper
 * function in the test's namespace; the `exit(...)` calls at the top level
 * are guarded by the missing-file check so requiring the file is safe.
 */

beforeEach(function (): void {
    // Loading the script registers `replaceFirstNewTrayCall()`. The top-level
    // executable block is guarded by an `$isDirectlyInvoked` check that is
    // false under the Pest runner, so requiring the file is a pure
    // function-definition side effect.
    require_once base_path('scripts/nativephp_patch_tray_template_image.php');
});

it('injects a templated NativeImage into the upstream `new Tray(...)` line', function (): void {
    $upstream = <<<'JS'
        if (onlyShowContextMenu) {
            const tray = new Tray(icon || state.icon.replace('icon.png', 'IconTemplate.png'));
            tray.setContextMenu(buildMenu(contextMenu));
        }
        JS;

    [$patched, $count] = replaceFirstNewTrayCall($upstream);

    expect($count)->toBe(1);
    expect($patched)->toContain('setTemplateImage(true)');
    expect($patched)->toContain("nativeImage.createFromPath(icon || state.icon.replace('icon.png', 'IconTemplate.png'))");
});

it('balances nested parentheses inside the Tray argument', function (): void {
    // The original buggy patch used a flat `[^)]+` regex and truncated at the
    // first inner `)`. Pin the balanced-paren walker so that bug stays gone.
    $upstream = "const tray = new Tray(icon || state.icon.replace('icon.png', 'IconTemplate.png'));";

    [$patched] = replaceFirstNewTrayCall($upstream);

    // The trailing `);` from the new Tray(...) call must still close cleanly:
    // the patched line ends with `})());`. A regex-truncation regression
    // would emit a stray `IconTemplate.png'); _img.setTemplateImage...`
    // sequence with mismatched parens.
    expect($patched)->toEndWith('})());');
    // The replacement preserves the full nested call as the createFromPath
    // argument — no character is dropped.
    expect($patched)->toContain("createFromPath(icon || state.icon.replace('icon.png', 'IconTemplate.png'))");
});

it('balances parentheses even when the argument string literal contains a `)`', function (): void {
    // A future upstream change could pass a string literal containing a
    // closing paren — the walker has to ignore parens that live inside
    // single- or double-quoted strings.
    $upstream = "const tray = new Tray('a)b');";

    [$patched, $count] = replaceFirstNewTrayCall($upstream);

    expect($count)->toBe(1);
    expect($patched)->toContain("createFromPath('a)b')");
});

it('reports failure when no `new Tray(...)` call is present', function (): void {
    [$patched, $count] = replaceFirstNewTrayCall("const x = 1;\nconsole.log('no tray here');\n");

    expect($patched)->toBeNull();
    expect($count)->toBe(0);
});

it('reports failure when the `new Tray(` opening paren has no matching close', function (): void {
    // A malformed source (truncated mid-call) must surface as failure rather
    // than silently producing a corrupted patch.
    [$patched, $count] = replaceFirstNewTrayCall("const tray = new Tray(icon || state.icon.replace('a', 'b'");

    expect($patched)->toBeNull();
    expect($count)->toBe(0);
});

it('produces JavaScript that calls setTemplateImage exactly once', function (): void {
    // Guards against a future regression where the patch accidentally
    // injects multiple flag calls (e.g. if the script were to walk every
    // `new Tray(...)` instead of only the first one).
    $upstream = "const tray = new Tray('/path/to/icon.png');";

    [$patched] = replaceFirstNewTrayCall($upstream);

    expect(substr_count($patched, 'setTemplateImage(true)'))->toBe(1);
});
