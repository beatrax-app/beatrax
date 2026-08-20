<?php

declare(strict_types=1);

/*
 * Livewire evaluates a wire:click expression against the $wire proxy, so a
 * bare `document` in it resolves to `$wire.document` — undefined — and the
 * call throws before the method is reached.
 *
 * /counterparties/triage did exactly that: "Label opslaan" threw
 * "$wire.document.getElementById is not a function" on every click, with no
 * toast and no on-screen error, and the counterparties table stayed at 35 rows.
 * The two inputs carried no wire:model, which is why the blade reached into
 * the DOM in the first place.
 *
 * Browser globals belong in an Alpine expression (x-on:click), where normal JS
 * scope applies.
 */

it('never reaches for a browser global from a wire: expression', function (): void {
    $offenders = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || ! str_ends_with($path, '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        // wire:click, wire:submit, wire:change … the whole family shares the
        // $wire scope.
        preg_match_all('/wire:[a-z.]+(?:\.[a-z]+)*="([^"]*)"/', $source, $matches);

        foreach ($matches[1] as $expression) {
            if (preg_match('/\b(document|window|navigator|localStorage|sessionStorage)\s*\./', $expression) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $path).' — '.mb_substr($expression, 0, 80);
            }
        }
    }

    expect($offenders)->toBe([], sprintf(
        "A wire: expression is evaluated against \$wire, so a browser global there is undefined — use x-on: instead:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
