<?php

declare(strict_types=1);

// `use PDO;` in a file with no namespace is a no-op PHP raises a warning for,
// and Pest turns that warning into an ErrorException at bootstrap. The whole
// parallel run then dies before a single test executes, printing no `Tests:`
// line at all -- so it does not read as a failing test, it reads as nothing
// having happened. A formatter added exactly this to a test file on this
// branch and the suite stopped running.
it('leaves no non-compound import in a test file that declares no namespace', function (): void {
    /** @var list<string> $files */
    $files = [];
    foreach (['Modules', 'tests'] as $root) {
        $dir = base_path($root);
        if (! is_dir($dir)) {
            continue;
        }
        /** @var Iterator<SplFileInfo> $found */
        $found = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
            '/Test\.php$|\/Pest\.php$/',
        );
        foreach ($found as $file) {
            $files[] = $file->getPathname();
        }
    }
    expect($files)->not->toBe([]);

    $offenders = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        // A namespaced test file resolves its own names, so an import there is
        // ordinary. Only the namespace-less ones carry the hazard.
        if (preg_match('/^namespace\s+\S+;/m', $source) === 1) {
            continue;
        }

        $tokens = token_get_all($source);
        $depth = 0;

        foreach ($tokens as $i => $token) {
            if ($token === '{') {
                $depth++;

                continue;
            }

            if ($token === '}') {
                $depth--;

                continue;
            }

            // Depth is what separates an import from a trait `use` inside a
            // class body -- and from a closure's `use (...)`, which is caught
            // by the paren below. Only a top-level one is the hazard.
            if ($depth !== 0 || ! is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            $name = '';
            for ($j = $i + 1; $j < count($tokens); $j++) {
                $next = $tokens[$j];

                if ($next === ';' || $next === '(') {
                    break;
                }

                if (is_array($next)) {
                    $name .= $next[1];
                }
            }

            $name = trim($name);

            // A grouped or aliased import always carries a separator; `use
            // function x` and `use const x` name a global symbol deliberately.
            if ($name === '' || str_contains($name, '\\')
                || str_starts_with($name, 'function ') || str_starts_with($name, 'const ')) {
                continue;
            }

            $offenders[] = str_replace(base_path().'/', '', $path).':'.$token[2].' — use '.$name.';';
        }
    }

    expect($offenders)->toBe([]);
});
