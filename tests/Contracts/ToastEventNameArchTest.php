<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

/**
 * A Livewire dispatch names a browser event, and nothing checks that anything
 * listens. `toast.show` was dispatched for the "suggestion sent" confirmation
 * against hosts that bind `toast`, so the toast simply never appeared and the
 * test asserting the dispatch passed the whole time. One name, checked here.
 */
const TOAST_EVENT = 'toast';

/**
 * @param  list<string>  $paths
 * @return list<string> one entry per dispatch of a toast-ish event by another name
 */
function toastDispatchesByAnotherName(array $paths): array
{
    $hits = [];

    foreach ($paths as $path) {
        if (str_ends_with($path, '.blade.php')) {
            $hits = [...$hits, ...toastDispatchesInMarkup($path)];

            continue;
        }

        $tokens = BackendSourceFiles::codeTokens($path);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'dispatch') {
                continue;
            }

            $name = toastFirstStringArgument($tokens, $index);
            if ($name === null || ! str_starts_with($name, TOAST_EVENT) || $name === TOAST_EVENT) {
                continue;
            }

            $hits[] = "{$path}:{$token[2]} dispatches '{$name}'";
        }
    }

    return $hits;
}

/** @return list<string> */
function toastDispatchesInMarkup(string $path): array
{
    $contents = (string) file_get_contents($path);
    $hits = [];

    // Blade is not tokenizable as PHP, and a directive can carry a dispatch in
    // an attribute as easily as in an @php block.
    $matches = PatternScan::all('/dispatch\(\s*[\'"]('.TOAST_EVENT.'[A-Za-z0-9_.:-]+)[\'"]/', $contents);

    foreach ($matches[1] as $name) {
        $hits[] = "{$path} dispatches '{$name}'";
    }

    return $hits;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return string|null the literal first argument, or null when it is not a literal
 */
function toastFirstStringArgument(array $tokens, int $index): ?string
{
    if (($tokens[$index + 1] ?? null) !== '(') {
        return null;
    }

    $first = $tokens[$index + 2] ?? null;

    return is_array($first) && $first[0] === T_CONSTANT_ENCAPSED_STRING
        ? trim($first[1], "'\"")
        : null;
}

/**
 * A class that uses DispatchesToast and then declares its own toast() wins over
 * the trait's, silently. Every call site still reads `$this->toast(...)`, so
 * whatever the private one does instead is invisible where it is called.
 *
 * @param  list<string>  $paths
 * @return list<string> one entry per redeclared seam method
 */
function toastSeamMethodsRedeclared(array $paths): array
{
    $hits = [];

    foreach ($paths as $path) {
        $tokens = BackendSourceFiles::codeTokens($path);

        // Exempt for declaring the methods, not for its path: the file that
        // declares the trait names it too, so `toastUsesTheTrait()` is true of
        // it and its own toast() would read as a shadow of itself. Keyed on the
        // declaration so moving or renaming the file cannot quietly widen this.
        if (toastDeclaresTheTrait($tokens) || ! toastUsesTheTrait($tokens)) {
            continue;
        }

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $name = $tokens[$index + 2] ?? null;
            if (is_array($name) && in_array($name[1], ['toast', 'toastWithUndo'], true)) {
                $hits[] = "{$path}:{$name[2]} redeclares {$name[1]}()";
            }
        }
    }

    return $hits;
}

/**
 * The import is a qualified name; only the in-class `use DispatchesToast;`
 * leaves the bare word behind.
 *
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function toastUsesTheTrait(array $tokens): bool
{
    foreach ($tokens as $token) {
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'DispatchesToast') {
            return true;
        }
    }

    return false;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function toastDeclaresTheTrait(array $tokens): bool
{
    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_TRAIT) {
            continue;
        }

        // Every trait in the file, not the first one: a file declaring another
        // trait above this one would otherwise answer for that one and stop.
        foreach (array_slice($tokens, $index + 1, 4) as $following) {
            if (! is_array($following) || $following[0] !== T_STRING) {
                continue;
            }
            if ($following[1] === 'DispatchesToast') {
                return true;
            }

            break;
        }
    }

    return false;
}

/** @return list<string> backend PHP plus every Blade template */
function toastScannedFiles(): array
{
    $blades = [];

    foreach ([base_path('Modules'), base_path('resources')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')) {
                $blades[] = $file->getPathname();
            }
        }
    }

    sort($blades);

    return [...BackendSourceFiles::all(), ...$blades];
}

it('dispatches every toast under the one name the hosts listen for', function (): void {
    $files = toastScannedFiles();

    // 6,471 backend files and 279 templates today. Floored well under both: a
    // walk that lost a root reports the same empty offender list a clean tree
    // reports, and nothing else here would look wrong.
    expect(count($files))->toBeGreaterThan(2000, 'the walk read almost nothing — the roots are wrong, not the tree.');

    expect(toastDispatchesByAnotherName($files))->toBe(
        [],
        "A toast dispatched under any other name reaches no listener and is simply\n".
        "invisible. Raise it through DispatchesToast, or dispatch '".TOAST_EVENT."' exactly.\n".
        'Offenders:',
    );
});

it('binds that exact name in the global toast host', function (): void {
    // The other half of the pair: the guard above is only worth something while
    // the host still listens for the name it enforces.
    $host = (string) file_get_contents(base_path('Modules/Core/Resources/views/components/toast-host.blade.php'));

    expect(str_contains($host, 'x-on:'.TOAST_EVENT.'.window'))->toBeTrue(
        'The global toast host no longer binds `'.TOAST_EVENT.'` on the window, so every dispatch '
        .'the rule above enforces reaches nothing and the guard is enforcing a name nobody hears.',
    );
});

it('sees a toast dispatched under a near-miss name', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'toast-name').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        final class PlantedToastDispatch
        {
            public function run(): void
            {
                $this->dispatch('toast', message: 'fine');
                $this->dispatch('toast.show', message: 'invisible');
                $this->dispatch('modal-close', name: 'x');
            }
        }
        PHP);

    try {
        $found = toastDispatchesByAnotherName([$planted]);
    } finally {
        @unlink($planted);
    }

    expect($found)->toHaveCount(1, 'The reader must flag `toast.show` and leave `toast` and an unrelated event alone.');
    expect(str_contains($found[0], "dispatches 'toast.show'"))->toBeTrue('The reader flagged a dispatch, but not the near-miss name.');
});

it('lets no component shadow the toast seam it uses', function (): void {
    $files = BackendSourceFiles::all();

    expect(count($files))->toBeGreaterThan(2000, 'the backend walk read almost nothing — the roots are wrong, not the tree.');

    // Thirty production files name the trait today. A tokeniser that stopped
    // resolving the bare `use DispatchesToast;` empties this while the offender
    // list below stays every bit as empty as a clean tree's.
    $using = array_values(array_filter(
        $files,
        static fn (string $path): bool => toastUsesTheTrait(BackendSourceFiles::codeTokens($path)),
    ));

    expect(count($using))->toBeGreaterThan(10, 'almost nothing was read as using DispatchesToast — the token walk is broken, not the components.');

    expect(toastSeamMethodsRedeclared($files))->toBe(
        [],
        "A private toast() beside `use DispatchesToast` overrides the trait without\n".
        "saying so at any call site. Call the trait method, or give yours a name that\n".
        'describes what it actually does. Offenders:',
    );
});

it('sees a component that shadows the trait method', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'toast-shadow').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
        final class PlantedToastShadow
        {
            use DispatchesToast;

            private function toast(string $message): void
            {
                $this->toastWithUndo($message, undoAction: '', undoPayload: null);
            }
        }
        PHP);

    try {
        $found = toastSeamMethodsRedeclared([$planted]);
    } finally {
        @unlink($planted);
    }

    expect($found)->toHaveCount(1, 'The reader must flag the redeclared toast() and nothing else in the planted class.');
    expect(str_contains($found[0], 'redeclares toast()'))->toBeTrue('The reader flagged a method, but not the one shadowing the trait.');
});

it('exempts the trait for declaring the methods, not for the path it sits at', function (): void {
    $declaring = tempnam(sys_get_temp_dir(), 'toast-trait').'.php';
    file_put_contents($declaring, <<<'PHP'
        <?php
        trait DispatchesToast
        {
            protected function toast(string $message): void {}

            protected function toastWithUndo(string $message, string $undoAction, mixed $undoPayload): void {}
        }
        PHP);

    try {
        $found = toastSeamMethodsRedeclared([$declaring]);
    } finally {
        @unlink($declaring);
    }

    // The file is named nothing like Concerns/DispatchesToast.php: the old
    // exemption was a path, and a moved trait would have failed against itself.
    expect($found)->toBe([], 'The declaration of the trait reads as a shadow of itself.');
});
