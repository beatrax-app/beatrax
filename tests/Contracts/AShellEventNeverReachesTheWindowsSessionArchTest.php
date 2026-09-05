<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// A native shell event is delivered by the shell's OWN process. NativePHP's
// notifyLaravel() posts it over axios with an X-NativePHP-Secret header and no
// cookie jar, onto a route the package loads with loadRoutesFrom() and so
// without the web group -- no StartSession, no auth guard. A PHP listener bound
// to such an event therefore cannot hold the window's session on ANY route,
// which is a property of the transport rather than a bug in one handler. Two of
// them wrote session state anyway: the app-lock listener, whose lock nobody
// could see, and the OS file-open intent, which was remembered nowhere.

/**
 * @return array<string, string> reached class => the shell-event listener it was reached from
 */
function shellEventReach(): array
{
    $listeners = shellEventListenerMap();

    $reached = [];
    $queue = [];
    foreach (shellEventSeeds($listeners) as $seed) {
        $queue[] = [$seed, $seed];
    }

    while ($queue !== []) {
        [$class, $seed] = array_shift($queue);
        if (isset($reached[$class])) {
            continue;
        }
        $reached[$class] = $seed;

        $bindings = shellEventBindingMap();

        foreach (shellEventFirstPartyImports($class) as $import) {
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
 * Every `$events->listen(Event::class, [Listener::class, …])` any module declares.
 *
 * @return array<string, list<string>>
 */
function shellEventListenerMap(): array
{
    $map = [];

    foreach (glob(base_path('Modules/*/Providers/*.php')) ?: [] as $file) {
        $source = shellEventStrippedSource((string) file_get_contents($file));
        $imports = shellEventImportAliases($source);

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
 * @param  array<string, list<string>>  $listeners
 * @return list<string>
 */
function shellEventSeeds(array $listeners): array
{
    // NotificationDeepLink is this repo's own class but arrives the same way:
    // the Notification builder hands its name to the shell, which posts it back
    // through notifyLaravel when the reader clicks the toast.
    $shellDelivered = ['Modules\\Desktop\\Public\\Events\\NotificationDeepLink'];

    $seeds = [];
    foreach ($listeners as $event => $bound) {
        if (str_starts_with($event, 'Native\\') || in_array($event, $shellDelivered, true)) {
            $seeds = array_merge($seeds, $bound);
        }
    }

    return array_values(array_unique($seeds));
}

/**
 * Every `->bind(Contract::class, Concrete::class)` any module declares.
 *
 * @return array<string, list<string>>
 */
function shellEventBindingMap(): array
{
    static $map = null;

    if ($map !== null) {
        return $map;
    }

    $map = [];

    foreach (glob(base_path('Modules/*/Providers/*.php')) ?: [] as $file) {
        $source = shellEventStrippedSource((string) file_get_contents($file));
        $imports = shellEventImportAliases($source);

        foreach (PatternScan::sets('/->(?:bind|singleton|scoped)\(\s*([A-Za-z0-9_\\\\]+)::class\s*,\s*([A-Za-z0-9_\\\\]+)::class\s*\)/s', $source) as $binding) {
            $map[$imports[$binding[1]] ?? $binding[1]][] = $imports[$binding[2]] ?? $binding[2];
        }
    }

    return $map;
}

/**
 * @return list<string>
 */
function shellEventFirstPartyImports(string $class): array
{
    $file = shellEventClassFile($class);
    if ($file === null) {
        return [];
    }

    $source = shellEventStrippedSource((string) file_get_contents($file));

    return PatternScan::all('/^use\s+((?:Modules|App)\\\\[A-Za-z0-9_\\\\]+);/m', $source)[1] ?? [];
}

function shellEventClassFile(string $class): ?string
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
function shellEventImportAliases(string $source): array
{
    $aliases = [];

    foreach (PatternScan::sets('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?;/m', $source) as $import) {
        $tail = strrchr('\\'.$import[1], '\\');
        $aliases[($import[2] ?? '') !== '' ? $import[2] : substr((string) $tail, 1)] = $import[1];
    }

    return $aliases;
}

function shellEventStrippedSource(string $source): string
{
    return PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
}

it('reaches the shell-event listeners it is written to judge', function (): void {
    $reach = shellEventReach();

    expect(shellEventSeeds(shellEventListenerMap()))->not->toBeEmpty(
        'No listener matched. The binding syntax this scans for has changed, '.
        'and a guard that finds nothing reports nothing.',
    );
    expect($reach)->toHaveKey('Modules\\Desktop\\Internal\\Listeners\\HandleNativeOpenFile');
    expect($reach)->toHaveKey('Modules\\Desktop\\Internal\\Native\\FileOpenHandoff');
});

it('never hands a shell event to a closure, which no walk can follow', function (): void {
    $closures = [];

    foreach (glob(base_path('Modules/*/Providers/*.php')) ?: [] as $file) {
        $source = shellEventStrippedSource((string) file_get_contents($file));
        $imports = shellEventImportAliases($source);

        foreach (PatternScan::sets('/->listen\(\s*([A-Za-z0-9_\\\\]+)::class\s*,\s*(?:static\s+)?(?:function|fn)\b/s', $source) as $binding) {
            $event = $imports[$binding[1]] ?? $binding[1];

            if (str_starts_with($event, 'Native\\')) {
                $closures[] = basename($file).' hands '.$event.' to a closure';
            }
        }
    }

    expect($closures)->toBe([], implode("\n", array_merge($closures, [
        '',
        'A shell event has to be handled by a named class, because the guard',
        'below walks out of one and a closure body carries code no import scan',
        'can follow. Give it a listener class with a named method.',
    ])));
});

it('never lets a shell event reach session or auth state', function (): void {
    $forbidden = [
        'Illuminate\\Contracts\\Session\\Session',
        'Illuminate\\Session\\',
        'Illuminate\\Support\\Facades\\Session',
        'Illuminate\\Support\\Facades\\Auth',
        'Illuminate\\Contracts\\Auth\\Guard',
        'Modules\\Core\\Public\\Services\\SessionFactory',
        'Modules\\Core\\Public\\Contracts\\CurrentUser',
    ];

    $offences = [];

    foreach (shellEventReach() as $class => $seed) {
        $file = shellEventClassFile($class);
        if ($file === null) {
            continue;
        }

        $source = shellEventStrippedSource((string) file_get_contents($file));

        foreach ($forbidden as $symbol) {
            if (str_contains($source, $symbol)) {
                $offences[] = "{$class} names {$symbol} (reached from {$seed})";
            }
        }

        if (PatternScan::matches('/(?<![\w>$])(?:session|auth)\s*\(/', $source)) {
            $offences[] = "{$class} calls the session()/auth() helper (reached from {$seed})";
        }
    }

    expect($offences)->toBe([], implode("\n", array_merge($offences, [
        '',
        'The shell posts these events from the Electron main process with no',
        'cookie, onto a route loaded without StartSession, so the session such a',
        'listener resolves belongs to no window and is discarded unsaved. There',
        'are two ways out, and both already ship here.',
        '',
        'Consume it in the page. The mobile shells deliver their native events',
        'from inside the WebView and act on them as Livewire browser events',
        "('native:Native\\Mobile\\Events\\...'), so the work happens on an ordinary",
        'request with the web group, the real session and the auth guard. Those',
        'consumers are the reference implementation and this guard does not see',
        'them, because they are not $events->listen() bindings.',
        '',
        'Or leave the fact for the window. A window that has already closed can',
        'no longer be told anything, and a file double-clicked at cold start is',
        'raised before any window exists. Modules\\Desktop\\Internal\\Native\\ShellHandoff',
        'is where such a fact waits; the window claims it on its next request.',
    ])));
});
