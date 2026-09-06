<?php

declare(strict_types=1);

// iOS embeds PHP with no interpreter binary on disk, so CommandSpawner::start()
// can never spawn there. Four of its five call sites answered that; the
// arg-prompt modal did not, and every dev command run from the phone's palette
// was a 500. A new call site inherits the same trap without this.

const SPAWN_UNAVAILABLE_EXCEPTION = 'ProcessSpawningUnavailableException';

/**
 * The lines of every `->start(` in $source that no `try` catching $exception
 * encloses, and how many call sites were looked at. Named and taking a source
 * string so the control below drives the same reader the walk drives.
 *
 * @return array{unguarded: list<int>, calls: int}
 */
function spawnCallsOutsideAPlatformCatch(string $source, string $exception): array
{
    // Brace-match each `try {` to its close, then read the catch clauses
    // that follow it. Counting braces rather than matching a pattern:
    // a regex over a body this size is one growth spurt away from
    // PREG_JIT_STACKLIMIT_ERROR, which reports "no offenders" and passes.
    $guarded = [];
    $at = 0;
    $length = strlen($source);

    while (($at = strpos($source, 'try {', $at)) !== false) {
        $depth = 0;
        $end = $at;

        for ($i = $at; $i < $length; $i++) {
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
        if (str_contains(substr($source, $end, 400), $exception)) {
            $guarded[] = [$at, $end];
        }

        $at = $end === $at ? $at + 1 : $end;
    }

    $unguarded = [];
    $calls = 0;
    $offset = 0;

    while (($call = strpos($source, '->start(', $offset)) !== false) {
        $offset = $call + 1;
        $calls++;

        foreach ($guarded as [$open, $close]) {
            if ($call > $open && $call < $close) {
                continue 2;
            }
        }

        $unguarded[] = substr_count(substr($source, 0, $call), "\n") + 1;
    }

    return ['unguarded' => $unguarded, 'calls' => $calls];
}

it('wraps every CommandSpawner::start() in a catch for the platform that cannot spawn', function (): void {
    /** @var list<string> $files */
    $files = [];
    /** @var Iterator<SplFileInfo> $found */
    $found = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'))),
        '/\.php$/',
    );
    foreach ($found as $file) {
        $path = $file->getPathname();
        // Only files that hold a spawner can be calling ITS start(); every
        // other `->start(` in Modules belongs to some unrelated collaborator.
        if (! str_contains($path, '/tests/') && str_contains((string) file_get_contents($path), 'CommandSpawner')) {
            $files[] = $path;
        }
    }

    // Six files name the spawner today, between them four `->start(` sites.
    // A walk that reached none of them reports every call site guarded.
    expect(count($files))->toBeGreaterThan(
        2,
        'No file names CommandSpawner at all, so this rule read no call site.'
    );

    $offenders = [];
    $calls = 0;

    foreach ($files as $path) {
        $read = spawnCallsOutsideAPlatformCatch((string) file_get_contents($path), SPAWN_UNAVAILABLE_EXCEPTION);
        $calls += $read['calls'];

        foreach ($read['unguarded'] as $line) {
            $offenders[] = str_replace(base_path().'/', '', $path).':'.$line;
        }
    }

    expect($calls)->toBeGreaterThan(
        1,
        'No ->start() call was found at all, so this rule checked nothing.'
    );

    expect($offenders)->toBe([], implode("\n  ", [
        'These spawn a process without catching the platform that cannot spawn one:',
        ...$offenders,
        '',
        'iOS embeds PHP with no interpreter binary on disk, so CommandSpawner::start()',
        'can never succeed there and every dev command run from the phone is a 500.',
        'Wrap the call in a try/'.SPAWN_UNAVAILABLE_EXCEPTION.' and tell the reader',
        'the command cannot run on this device.',
    ]));
});

// The reader is what the rule above gets its verdict from, and a brace matcher
// that found no try block would report every call site as guarded — or, having
// found no call site at all, report a clean tree.
it('sees a spawn outside the catch and credits one inside it', function (): void {
    $source = <<<'PHP'
        <?php
        final class Planted
        {
            public function guarded(): void
            {
                try {
                    $this->spawner->start($command);
                } catch (ProcessSpawningUnavailableException) {
                    $this->tell('This device cannot run commands.');
                }
            }

            public function bare(): void
            {
                $this->spawner->start($command);
            }

            public function wrongCatch(): void
            {
                try {
                    $this->spawner->start($command);
                } catch (RuntimeException $e) {
                    report($e);
                }
            }
        }
        PHP;

    expect(spawnCallsOutsideAPlatformCatch($source, 'ProcessSpawningUnavailableException'))->toBe([
        'unguarded' => [15, 21],
        'calls' => 3,
    ]);
});
