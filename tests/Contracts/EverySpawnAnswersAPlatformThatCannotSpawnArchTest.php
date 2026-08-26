<?php

declare(strict_types=1);

// iOS embeds PHP with no interpreter binary on disk, so CommandSpawner::start()
// can never spawn there. Four of its five call sites answered that; the
// arg-prompt modal did not, and every dev command run from the phone's palette
// was a 500. A new call site inherits the same trap without this.
it('wraps every CommandSpawner::start() in a catch for the platform that cannot spawn', function (): void {
    $exception = 'ProcessSpawningUnavailableException';

    /** @var list<string> $files */
    $files = [];
    /** @var Iterator<SplFileInfo> $found */
    $found = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'))),
        '/\.php$/',
    );
    foreach ($found as $file) {
        $path = $file->getPathname();
        if (! str_contains($path, '/tests/') && str_contains((string) file_get_contents($path), '->start(')) {
            $files[] = $path;
        }
    }
    expect($files)->not->toBe([]);

    $offenders = [];
    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        // Only files that hold a spawner can be calling ITS start(); every
        // other `->start(` in Modules belongs to some unrelated collaborator.
        if (! str_contains($source, 'CommandSpawner')) {
            continue;
        }

        // Brace-match each `try {` to its close, then read the catch clauses
        // that follow it. Counting braces rather than matching a pattern:
        // a regex over a body this size is one growth spurt away from
        // PREG_JIT_STACKLIMIT_ERROR, which reports "no offenders" and passes.
        $guarded = [];
        $at = 0;
        while (($at = strpos($source, 'try {', $at)) !== false) {
            $depth = 0;
            $end = $at;
            for ($i = $at; $i < strlen($source); $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }

            // The catch list runs from the try's close to the statement after
            // the last handler; reading to the next `{` after `catch` is enough
            // to see which types it names.
            $tail = substr($source, $end, 400);
            if (str_contains($tail, $exception)) {
                $guarded[] = [$at, $end];
            }
            $at = $end === $at ? $at + 1 : $end;
        }

        $offset = 0;
        while (($call = strpos($source, '->start(', $offset)) !== false) {
            $offset = $call + 1;

            $covered = false;
            foreach ($guarded as [$open, $close]) {
                if ($call > $open && $call < $close) {
                    $covered = true;
                    break;
                }
            }

            if (! $covered) {
                $line = substr_count(substr($source, 0, $call), "\n") + 1;
                $offenders[] = str_replace(base_path().'/', '', $path).':'.$line;
            }
        }
    }

    expect($offenders)->toBe([]);
});
