<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Modules\Core\Public\Support\PatternScan;

// The call graph out of every listener bound to a shell-delivered event, walked
// once for the two invariants that judge it. A shell event arrives on its own
// terms -- the Electron main process posts it with no cookie and no window, into
// a PHP process built for that one request -- so what such a listener may touch,
// and what it may keep, are both properties of everything it can reach.
final class ShellEventGraph
{
    // NotificationDeepLink is this repo's own class but arrives the same way:
    // the Notification builder hands its name to the shell, which posts it back
    // through notifyLaravel when the reader clicks the toast.
    private const string SHELL_DELIVERED = 'Modules\\Desktop\\Public\\Events\\NotificationDeepLink';

    /**
     * @return array<string, string> reached class => the shell-event listener it was reached from
     */
    public static function reach(): array
    {
        $listeners = self::listenerMap();
        $bindings = self::bindingMap();

        $reached = [];
        $queue = [];
        foreach (self::seeds() as $seed) {
            $queue[] = [$seed, $seed];
        }

        while ($queue !== []) {
            [$class, $seed] = array_shift($queue);
            if (isset($reached[$class])) {
                continue;
            }
            $reached[$class] = $seed;

            foreach (self::firstPartyImports($class) as $import) {
                $queue[] = [$import, $seed];

                // Two edges no import scan has of its own: an event this class
                // dispatches runs its listeners inside the very same request, and a
                // contract it depends on is really the class bound to it.
                foreach (array_merge($listeners[$import] ?? [], $bindings[$import] ?? []) as $downstream) {
                    $queue[] = [$downstream, $seed];
                }
            }
        }

        return $reached;
    }

    /**
     * The listeners bound directly to an event the shell delivers.
     *
     * @return list<string>
     */
    public static function seeds(): array
    {
        $seeds = [];
        foreach (self::listenerMap() as $event => $bound) {
            if (str_starts_with($event, 'Native\\') || $event === self::SHELL_DELIVERED) {
                $seeds = array_merge($seeds, $bound);
            }
        }

        return array_values(array_unique($seeds));
    }

    /**
     * Every `$events->listen(Event::class, [Listener::class, …])` any module declares.
     *
     * @return array<string, list<string>>
     */
    public static function listenerMap(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = [];

        foreach (self::providerFiles() as $file) {
            $source = self::strippedSource((string) file_get_contents($file));
            $imports = self::importAliases($source);

            // Both shapes the framework accepts for a class handler: the
            // [Listener::class, 'method'] pair and a bare invokable.
            foreach (PatternScan::sets('/->listen\(\s*([A-Za-z0-9_\\\\]+)::class\s*,\s*\[?\s*([A-Za-z0-9_\\\\]+)::class/s', $source) as $binding) {
                $event = $imports[$binding[1]] ?? $binding[1];
                $map[$event][] = $imports[$binding[2]] ?? $binding[2];
            }
        }

        return $map;
    }

    /**
     * Every `->bind(Contract::class, Concrete::class)` any module declares.
     *
     * @return array<string, list<string>>
     */
    public static function bindingMap(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = [];

        foreach (self::providerFiles() as $file) {
            $source = self::strippedSource((string) file_get_contents($file));
            $imports = self::importAliases($source);

            foreach (PatternScan::sets('/->(?:bind|singleton|scoped)\(\s*([A-Za-z0-9_\\\\]+)::class\s*,\s*([A-Za-z0-9_\\\\]+)::class\s*\)/s', $source) as $binding) {
                $map[$imports[$binding[1]] ?? $binding[1]][] = $imports[$binding[2]] ?? $binding[2];
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public static function firstPartyImports(string $class): array
    {
        $file = self::classFile($class);
        if ($file === null) {
            return [];
        }

        $source = self::strippedSource((string) file_get_contents($file));

        return PatternScan::all('/^use\s+((?:Modules|App)\\\\[A-Za-z0-9_\\\\]+);/m', $source)[1] ?? [];
    }

    public static function classFile(string $class): ?string
    {
        // Models and jobs are leaves. An Eloquent class drags in every global scope
        // of every trait it uses, and BelongsToUser's names CurrentUser, which would
        // report every writer of every owned table. A job runs on the worker, in a
        // request this one never becomes.
        if (str_contains($class, '\\Models\\') || str_contains($class, '\\Jobs\\')) {
            return null;
        }

        $path = match (true) {
            str_starts_with($class, 'Modules\\') => base_path(str_replace('\\', '/', $class).'.php'),
            str_starts_with($class, 'App\\') => base_path('app/'.str_replace('\\', '/', substr($class, 4)).'.php'),
            default => null,
        };

        return is_string($path) && is_file($path) ? $path : null;
    }

    /**
     * @return array<string, string>
     */
    public static function importAliases(string $source): array
    {
        $aliases = [];

        foreach (PatternScan::sets('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?;/m', $source) as $import) {
            $tail = strrchr('\\'.$import[1], '\\');
            $aliases[($import[2] ?? '') !== '' ? $import[2] : substr((string) $tail, 1)] = $import[1];
        }

        return $aliases;
    }

    public static function strippedSource(string $source): string
    {
        return PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
    }

    /**
     * @return list<string>
     */
    public static function providerFiles(): array
    {
        return array_values(glob(base_path('Modules/*/Providers/*.php')) ?: []);
    }
}
