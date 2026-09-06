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
    $components = livewireComponentFiles();
    $viewsRead = 0;

    expect(count($components))->toBeGreaterThan(
        100,
        'The walk found almost no Livewire component, so the empty offender list below is a tree nobody read.',
    );

    foreach ($components as $componentPath) {
        $source = (string) file_get_contents($componentPath);

        if (preg_match('/function\s+updated[A-Za-z]*\s*\(/', $source) !== 1) {
            continue;
        }

        foreach (viewsRenderedBy($componentPath) as $viewPath) {
            $view = (string) file_get_contents($viewPath);
            $viewsRead++;

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

    // The component-to-view hop is a name lookup, and a renamed view resolves to
    // nothing without saying so — which reads exactly like a component with no
    // deferred binding in it.
    expect($viewsRead)->toBeGreaterThan(
        5,
        'Almost no view was resolved from a component that declares an updated() hook, so the verdict below is about markup nobody opened.',
    );

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
    $hooksRead = 0;

    foreach (livewireComponentFiles() as $componentPath) {
        $source = (string) file_get_contents($componentPath);

        $hooks = PatternScan::all('/function\s+updated([A-Z][A-Za-z0-9]*)\s*\(/', $source);
        $hooksRead += count($hooks[1]);

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

    expect($hooksRead)->toBeGreaterThan(
        4,
        'No property-specific updated() hook was found in any component, so this rule compared nothing against any binding.',
    );

    expect(array_values(array_unique($offenders)))->toBe([], implode("\n", [
        'These fields are bound so the hook that validates them cannot run until the next round trip,',
        'which is usually the submit the reader was trying to avoid. A goal refused for its date kept',
        'saying so after a date was chosen, with aria-invalid still true:',
        ...array_values(array_unique($offenders)),
        '',
        'Spell the binding wire:model.live (or .live.blur) wherever an updated<Property>() hook has to',
        'see the value the reader just typed.',
    ]));
});

// Both verdicts above are read off lists that are empty on a clean tree and on a
// reader that stopped. This plants each answer the binding reader has to give.
it('reads a deferred binding on the property a hook watches, and leaves a live one alone', function (): void {
    expect(deferredBindingOn(['wire:model' => 'targetDate'], 'targetDate'))
        ->toBe(['targetDate'], 'a plain wire:model on the watched property went unreported');

    expect(deferredBindingOn(['wire:model.blur' => 'targetDate'], 'targetDate'))
        ->toBe(['targetDate.blur'], 'a blur-only binding on the watched property went unreported');

    expect(deferredBindingOn(['wire:model.live.blur' => 'targetDate'], 'targetDate'))
        ->toBe([], 'a live binding reaches the server and must not be reported');

    expect(deferredBindingOn(['wire:model' => 'otherField'], 'targetDate'))
        ->toBe([], 'a binding on a property no hook watches was read as one that is watched');

    expect(deferredBindingOn(['wire:submit' => 'save'], 'save'))
        ->toBe([], 'an attribute that is not a wire:model binding was read as one');
});
