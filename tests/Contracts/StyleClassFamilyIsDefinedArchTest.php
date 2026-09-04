<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

it('defines every class a Blade applies out of a family app.css names', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $cssMatches = PatternScan::all('~\.([a-z][a-z0-9]*(?:-[a-z0-9]+)*)~', $css);
    /** @var array<string,int> $defined */
    $defined = array_flip(array_values(array_unique($cssMatches[1])));
    $definedNames = array_keys($defined);

    $blades = [];
    foreach ([base_path('Modules'), base_path('resources')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')) {
                $blades[] = $file->getPathname();
            }
        }
    }
    sort($blades);

    $offenders = [];
    foreach ($blades as $path) {
        $source = (string) file_get_contents($path);
        $attributes = PatternScan::allWithOffsets('~\bclass="([^"]*)"~', $source);

        foreach ($attributes[1] as [$value, $offset]) {
            // Interpolated halves are dropped rather than guessed at: a token
            // assembled at render time is not a literal this file can resolve.
            $literal = preg_replace('~\{\{.*?\}\}|\{!!.*?!!\}~s', ' ', $value) ?? $value;

            foreach (preg_split('~\s+~', trim($literal)) ?: [] as $token) {
                if ($token === '' || isset($defined[$token])) {
                    continue;
                }

                // Only multi-segment names, because Tailwind's own bare words
                // (flex, hidden, grid) collide with app family stems and the
                // framework — not app.css — is what defines those.
                if (preg_match('~^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$~', $token) !== 1) {
                    continue;
                }

                foreach ($definedNames as $name) {
                    if (str_starts_with($name, $token.'-')) {
                        $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                        $offenders[] = str_replace(base_path().'/', '', $path).':'.$line.'  .'.$token;
                        break;
                    }
                }
            }
        }
    }

    $offenders = array_values(array_unique($offenders));

    expect($offenders)->toBe([], implode("\n", [
        'These Blade class tokens name a member of a family app.css defines,',
        'but app.css defines no rule for the token itself, so the control is',
        'painted by nothing:',
        ...$offenders,
        '',
        'Either add the rule to resources/css/app.css — theme-aware, so it',
        'holds in light and dark — or apply the class that already exists.',
    ]));
});
