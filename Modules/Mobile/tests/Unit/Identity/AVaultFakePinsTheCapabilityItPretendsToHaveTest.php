<?php

declare(strict_types=1);

// isAvailable() is runtimeAvailable() AND platformCanStore(), and a fake that
// overrides only the first leaves the second to the machine the test runs on.
// It read PHP_OS_FAMILY once, and sixteen tests passed on a Mac and failed on
// CI's Linux runner -- "Failed asserting that false is true" against a vault
// the fake had just declared available. It now asks the device over a bridge no
// toolchain has, which fails everywhere instead, for a reason no less unrelated
// to what the test is about.

it('pins the capability in every vault fake that pins availability', function (): void {
    $unpinned = [];

    // Recursive, not glob(): `**` in a glob pattern matches ONE directory
    // level, so it reached tests/Feature and silently skipped
    // tests/Unit/Identity -- where three of the sixteen failures lived.
    $files = [];
    /** @var Iterator<SplFileInfo> $found */
    $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules/Mobile/tests')));
    foreach ($found as $file) {
        if (str_ends_with($file->getPathname(), 'Test.php')) {
            $files[] = $file->getPathname();
        }
    }

    // A guard that reaches nothing passes for the wrong reason.
    expect(count($files))->toBeGreaterThan(60)
        ->and(implode(' ', $files))->toContain('BiometricKeyVaultTest.php');

    foreach ($files as $path) {
        $source = (string) file_get_contents((string) $path);
        $offset = 0;

        while (($at = strpos($source, 'extends BiometricKeyVault', $offset)) !== false) {
            $offset = $at + 1;

            // The fake's body, bounded by the next fake in the same file so two
            // subclasses cannot cover for one another.
            $next = strpos($source, 'extends ', $at + 1);
            $body = substr($source, $at, ($next === false ? strlen($source) : $next) - $at);

            // capabilityAnswer() counts: it is the bridge call itself, so a fake
            // answering for it pins the capability through the reading above it.
            $pinsCapability = str_contains($body, 'vaultCapability')
                || str_contains($body, 'capabilityAnswer')
                || str_contains($body, 'platformCanStore');

            if (str_contains($body, 'runtimeAvailable') && ! $pinsCapability) {
                $unpinned[] = str_replace(base_path().'/', '', (string) $path)
                    .':'.(substr_count(substr($source, 0, $at), "\n") + 1);
            }
        }
    }

    sort($unpinned);

    expect($unpinned)->toBe(
        [],
        "These vault fakes decide availability but let the machine decide the capability:\n  ".implode("\n  ", $unpinned)
    );
});
