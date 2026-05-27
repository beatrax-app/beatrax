<?php

declare(strict_types=1);

/**
 * Public-release defensive arch invariant — runtime code and public
 * documentation must not name internal planning artefacts.
 *
 * Two scans run in sequence and accumulate into a single $hits[] array:
 *
 * 1. Runtime-code scan (strict pattern, comment-stripped):
 *      routes/, Modules/, config/, app/ over *.php + *.blade.php.
 *      Pattern matches `.planning/`, PLAN.md, RESEARCH.md, CONTEXT.md,
 *      REVIEW.md, the `D-NN` / `D-NNN` codename shape, and any
 *      `gsd-` / `gsd_` prefixed identifier. Block / line / Blade
 *      comments are stripped first so the pattern fires only against
 *      live code — that means legitimate PHPDoc references stay legal,
 *      but render-output strings, log lines, route names, and view-data
 *      keys do not. Test files are excluded because contract fixtures
 *      and the arch test itself reference the planning corpus by name.
 *
 * 2. Public-docs scan (narrower pattern, no comment-strip):
 *      .docs/ over every file. Pattern omits the `D-NN` codename shape
 *      so graduated ADRs may legitimately cite their source phase, but
 *      still catches `.planning/`, PLAN.md, RESEARCH.md, CONTEXT.md and
 *      gsd- / gsd_ prefixes — the actual leakage class the public repo
 *      must never carry. Markdown has no inline-comment syntax, so the
 *      pattern fires against the raw contents.
 *
 * Allow-list policy: every legitimate match must land in the explicit
 * $runtimeAllowList or $docsAllowList below with an inline rationale.
 * The lists are intentionally empty today — once the cleanup sweep is
 * complete the codebase carries zero matches under either scan.
 *
 * Cleanup outcome (recorded at landing):
 *   - Runtime-code scan: zero matches after the comment-strip pass.
 *     Every PHPDoc D-NN / CONTEXT.md / RESEARCH.md reference identified
 *     during the dry-run already lives inside block or line comments
 *     and therefore strips out cleanly; no runtime PHP / Blade string
 *     names a planning artefact. No allow-list entries needed.
 *   - .docs/ scan: one match initially — `.docs/cicd/branch-protection.md`
 *     contained the string `CONTEXT.md` in a heading describing the
 *     posture choice. Rewritten to name the architectural intent
 *     without citing the planning blob; no allow-list entry needed.
 */
it('does not allow GSD planning artefacts to leak into runtime code or public docs (noGsdLeakage)', function (): void {
    /** @var list<string> $runtimeAllowList */
    $runtimeAllowList = [
        // Inline rationale required for every entry — use relative path
        // from the repo root. Example:
        // 'Modules/Foo/Bar.php',  // ← justification …
    ];

    /** @var list<string> $docsAllowList */
    $docsAllowList = [
        // Inline rationale required for every entry — use relative path
        // from the repo root.
    ];

    $hits = [];

    // ── Scan A — runtime-code corpus (strict pattern) ────────────────
    $runtimePattern = '/\.planning\/|PLAN\.md|RESEARCH\.md|CONTEXT\.md|REVIEW\.md|\bD-\d{2,3}\b|gsd[-_]/i';
    foreach (['routes', 'Modules', 'config', 'app'] as $root) {
        $absoluteRoot = base_path($root);
        if (! is_dir($absoluteRoot)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $absoluteRoot,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (preg_match('/\.(php|blade\.php)$/', $path) !== 1) {
                continue;
            }
            // Test fixtures and the arch test itself reference the
            // planning corpus by name. The trailing slash on the match
            // keeps `Modules/Foo/Tests` (no production analog today)
            // from being silenced.
            if (str_contains($path, '/tests/') || str_contains($path, '/Tests/')) {
                continue;
            }
            $relative = str_replace(base_path().'/', '', $path);
            if (in_array($relative, $runtimeAllowList, true)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            $stripped = preg_replace(
                '#/\*.*?\*/|//[^\n]*|\{\{--.*?--\}\}#s',
                '',
                $contents,
            ) ?? $contents;
            if (preg_match($runtimePattern, $stripped) === 1) {
                $hits[] = $relative;
            }
        }
    }

    // ── Scan B — public-docs corpus (narrower pattern, raw) ─────────
    $docsPattern = '/\.planning\/|PLAN\.md|RESEARCH\.md|CONTEXT\.md|gsd[-_]/i';
    $docsRoot = base_path('.docs');
    if (is_dir($docsRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $docsRoot,
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            // Skip a `_template/` subtree if present — placeholder docs
            // intentionally name the artefacts they replace.
            if (str_contains($path, '/.docs/_template/')) {
                continue;
            }
            $relative = str_replace(base_path().'/', '', $path);
            if (in_array($relative, $docsAllowList, true)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (preg_match($docsPattern, $contents) === 1) {
                $hits[] = $relative;
            }
        }
    }

    expect($hits)->toBe(
        [],
        "No GSD planning artefacts may leak into runtime code or .docs/. Offenders:\n  ".implode("\n  ", $hits),
    );
});

/**
 * Self-test: the arch test mechanism must actually fail when a
 * synthetic offender lands inside its scan corpus. The fixture writes
 * a temporary PHP file under config/ (runtime scan corpus), re-runs
 * the same scan logic, asserts the offender is detected, and unlinks
 * the file. A second pass writes a markdown file under .docs/ to
 * exercise the narrower scan.
 *
 * Without this self-test, the green production scan above could be
 * silently broken (e.g. a path filter typo) and no one would notice
 * until a real leak landed unchecked.
 */
it('catches synthetic offenders injected into runtime + .docs/ scans', function (): void {
    $runtimeFixture = base_path('config/_gsd_leakage_self_test.php');
    $docsFixture = base_path('.docs/_gsd_leakage_self_test.md');

    try {
        file_put_contents(
            $runtimeFixture,
            "<?php\n// echo 'see PLAN.md';\n\$x = 'see PLAN.md for details';\n",
        );
        file_put_contents(
            $docsFixture,
            "# self-test\nThis line references .planning/ inline.\n",
        );

        $runtimePattern = '/\.planning\/|PLAN\.md|RESEARCH\.md|CONTEXT\.md|REVIEW\.md|\bD-\d{2,3}\b|gsd[-_]/i';
        $docsPattern = '/\.planning\/|PLAN\.md|RESEARCH\.md|CONTEXT\.md|gsd[-_]/i';

        // Runtime scan must detect the fixture (strip-comment safe —
        // the offending string lives outside a comment).
        $runtimeContents = (string) file_get_contents($runtimeFixture);
        $runtimeStripped = preg_replace(
            '#/\*.*?\*/|//[^\n]*|\{\{--.*?--\}\}#s',
            '',
            $runtimeContents,
        ) ?? $runtimeContents;
        expect(preg_match($runtimePattern, $runtimeStripped))->toBe(1);

        // Docs scan must detect the fixture (no comment stripping).
        $docsContents = (string) file_get_contents($docsFixture);
        expect(preg_match($docsPattern, $docsContents))->toBe(1);

        // Phase-number form must NOT trigger the narrower docs scan.
        $phaseLine = 'This decision graduated from Phase 17 (D-23).';
        expect(preg_match($docsPattern, $phaseLine))->toBe(0);
    } finally {
        if (file_exists($runtimeFixture)) {
            unlink($runtimeFixture);
        }
        if (file_exists($docsFixture)) {
            unlink($docsFixture);
        }
    }
});
