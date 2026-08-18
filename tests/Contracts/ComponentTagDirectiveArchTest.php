<?php

declare(strict_types=1);

/*
 * A Blade component tag must not carry a Blade directive in its attribute
 * list.
 *
 * `<x-foo @if ($bad) aria-invalid="true" @endif />` does not error and does not
 * warn. Blade's component-tag compiler matches the tag with a regex over its
 * attributes, the directive defeats the match, and the tag is emitted into the
 * page VERBATIM as an unknown HTML element — which renders as nothing at all.
 *
 * It cost a shipped field. The goal edit form's target-date input was written
 * that way, and the modal went out with no date control in it: a label, then
 * empty space, then the next label. The page returned 200, no console error,
 * no failing test. It was found by reading the device's DOM and seeing a
 * literal `<x-core::date-input …>` element sitting in it.
 *
 * The fix is always the same shape — branch around the whole tag rather than
 * inside it — so this only has to say "not here".
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
        preg_match_all('/<x-[a-zA-Z0-9_.:-]+((?:[^>])*?)\/?>/s', $source, $matches, PREG_OFFSET_CAPTURE);

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
