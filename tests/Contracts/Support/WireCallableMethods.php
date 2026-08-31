<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * @link ../../../.docs/conventions/a-public-livewire-method-is-a-public-endpoint.md
 */
final class WireCallableMethods
{
    /**
     * Livewire's own protocol: the framework calls these itself, so an
     * unreferenced one is not an unreachable feature.
     *
     * @var list<string>
     */
    private const LIFECYCLE = [
        'mount', 'render', 'boot', 'booted', 'hydrate', 'dehydrate',
        'updating', 'updated', 'rendering', 'rendered', 'exception', 'placeholder',
    ];

    /**
     * `updatedSomeProperty()` and its three siblings are the per-property
     * forms of the hooks above, and are just as much Livewire's to call.
     *
     * @var list<string>
     */
    private const LIFECYCLE_PREFIXES = ['updating', 'updated', 'hydrate', 'dehydrate'];

    /**
     * Every production Livewire component under Modules/.
     *
     * @return list<class-string<Component>>
     */
    public static function components(): array
    {
        $modulesRoot = base_path('Modules');
        $classes = [];

        foreach (self::filesUnder($modulesRoot, '.php') as $path) {
            if (! str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/Http/Livewire/')) {
                continue;
            }
            if (str_contains($path, '/tests/') || str_contains($path, '/Tests/')) {
                continue;
            }

            $relative = substr($path, strlen($modulesRoot) + 1, -strlen('.php'));
            $fqcn = 'Modules\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (! class_exists($fqcn)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);
            if (! $reflection->isSubclassOf(Component::class) || $reflection->isAbstract()) {
                continue;
            }

            /** @var class-string<Component> $fqcn */
            $classes[] = $fqcn;
        }

        sort($classes);

        return $classes;
    }

    /**
     * The methods a crafted `/livewire/update` payload could invoke, minus the
     * ones Livewire itself calls.
     *
     * @param  class-string<Component>  $component
     * @return list<ReflectionMethod>
     */
    public static function invokableOn(string $component): array
    {
        $methods = [];

        foreach ((new ReflectionClass($component))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $file = $method->getFileName();

            // Livewire's own base class and the framework's traits declare
            // plenty; only first-party code is this guard's business.
            if ($file === false || str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $file), '/vendor/')) {
                continue;
            }
            if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
                continue;
            }
            if (self::isLifecycle($method->getName()) || self::isFrameworkDriven($method)) {
                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * Every bare identifier appearing anywhere a template or a script can name
     * a wire action — `wire:click`, `x-on:click="$wire.foo()"`, an Alpine
     * expression, a `@js()` payload. Deliberately not a parse: a guard that
     * models Blade will miss the one spelling nobody thought of, and a missed
     * caller here is a false accusation of dead code.
     *
     * @return array<string, true>
     */
    public static function namesTemplatesCanReach(): array
    {
        $names = [];

        foreach ([base_path('Modules'), base_path('resources')] as $root) {
            foreach (['.blade.php', '.js'] as $extension) {
                foreach (self::filesUnder($root, $extension) as $path) {
                    if (str_contains($path, '/tests/') || str_contains($path, '/Tests/')) {
                        continue;
                    }

                    if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', (string) file_get_contents($path), $matches) === false) {
                        continue;
                    }

                    foreach ($matches[0] as $name) {
                        $names[$name] = true;
                    }
                }
            }
        }

        return $names;
    }

    /**
     * Method names production PHP calls or names. Both forms count: an undo
     * payload and a `$listeners` array reach a method through a string
     * literal, and neither looks like a call.
     *
     * @return array<string, true>
     */
    public static function namesProductionPhpReaches(): array
    {
        $names = [];

        foreach (BackendSourceFiles::all() as $path) {
            $source = (string) file_get_contents($path);

            foreach (['/(?:->|::)\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/', '/[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]/'] as $pattern) {
                if (preg_match_all($pattern, $source, $matches) === false) {
                    continue;
                }

                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return $names;
    }

    private static function isLifecycle(string $name): bool
    {
        if (in_array($name, self::LIFECYCLE, true)) {
            return true;
        }

        foreach (self::LIFECYCLE_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix) && ctype_upper($name[strlen($prefix)])) {
                return true;
            }
        }

        return false;
    }

    // #[On] is a subscription and #[Computed] is a property in method form;
    // both are called by Livewire, not by a caller a grep could find.
    private static function isFrameworkDriven(ReflectionMethod $method): bool
    {
        return $method->getAttributes(On::class) !== [] || $method->getAttributes(Computed::class) !== [];
    }

    /**
     * @return list<string>
     */
    private static function filesUnder(string $root, string $extension): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $paths = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), $extension)) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }
}
