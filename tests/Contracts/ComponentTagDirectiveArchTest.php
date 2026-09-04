<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-blade-directive-inside-a-component-tag
 */

/** @return list<string> */
function componentTagBladeFiles(): array
{
    $files = [];

    foreach (['Modules', 'resources/views'] as $root) {
        $dir = base_path($root);

        if (! is_dir($dir)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $it */
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('never puts a Blade directive inside a component tag', function (): void {
    $offenders = [];

    foreach (componentTagBladeFiles() as $path) {
        $source = file_get_contents($path);

        if ($source === false) {
            continue;
        }

        // Opening component tags only, up to the first `>`. Non-greedy so a
        // later tag on the same file cannot swallow the text between them.
        $matches = PatternScan::allWithOffsets('/<x-[a-zA-Z0-9_.:-]+((?:[^>])*?)\/?>/s', $source);

        foreach ($matches[1] as $index => $attributes) {
            if (preg_match('/@(if|unless|else|elseif|endif|endunless|foreach|endforeach|for|endfor|isset|empty)\b/', (string) $attributes[0]) !== 1) {
                continue;
            }

            $line = substr_count(substr($source, 0, (int) $matches[0][$index][1]), "\n") + 1;
            $offenders[] = str_replace(base_path().'/', '', $path).':'.$line;
        }
    }

    expect($offenders)->toBe([], 'Blade emits these component tags as raw HTML, so the component never renders: '.implode(', ', $offenders));
});
