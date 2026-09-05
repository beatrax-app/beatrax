<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Helpers\ShellEventGraph;

// A native shell event is delivered by the shell's OWN process. NativePHP's
// notifyLaravel() posts it over axios with an X-NativePHP-Secret header and no
// cookie jar, onto a route the package loads with loadRoutesFrom() and so
// without the web group -- no StartSession, no auth guard. A PHP listener bound
// to such an event therefore cannot hold the window's session on ANY route,
// which is a property of the transport rather than a bug in one handler. Two of
// them wrote session state anyway: the app-lock listener, whose lock nobody
// could see, and the OS file-open intent, which was remembered nowhere.

it('reaches the shell-event listeners it is written to judge', function (): void {
    $reach = ShellEventGraph::reach();

    expect(ShellEventGraph::seeds())->not->toBeEmpty(
        'No listener matched. The binding syntax this scans for has changed, '.
        'and a guard that finds nothing reports nothing.',
    );
    expect($reach)->toHaveKey('Modules\\Desktop\\Internal\\Listeners\\HandleNativeOpenFile');
    expect($reach)->toHaveKey('Modules\\Desktop\\Internal\\Native\\FileOpenHandoff');
});

it('never hands a shell event to a closure, which no walk can follow', function (): void {
    $closures = [];

    foreach (ShellEventGraph::providerFiles() as $file) {
        $source = ShellEventGraph::strippedSource((string) file_get_contents($file));
        $imports = ShellEventGraph::importAliases($source);

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

    foreach (ShellEventGraph::reach() as $class => $seed) {
        $file = ShellEventGraph::classFile($class);
        if ($file === null) {
            continue;
        }

        $source = ShellEventGraph::strippedSource((string) file_get_contents($file));

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
