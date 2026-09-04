<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;

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

        foreach (MarkupSource::tags($source) as $element) {
            if (! str_starts_with($element->name, 'x-')) {
                continue;
            }

            if (preg_match('/@(if|unless|else|elseif|endif|endunless|foreach|endforeach|for|endfor|isset|empty)\b/', $element->startTag) !== 1) {
                continue;
            }

            $offenders[] = str_replace(base_path().'/', '', $path).':'.$element->line($source);
        }
    }

    expect($offenders)->toBe([], 'Blade emits these component tags as raw HTML, so the component never renders: '.implode(', ', $offenders));
});
