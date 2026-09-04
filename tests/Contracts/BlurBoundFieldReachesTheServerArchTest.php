<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#wiremodelblur-never-reaches-the-server
 */

/** @return list<string> */
function livewireComponentFiles(): array
{
    $files = [];
    $dir = base_path('Modules');

    /** @var iterable<SplFileInfo> $it */
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ($it as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        if (! str_contains($file->getPathname(), '/Http/Livewire/')) {
            continue;
        }
        $files[] = $file->getPathname();
    }

    sort($files);

    return $files;
}

// A component names its view; the view lives under some module's
// Resources/views/livewire, and which module is not derivable from the
// namespace alias, so the file is looked up by name.
/** @return list<string> */
function viewsRenderedBy(string $componentPath): array
{
    $source = (string) file_get_contents($componentPath);

    $matches = PatternScan::all("/'[a-z0-9\-]+::livewire\.([a-z0-9\-.]+)'/", $source);

    $paths = [];
    foreach ($matches[1] as $name) {
        foreach (glob(base_path('Modules/*/Resources/views/livewire/'.str_replace('.', '/', $name).'.blade.php')) ?: [] as $found) {
            $paths[] = $found;
        }
    }

    return array_values(array_unique($paths));
}

it('never binds a field with wire:model.blur in a component whose updated() hook has to see it', function (): void {
    $offenders = [];

    foreach (livewireComponentFiles() as $componentPath) {
        $source = (string) file_get_contents($componentPath);

        if (preg_match('/function\s+updated[A-Za-z]*\s*\(/', $source) !== 1) {
            continue;
        }

        foreach (viewsRenderedBy($componentPath) as $viewPath) {
            $view = (string) file_get_contents($viewPath);

            $matches = PatternScan::all('/wire:model((?:\.[\w]+)*)\.blur/', $view);

            foreach ($matches[1] as $leading) {
                if (str_contains($leading, 'live')) {
                    continue;
                }

                $offenders[] = str_replace(base_path().'/', '', $viewPath)
                    .' ← '.str_replace(base_path().'/', '', $componentPath);
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe(
        [],
        "In Livewire 4 `wire:model.blur` is an EPHEMERAL modifier: it syncs the client-side\n".
        "\$wire proxy on blur and sends no request, so an updated() hook on the server never\n".
        "runs. Spell it `wire:model.live.blur` where the hook has to see the new value.\n  "
        .implode("\n  ", array_unique($offenders)),
    );
});

// The tag a binding sits on decides whether the modifier means what it reads
// as. On a `flux:modal` the component rewrites the directive to `.self` and
// owns its own open/close sync, so the same string there is not the same
// promise. Only fields the reader types into are judged here.
/** @return list<string> */
function deferredFieldBindings(string $view, string $property): array
{
    $bindings = [];

    foreach (MarkupSource::tags($view) as $element) {
        $isField = in_array($element->name, ['input', 'select', 'textarea'], true)
            || str_starts_with($element->name, 'x-core::');

        if (! $isField) {
            continue;
        }

        foreach (deferredBindingOn($element->attributes(), $property) as $binding) {
            $bindings[] = $binding;
        }
    }

    return $bindings;
}

/**
 * @param  array<string, string>  $attributes
 * @return list<string>
 */
function deferredBindingOn(array $attributes, string $property): array
{
    $bindings = [];

    foreach ($attributes as $name => $target) {
        $modifiers = $name === 'wire:model' ? '' : substr($name, strlen('wire:model'));

        if ($name !== 'wire:model' && ! str_starts_with($name, 'wire:model.')) {
            continue;
        }

        if (explode('.', $target)[0] !== $property || str_contains($modifiers, 'live')) {
            continue;
        }

        $bindings[] = $target.$modifiers;
    }

    return $bindings;
}

// A goal refused for its date kept saying so after a date was chosen: the sheet
// showed 26-08-2026 with "Kies een streefdatum." in red under it, and
// aria-invalid still true. `updatedTargetDate()` was already there and could not
// run, because a plain wire:model does not reach the server until the next
// round trip -- which was the submit the reader was trying to avoid.
it('binds every property an updated() hook watches so the hook can actually run', function (): void {
    $offenders = [];

    foreach (livewireComponentFiles() as $componentPath) {
        $source = (string) file_get_contents($componentPath);

        $hooks = PatternScan::all('/function\s+updated([A-Z][A-Za-z0-9]*)\s*\(/', $source);

        foreach (viewsRenderedBy($componentPath) as $viewPath) {
            $view = (string) file_get_contents($viewPath);

            foreach ($hooks[1] as $hook) {
                foreach (deferredFieldBindings($view, lcfirst($hook)) as $binding) {
                    $offenders[] = str_replace(base_path().'/', '', $viewPath)
                        .' wire:model="'.$binding.'" ← updated'.$hook.'()';
                }
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});
